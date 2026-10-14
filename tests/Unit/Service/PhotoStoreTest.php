<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\PhotoService;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Photo evidence pipeline: EXIF/XMP strip via re-encode, decompression-bomb
 * cap, size cap, non-image rejection. Real GD codec — a fixture JPEG gets
 * an APP1/Exif segment spliced in and must come out clean.
 */
final class PhotoStoreTest extends TestCase
{
	private string $tmp;

	protected function setUp(): void
	{
		parent::setUp();
		if (!function_exists('imagecreatetruecolor')) {
			self::markTestSkipped('GD not available');
		}
		$this->tmp = tempnam(sys_get_temp_dir(), 'tck_photo_test_');
	}

	protected function tearDown(): void
	{
		@unlink($this->tmp);
		parent::tearDown();
	}

	/** @return array{1:PhotoService, 2:\stdClass} */
	private function service(): array
	{
		$box = new \stdClass();
		$box->files = [];
		$file = $this->createMock(ISimpleFile::class);

		$site = $this->createMock(ISimpleFolder::class);
		$site->method('newFile')->willReturnCallback(static function (string $name, $content) use ($box, $file) {
			$box->files[$name] = $content;
			return $file;
		});
		$site->method('getFile')->willThrowException(new \OCP\Files\NotFoundException());

		$root = $this->createMock(ISimpleFolder::class);
		$root->method('getFolder')->willReturn($site);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($root);

		return [$site, new PhotoService($appData, $this->createMock(LoggerInterface::class)), $box];
	}

	private function writeJpeg(int $w = 120, int $h = 80, bool $withExif = false): void
	{
		$img = imagecreatetruecolor($w, $h);
		ob_start();
		imagejpeg($img, null, 90);
		$jpeg = ob_get_clean();
		if ($withExif) {
			// Splice an APP1 Exif segment right after SOI — the re-encode
			// must strip it (NN-14: no GPS/device trail inside evidence).
			$payload = "Exif\0\0GPS-here" . random_bytes(16);
			$app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
			$jpeg = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
		}
		file_put_contents($this->tmp, $jpeg);
	}

	public function testStoresReencodedJpeg(): void
	{
		$this->writeJpeg();
		[, $svc, $box] = $this->service();
		$id = $svc->store(7, $this->tmp);
		self::assertGreaterThan(0, $id);
		self::assertCount(1, $box->files);
		$bytes = (string)array_values($box->files)[0];
		self::assertStringStartsWith("\xFF\xD8", $bytes);
		$info = getimagesizefromstring($bytes);
		self::assertSame([120, 80], [$info[0], $info[1]]);
	}

	public function testExifStrippedOnReencode(): void
	{
		$this->writeJpeg(withExif: true);
		self::assertStringContainsString('Exif', (string)file_get_contents($this->tmp));
		[, $svc, $box] = $this->service();
		$svc->store(7, $this->tmp);
		$bytes = (string)array_values($box->files)[0];
		self::assertStringNotContainsString('Exif', $bytes);
		self::assertStringNotContainsString('GPS-here', $bytes);
	}

	public function testOversizeRejected(): void
	{
		file_put_contents($this->tmp, str_repeat("\0", 10 * 1024 * 1024 + 1));
		[, $svc] = $this->service();
		try {
			$svc->store(7, $this->tmp);
			self::fail('expected rejection');
		} catch (ValidationException $e) {
			// Must be the byte cap specifically — a removed cap would surface
			// 'invalid' at decode instead, and this assert would fail.
			self::assertSame('too_large', $e->fields['photo'] ?? null);
		}
	}

	public function testDecompressionBombRejected(): void
	{
		// A 9000×9000 PNG header (~81 MP) in a few bytes — getimagesize
		// reads IHDR without decoding the body.
		$ihdr = pack('N', 13) . 'IHDR' . pack('NN', 9000, 9000) . "\x08\x06\x00\x00\x00";
		$ihdr .= pack('N', crc32(substr($ihdr, 4)));
		$png = "\x89PNG\r\n\x1a\n" . $ihdr;
		file_put_contents($this->tmp, $png);
		[, $svc] = $this->service();
		try {
			$svc->store(7, $this->tmp);
			self::fail('expected rejection');
		} catch (ValidationException $e) {
			// The pixel cap must fire — not a later 'invalid' decode failure.
			self::assertSame('too_large', $e->fields['photo'] ?? null);
		}
	}

	public function testNonImageRejected(): void
	{
		file_put_contents($this->tmp, 'this is not an image');
		[, $svc] = $this->service();
		$this->expectException(ValidationException::class);
		$svc->store(7, $this->tmp);
	}
}
