<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\CheckpointToken;
use OCA\TourCheck\Db\CheckpointTokenMapper;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Checkpoint tokens (QR/NFC). Storage: sha256 of the raw token only — a DB
 * dump cannot mint scans. Raw value exists once at generation for printing.
 */
class TokenService
{
	public const URL_PREFIX = 'tckt://';
	/** NH-11 signed NFC tags: tcks://v1.<rawToken>.<sig> — Ed25519 over sha256(token)‖tagUid. */
	public const SIGNED_PREFIX = 'tcks://';

	public function __construct(
		private readonly CheckpointTokenMapper $tokens,
		private readonly CheckpointMapper $checkpoints,
		private readonly ITimeFactory $timeFactory,
		private readonly TokenAnomalyService $anomaly,
		private readonly SettingsService $settings,
		private readonly \OCP\IDBConnection $db,
	) {
	}

	public static function hash(string $rawToken): string
	{
		return hash('sha256', $rawToken);
	}

	public function issue(Checkpoint $checkpoint, string $kind): string
	{
		$raw = 'tck_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
		$t = new CheckpointToken();
		$t->setCheckpointId((int)$checkpoint->getId());
		$t->setKind($kind);
		$t->setTokenHash(self::hash($raw));
		$t->setCreatedAt($this->timeFactory->getDateTime());
		$this->tokens->insert($t);
		return $raw;
	}

