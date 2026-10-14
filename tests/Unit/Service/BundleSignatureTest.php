<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Service\SettingsService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * MH-16: the claim-bundle manifest must be cryptographically SIGNED — a
 * per-site Ed25519 keypair derived from the config-secret, deterministic,
 * domain-separated from the NFC key, and per-site distinct.
 */
class BundleSignatureTest extends TestCase
{
	private const SEED = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	private SettingsService $settings;

	protected function setUp(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn(self::SEED);
		$this->settings = new SettingsService($config);
	}

	public function testKeypairIsDeterministicPerSite(): void
	{
		$a = $this->settings->bundleSignKeypair(7);
		$b = $this->settings->bundleSignKeypair(7);
		self::assertSame(sodium_bin2hex($a['pk']), sodium_bin2hex($b['pk']));
	}

	public function testKeypairDiffersAcrossSites(): void
	{
		$a = $this->settings->bundleSignKeypair(7);
		$b = $this->settings->bundleSignKeypair(8);
		self::assertNotSame(sodium_bin2hex($a['pk']), sodium_bin2hex($b['pk']));
	}

	public function testKeypairDiffersFromNfcKey(): void
	{
		// Domain separation: the bundle key must NOT be the raw NFC keypair —
		// signing domains stay cryptographically distinct.
		$nfcKp = sodium_crypto_sign_seed_keypair(sodium_hex2bin(self::SEED));
		$bundle = $this->settings->bundleSignKeypair(7);
		self::assertNotSame(
			sodium_bin2hex(sodium_crypto_sign_publickey($nfcKp)),
			sodium_bin2hex($bundle['pk']),
		);
	}

	public function testSignVerifyRoundtripAndTamperDetection(): void
	{
		$kp = $this->settings->bundleSignKeypair(7);
		$manifest = json_encode(['files' => [['name' => 'report.pdf', 'sha256' => 'abc']], 'anchoring' => 'anchored']);
		self::assertIsString($manifest);
		$sig = sodium_crypto_sign_detached($manifest, $kp['sk']);
		self::assertTrue(sodium_crypto_sign_verify_detached($sig, $manifest, $kp['pk']));

		// A re-hashed doctored manifest fails the signature — the whole point.
		$doctored = str_replace('anchored', 'unanchor', $manifest);
		self::assertFalse(sodium_crypto_sign_verify_detached($sig, $doctored, $kp['pk']));
	}
}
