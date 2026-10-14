<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\License;

use OCA\TourCheck\License\Tck2Codec;
use Test\TestCase;

/**
 * Wire-compat proof against the sbdlicenseops golden fixture
 * (license_tck2_golden.json — same bytes the generator signs).
 */
final class Tck2CodecTest extends TestCase
{
	private const FIXTURE = __DIR__ . '/../../fixtures/license_tck2_golden.json';

	private function fixture(): array
	{
		return json_decode((string)file_get_contents(self::FIXTURE), true, 16, JSON_THROW_ON_ERROR);
	}

	protected function setUp(): void
	{
		parent::setUp();
		// Point the embedded vendor key at the fixture's test keypair.
		$f = $this->fixture();
		putenv('TCK_VENDOR_PUBLIC_KEY_B64=' . $f['publicKeyB64']);
	}

	protected function tearDown(): void
	{
		putenv('TCK_VENDOR_PUBLIC_KEY_B64');
		parent::tearDown();
	}

	public function testGoldenWireVerifiesEndToEnd(): void
	{
		$f = $this->fixture();
		$parsed = Tck2Codec::parseAndVerify($f['wireKey']);
		self::assertNotNull($parsed, 'golden TCK2 wire must verify');
		self::assertSame('tourcheck', $parsed['payload']['product']);
		self::assertSame(5, $parsed['payload']['mobileSeats']);
		self::assertSame(2, $parsed['payload']['terminalDevices']);
		self::assertTrue($parsed['payload']['bundle']);
	}

	public function testCanonicalJsonMatchesGeneratorBytes(): void
	{
		$f = $this->fixture();
		$decoded = base64_decode(strtr($f['payloadB64'], '-_', '+/'));
		self::assertSame($decoded, Tck2Codec::canonicalJson($f['payload']));
	}

	public function testClassifyOkReturnsEmpty(): void
	{
		$f = $this->fixture();
		// validUntil 2027 — not expired relative to today
		self::assertSame('', Tck2Codec::classifyApplyError($f['wireKey']));
	}

	public function testRejectsWrongPrefix(): void
	{
		$f = $this->fixture();
		$wire = 'SNK2.' . $f['payloadB64'] . '.' . $f['signatureB64'];
		self::assertNull(Tck2Codec::parseAndVerify($wire));
		self::assertSame(Tck2Codec::ERROR_INVALID_FORMAT, Tck2Codec::classifyApplyError($wire));
	}

	public function testRejectsTamperedPayload(): void
	{
		$f = $this->fixture();
		// seat upgrade tamper: same signature, edited payload
		$payload = $f['payload'];
		$payload['mobileSeats'] = 9999;
		$tampered = 'TCK2.' . rtrim(strtr(base64_encode(Tck2Codec::canonicalJson($payload)), '+/', '-_'), '=') . '.' . $f['signatureB64'];
		self::assertNull(Tck2Codec::parseAndVerify($tampered));
		self::assertSame(Tck2Codec::ERROR_INVALID_SIGNATURE, Tck2Codec::classifyApplyError($tampered));
	}

	public function testRejectsForeignProductPayload(): void
	{
		// payload saying "snackcheck" must never validate as TCK2
		$payload = ['v' => 2, 'product' => 'snackcheck', 'customerId' => 'test',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-12-31',
			'mobileSeats' => 0, 'terminalDevices' => 1];
		self::assertFalse(Tck2Codec::validatePayloadFields($payload));
	}

	public function testRejectsZeroProducts(): void
	{
		$payload = ['v' => 2, 'product' => 'tourcheck', 'customerId' => 'test',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-12-31',
			'mobileSeats' => 0, 'terminalDevices' => 0];
		self::assertFalse(Tck2Codec::validatePayloadFields($payload));
	}

	public function testRejectsInstanceIdOnWire(): void
	{
		$payload = ['v' => 2, 'product' => 'tourcheck', 'customerId' => 'test',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-12-31',
			'mobileSeats' => 1, 'terminalDevices' => 0, 'instanceId' => 'abc'];
		self::assertFalse(Tck2Codec::validatePayloadFields($payload));
	}

	public function testExpiryBoundary(): void
	{
		$past = ['validUntil' => '2020-01-01'];
		$future = ['validUntil' => '2999-01-01'];
		self::assertTrue(Tck2Codec::isExpired($past, new \DateTimeImmutable('2026-10-12')));
		self::assertFalse(Tck2Codec::isExpired($future, new \DateTimeImmutable('2026-10-12')));
		self::assertFalse(Tck2Codec::isExpired(['validUntil' => '2026-10-12'], new \DateTimeImmutable('2026-10-12')));
	}
}
