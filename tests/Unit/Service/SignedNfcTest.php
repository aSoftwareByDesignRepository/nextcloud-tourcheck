<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\CheckpointToken;
use OCA\TourCheck\Db\CheckpointTokenMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\TokenAnomalyService;
use OCA\TourCheck\Service\TokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * NH-11 signed NFC — tcks://v1.<token>.<ed25519(sig)>.
 * Covers: mint, happy-path resolve, cloned tag UID, forged signature,
 * missing UID, signature wrapped around a non-nfc token, revoked inner token.
 */
final class SignedNfcTest extends TestCase
{
	private CheckpointTokenMapper&MockObject $tokenMapper;
	private CheckpointMapper&MockObject $cpMapper;
	private ITimeFactory&MockObject $time;
	private TokenAnomalyService&MockObject $anomaly;
	private SettingsService&MockObject $settings;
	private TokenService $svc;

	/** In-memory token store so issueSigned + resolve share state. */
	private array $tokenRows = [];

	protected function setUp(): void
	{
		$this->tokenMapper = $this->createMock(CheckpointTokenMapper::class);
		$this->cpMapper = $this->createMock(CheckpointMapper::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->anomaly = $this->createMock(TokenAnomalyService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getNfcSignSecret')
			->willReturn(str_repeat('ab', SODIUM_CRYPTO_SIGN_SEEDBYTES));
		$this->time->method('getDateTime')->willReturn(new \DateTime());
		$this->tokenRows = [];

		$this->tokenMapper->method('insert')->willReturnCallback(function (CheckpointToken $t) {
			$this->tokenRows[$t->getTokenHash()] = $t;
			return $t;
		});
		$this->tokenMapper->method('findActiveByHash')->willReturnCallback(
			fn(string $h) => isset($this->tokenRows[$h]) && $this->tokenRows[$h]->getRevokedAt() === null
				? $this->tokenRows[$h] : null
		);
		$this->tokenMapper->method('findAnyByHash')->willReturnCallback(
			fn(string $h) => $this->tokenRows[$h] ?? null
		);

		$this->svc = new TokenService(
			$this->tokenMapper, $this->cpMapper, $this->time, $this->anomaly, $this->settings,
			$this->createMock(\OCP\IDBConnection::class)
		);
	}

	private function checkpoint(int $id = 7, int $site = 5): Checkpoint
	{
		$cp = new Checkpoint();
		$cp->setId($id);
		$cp->setSiteId($site);
		$cp->setActive(1);
		return $cp;
	}

	public function testIssueSignedProducesResolvablePayload(): void
	{
		$cp = $this->checkpoint();
		$this->cpMapper->method('find')->with(7)->willReturn($cp);

		$payload = $this->svc->issueSigned($cp, '04A2B3C4');
		$this->assertStringStartsWith('tcks://v1.', $payload);

		[$resolvedCp, $token] = $this->svc->resolve($payload, '10.0.0.9', '04a2b3c4');
		$this->assertSame(7, $resolvedCp->getId());
		$this->assertSame('nfc', $token->getKind());
	}

	public function testClonedTagUidIsRejected(): void
	{
		$cp = $this->checkpoint();
		$payload = $this->svc->issueSigned($cp, '04A2B3C4');
		// Signature is valid math but bound to a DIFFERENT physical UID.
		$this->cpMapper->method('find')->willReturn($cp);
		$this->anomaly->expects($this->once())->method('recordSiteHit')
			->with(5, 'nfc_signature_fail');
		$this->expectException(NotFoundException::class);
		$this->svc->resolve($payload, '10.0.0.9', 'DEADBEEF');
	}

	public function testForgedSignatureIsRejected(): void
	{
		$cp = $this->checkpoint();
		$payload = $this->svc->issueSigned($cp, '04A2B3C4');
		// Re-sign the same token with a DIFFERENT secret (attacker minted it).
		$badSeed = str_repeat('cd', SODIUM_CRYPTO_SIGN_SEEDBYTES);
		$badKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(sodium_hex2bin($badSeed)));
		preg_match('/^tcks:\/\/v1\.([A-Za-z0-9_-]+)\./', $payload, $m);
		$raw = $m[1];
		$forged = 'tcks://v1.' . $raw . '.' . rtrim(strtr(base64_encode(
			sodium_crypto_sign_detached('tcks:v1:' . hash('sha256', $raw) . ':04a2b3c4', $badKey)
		), '+/', '-_'), '=');
		$this->expectException(NotFoundException::class);
		$this->svc->resolve($forged, '10.0.0.9', '04a2b3c4');
	}

	public function testMissingTagUidIsRejected(): void
	{
		$payload = $this->svc->issueSigned($this->checkpoint(), '04A2B3C4');
		$this->expectException(NotFoundException::class);
		$this->svc->resolve($payload, '10.0.0.9', null); // scanned without NFC hardware read
	}

	public function testMalformedSignedPayloadIsRejected(): void
	{
		$this->expectException(NotFoundException::class);
		$this->svc->resolve('tcks://v1.tooshort.notasig', '10.0.0.9', '04a2b3c4');
	}

	public function testSignatureCannotUpgradeQrTokenToNfc(): void
	{
		// Attacker signs a payload around a QR-kind token they possess.
		$qr = new CheckpointToken();
		$qr->setCheckpointId(7);
		$qr->setKind('qr');
		$qr->setTokenHash('unused');
		$raw = 'tck_' . str_repeat('a', 32);
		$qr->setTokenHash(hash('sha256', $raw));
		$this->tokenRows[$qr->getTokenHash()] = $qr;
		$this->cpMapper->method('find')->willReturn($this->checkpoint());

		$seed = sodium_hex2bin(str_repeat('ab', SODIUM_CRYPTO_SIGN_SEEDBYTES));
		$key = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
		$sig = sodium_crypto_sign_detached('tcks:v1:' . hash('sha256', $raw) . ':04a2b3c4', $key);
		$payload = 'tcks://v1.' . $raw . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

		$this->anomaly->expects($this->once())->method('recordSiteHit')
			->with(5, 'sig_on_nonnfc');
		$this->expectException(NotFoundException::class);
		$this->svc->resolve($payload, '10.0.0.9', '04a2b3c4');
	}

	public function testRevokedInnerTokenIsRejected(): void
	{
		$cp = $this->checkpoint();
		$this->cpMapper->method('find')->willReturn($cp);
		$payload = $this->svc->issueSigned($cp, '04A2B3C4');
		// Revoke the inner token row — signature still verifies but the row is dead.
		preg_match('/^tcks:\/\/v1\.([A-Za-z0-9_-]+)\./', $payload, $m);
		$this->tokenRows[hash('sha256', $m[1])]->setRevokedAt(new \DateTime());
		$this->expectException(NotFoundException::class);
		$this->svc->resolve($payload, '10.0.0.9', '04a2b3c4');
	}
}
