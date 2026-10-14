<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Photo evidence pipeline. Two rules that are non-negotiable:
 *  1. Server-side re-encode + EXIF strip — a "no GPS trails" promise that
 *     stores camera EXIF is a lie (found in the architecture review).
 *  2. Decompression-bomb cap: reject images that decode beyond ~50 MP.
 * Photos land in appdata (not user Files) — evidence is append-only.
 */
class PhotoService
{
	private const MAX_BYTES = 10 * 1024 * 1024;
	private const MAX_PIXELS = 50_000_000;
	private const MAX_AUDIO_BYTES = 5 * 1024 * 1024;
	/** Voice-note allowlist — finfo probed, extension never trusted. */
	private const AUDIO_MIME_EXT = [
		'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/m4a' => 'm4a',
		'audio/mpeg' => 'mp3', 'audio/webm' => 'webm', 'video/webm' => 'webm',
		'audio/ogg' => 'ogg', 'audio/x-caf' => 'caf', 'audio/aac' => 'aac',
	];

	public function __construct(
		private readonly IAppData $appData,
		private readonly LoggerInterface $logger,
	) {
	}

	private function folder(int $siteId): ISimpleFolder
	{
		try {
			$root = $this->appData->getFolder('photos');
		} catch (\OCP\Files\NotFoundException) {
			$root = $this->appData->newFolder('photos');
		}
		$dir = 'site' . $siteId;
		try {
			return $root->getFolder($dir);
		} catch (\OCP\Files\NotFoundException) {
			return $root->newFolder($dir);
		}
	}

	/**
	 * Store an uploaded photo: validate, decode, strip metadata, re-encode
	 * JPEG q85. Returns the numeric file id used as photo_fileid.
	 */
	public function store(int $siteId, string $tmpPath): int
	{
		if (!is_file($tmpPath) || filesize($tmpPath) === 0) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Empty upload.', ['photo' => 'empty']);
		}
		if (filesize($tmpPath) > self::MAX_BYTES) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Photo too large (max 10 MB).', ['photo' => 'too_large']);
		}
		$info = @getimagesize($tmpPath);
		if ($info === false || !isset($info[0], $info[1])) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Not an image.', ['photo' => 'invalid']);
		}
		if (($info[0] * $info[1]) > self::MAX_PIXELS) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Image too large (decompression cap).', ['photo' => 'too_large']);
		}
		$src = match ($info[2] ?? 0) {
			IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
			IMAGETYPE_PNG => @imagecreatefrompng($tmpPath),
			IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
			default => false,
		};
		if ($src === false || $src === null) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Unsupported image type (JPEG/PNG/WebP).', ['photo' => 'invalid']);
		}
		// Re-encode — this alone strips every EXIF/XMP byte.
		ob_start();
		imagejpeg($src, null, 85);
		$clean = ob_get_clean();
		if ($clean === false || $clean === '') {
			throw new DomainException('photo_encode_failed', 'Could not process the photo.', 500);
		}
		do {
			$fileId = random_int(1_000_000_000, PHP_INT_MAX - 1);
		} while ($this->read($siteId, $fileId) !== null);
		$this->folder($siteId)->newFile($fileId . '.jpg', $clean);
		return $fileId;
	}

	/** Does this photo belong to the site? (cross-site reference check) */
	public function exists(int $siteId, int $fileId): bool
	{
		return $this->read($siteId, $fileId) !== null;
	}

	public function read(int $siteId, int $fileId): ?ISimpleFile
	{
		try {
			return $this->folder($siteId)->getFile($fileId . '.jpg');
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * NH-15c voice note: audio blobs get the same append-only treatment as
	 * photos — MIME sniffed (never the client claim), size-capped, stored
	 * under the same site folder with a distinct extension.
	 */
	public function storeAudio(int $siteId, string $tmpPath): int
	{
		if (!is_file($tmpPath) || filesize($tmpPath) === 0) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Empty upload.', ['audio' => 'empty']);
		}
		if (filesize($tmpPath) > self::MAX_AUDIO_BYTES) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Voice note too large (max 5 MB).', ['audio' => 'too_large']);
		}
		$fi = new \finfo(FILEINFO_MIME_TYPE);
		$mime = (string)$fi->file($tmpPath);
		$ext = self::AUDIO_MIME_EXT[$mime] ?? null;
		if ($ext === null) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Not a supported voice recording.', ['audio' => 'invalid']);
		}
		do {
			$fileId = random_int(1_000_000_000, PHP_INT_MAX - 1);
		} while ($this->readAudio($siteId, $fileId) !== null || $this->read($siteId, $fileId) !== null);
		$this->folder($siteId)->newFile($fileId . '.' . $ext, (string)file_get_contents($tmpPath));
		return $fileId;
	}

	public function readAudio(int $siteId, int $fileId): ?ISimpleFile
	{
		foreach (self::AUDIO_MIME_EXT as $ext) {
			try {
				return $this->folder($siteId)->getFile($fileId . '.' . $ext);
			} catch (\Throwable) {
			}
		}
		return null;
	}

	/** Cross-site reference check for audio (same rule as photos). */
	public function audioExists(int $siteId, int $fileId): bool
	{
		return $this->readAudio($siteId, $fileId) !== null;
	}

	public function delete(int $siteId, int $fileId): void
	{
		foreach ([$this->read($siteId, $fileId), $this->readAudio($siteId, $fileId)] as $f) {
			if ($f !== null) {
				// best-effort: a locked/missing file must not abort purge.
				try {
					$f->delete();
				} catch (\Throwable $e) {
					$this->logger->warning('tourcheck: evidence file delete failed', ['id' => $fileId, 'e' => $e->getMessage()]);
				}
			}
		}
	}
}