	/**
	 * Rotate: revoke every live token on the checkpoint, issue a fresh one.
	 * Returns the new raw token (shown once to print/re-burn).
	 */
	public function rotate(Checkpoint $checkpoint, string $kind): string
	{
		// Row lock on the checkpoint serializes concurrent rotates —
		// without it two rotates can each revoke the pre-read active set and
		// issue, leaving TWO live tokens (the un-printed one is leaked-valid).
		$this->db->beginTransaction();
		try {
			$this->checkpoints->findForUpdate((int)$checkpoint->getId());
			$active = $this->tokens->findActiveForCheckpoint((int)$checkpoint->getId());
			if ($active !== [] && in_array($active[0]->getKind(), ['qr', 'nfc', 'manual'], true)) {
				// Kind is a property of the checkpoint's physical token type —
				// rotation must not silently downgrade nfc/manual back to qr.
				$kind = (string)$active[0]->getKind();
			}
			foreach ($active as $t) {
				$t->setRevokedAt($this->timeFactory->getDateTime());
				$this->tokens->update($t);
			}
			$raw = $this->issue($checkpoint, $kind);
			$this->db->commit();
			return $raw;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** @return array<int,string> checkpointId → active token kind */
	public function activeKindMap(array $checkpointIds): array
	{
		$map = [];
		foreach ($this->tokens->findActiveForCheckpoints($checkpointIds) as $t) {
			$map[(int)$t->getCheckpointId()] = (string)$t->getKind();
		}
		return $map;
	}

	/**
	 * Resolve a scanned raw token → [checkpoint, token]. Only active tokens on
	 * active checkpoints resolve. Failure is uniformly NotFound — invalid and
	 * revoked look identical (no oracle); rate limiting happens in callers.
	 *
	 * GA-3: every failure feeds TokenAnomalyService — unattributable misses
	 * count toward the global/per-IP burst, revoked/inactive rows additionally
	 * count toward their site's anomaly counter.
	 * @return array{0: Checkpoint, 1: CheckpointToken}
	 */
	public function resolve(string $rawToken, ?string $ip = null, ?string $tagUid = null): array
	{
		$rawToken = trim($rawToken);
		$wasSigned = false;
		if (str_starts_with($rawToken, self::SIGNED_PREFIX)) {
			// NH-11: the signature binds payload → physical tag UID. Verified
			// BEFORE any DB read so forged/copied payloads can't probe.
			$rawToken = $this->unwrapSigned($rawToken, $tagUid, $ip);
			$wasSigned = true;
		} elseif (str_starts_with($rawToken, self::URL_PREFIX)) {
			$rawToken = substr($rawToken, strlen(self::URL_PREFIX));
		}
		if (strlen($rawToken) < 20 || strlen($rawToken) > 128) {
			$this->anomaly->recordInvalid($ip);
			throw new NotFoundException();
		}
		$token = $this->tokens->findActiveByHash(self::hash($rawToken));
		if ($token === null) {
			// One extra read on the failure path: a KNOWN-but-dead token
			// (rotated/revoked, or checkpoint archived) is site-attributable.
			$dead = $this->tokens->findAnyByHash(self::hash($rawToken));
			if ($dead !== null) {
				$deadCp = $this->checkpoints->find((int)$dead->getCheckpointId());
				if ($deadCp !== null) {
					$this->anomaly->recordSiteHit(
						(int)$deadCp->getSiteId(),
						$dead->getRevokedAt() !== null ? 'rotated_token' : 'inactive_checkpoint'
					);
				}
			}
			$this->anomaly->recordInvalid($ip);
			throw new NotFoundException();
		}
		$cp = $this->checkpoints->find((int)$token->getCheckpointId());
		if ($cp === null || (int)$cp->getActive() !== 1) {
			if ($cp !== null) {
				$this->anomaly->recordSiteHit((int)$cp->getSiteId(), 'inactive_checkpoint');
			}
			$this->anomaly->recordInvalid($ip);
			throw new NotFoundException();
		}
		if ($wasSigned && $token->getKind() !== 'nfc') {
			// A signature must never upgrade a QR token to nfc-grade evidence.
			$this->anomaly->recordSiteHit((int)$cp->getSiteId(), 'sig_on_nonnfc');
			$this->anomaly->recordInvalid($ip);
			throw new NotFoundException();
		}
		return [$cp, $token];
	}

	public function qrPayload(string $rawToken): string
	{
		return self::URL_PREFIX . $rawToken;
	}

	/**
	 * NH-11: mint a signed-NFC payload for a checkpoint + physical tag UID.
	 * The tag carries tcks://v1.<rawToken>.<sig>; each tag gets its own token
	 * row so a lost tag is revoked alone (rotate() would kill all siblings).
	 */
	public function issueSigned(Checkpoint $checkpoint, string $tagUid): string
	{
		$tagUid = strtolower(preg_replace('/[^a-f0-9]/i', '', $tagUid));
		if (strlen($tagUid) < 4 || strlen($tagUid) > 40) {
			throw new DomainException('validation', 'Tag UID must be 4–40 hex chars.', 422);
		}
		$raw = 'tck_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
		$t = new CheckpointToken();
		$t->setCheckpointId((int)$checkpoint->getId());
		$t->setKind('nfc');
		$t->setTokenHash(self::hash($raw));
		$t->setCreatedAt($this->timeFactory->getDateTime());
		$this->tokens->insert($t);
		$sig = sodium_crypto_sign_detached(
			'tcks:v1:' . self::hash($raw) . ':' . $tagUid,
			sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(sodium_hex2bin($this->nfcSecret())))
		);
		return self::SIGNED_PREFIX . 'v1.' . $raw . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
	}

	/**
	 * Unwrap a signed payload → inner raw token. Any failure (bad shape,
	 * missing tag UID, invalid signature, wrong token kind) is the uniform
	 * NotFound — a forged tag must be indistinguishable from a random string.
	 */
	private function unwrapSigned(string $payload, ?string $tagUid, ?string $ip): string
	{
		$fail = function () use ($ip): void {
			$this->anomaly->recordInvalid($ip);
			throw new NotFoundException();
		};
		if (!preg_match('/^tcks:\/\/v1\.([A-Za-z0-9_-]{20,128})\.([A-Za-z0-9_-]{40,120})$/', $payload, $m)) {
			$fail();
		}
		[, $raw, $sigB64] = $m;
		$tagUid = strtolower(preg_replace('/[^a-f0-9]/i', '', (string)$tagUid));
		if ($tagUid === '') {
			$fail(); // a signed tag can never resolve without its hardware UID
		}
		$sig = base64_decode(strtr($sigB64, '-_', '+/'), true);
		if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
			$fail();
		}
		$ok = sodium_crypto_sign_verify_detached(
			$sig,
			'tcks:v1:' . self::hash($raw) . ':' . $tagUid,
			sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(sodium_hex2bin($this->nfcSecret())))
		);
		if (!$ok) {
			// Cloned tag (payload copied to a different UID) or forged — the
			// payload DOES parse, so the inner token may identify a site for
			// the per-site anomaly counter, but only when the row exists.
			$dead = $this->tokens->findAnyByHash(self::hash($raw));
			if ($dead !== null) {
				$cp = $this->checkpoints->find((int)$dead->getCheckpointId());
				if ($cp !== null) {
					$this->anomaly->recordSiteHit((int)$cp->getSiteId(), 'nfc_signature_fail');
				}
			}
			$fail();
		}
		return $raw;
	}

	private function nfcSecret(): string
	{
		return $this->settings->getNfcSignSecret();
	}

	public function assertKind(CheckpointToken $token, string $kind): void
	{
		if ($token->getKind() !== $kind) {
			// NFC claim on a QR token (or vice versa) — uniform reject, but the
			// caller should journal a method_lie signal when the token exists.
			throw new DomainException('method_mismatch', 'Token type does not match this capture method.', 422);
		}
	}

	/** Revoke every live token for a checkpoint (archive path). */
	public function revokeAllForCheckpoint(int $checkpointId): void
	{
		$now = $this->timeFactory->getDateTime();
		foreach ($this->tokens->findActiveForCheckpoint($checkpointId) as $t) {
			$t->setRevokedAt($now);
			$this->tokens->update($t);
		}
	}
}
