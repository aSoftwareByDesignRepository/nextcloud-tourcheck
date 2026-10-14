<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Config\InstanceId;
use OCA\TourCheck\Config\VendorPublicKey;
use OCA\TourCheck\Db\LicenseState;
use OCA\TourCheck\Db\LicenseStateMapper;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\License\LicenseFingerprint;
use OCA\TourCheck\Service\LicenseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Seat-gate semantics, proven against a REAL cryptographically valid license
 * state built from the golden TCK2 fixture (no crypto stubbing):
 *  - requireStationPlan gates OPERATION of enrolled devices (plan-active only)
 *  - requireStationLicense gates ENROLLMENT (plan-active AND next <= limit)
 *  - tampered fingerprint / expired / wrong-instance states behave as no license
 */
final class LicenseGateTest extends TestCase
{
	private LicenseStateMapper&MockObject $mapper;
	private InstanceId&MockObject $instanceId;
	private LicenseService $svc;
	private string|false $prevVendorKey;

	protected function setUp(): void
	{
		parent::setUp();
		// Pin the fixture's signing key — the test must not depend on the
		// runner's env. Restored in tearDown.
		$this->prevVendorKey = getenv('TCK_VENDOR_PUBLIC_KEY_B64');
		putenv('TCK_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::TEST_PUBLIC_KEY_B64);
		$this->instanceId = $this->createMock(InstanceId::class);
		$this->instanceId->method('get')->willReturn('atlas-test-instance');
	}

	protected function tearDown(): void
	{
		if ($this->prevVendorKey === false) {
			putenv('TCK_VENDOR_PUBLIC_KEY_B64');
		} else {
			putenv('TCK_VENDOR_PUBLIC_KEY_B64=' . $this->prevVendorKey);
		}
		parent::tearDown();
	}

	private function goldenState(array $overrides = []): LicenseState
	{
		$fixture = json_decode(
			(string)file_get_contents(__DIR__ . '/../../fixtures/license_tck2_golden.json'),
			true, 512, JSON_THROW_ON_ERROR
		);
		[, $payloadB64, $sigB64] = explode('.', (string)$fixture['wireKey']);
		$s = new LicenseState();
		$s->setCustomerId('test');
		$s->setValidUntil(new \DateTime('2027-12-31'));
		$s->setMobileSeats(5);
		$s->setStations(2);
		$s->setBundle(1);
		$s->setPayloadB64($payloadB64);
		$s->setSignatureB64($sigB64);
		$s->setLicenseFingerprint(LicenseFingerprint::fromWireParts($payloadB64, $sigB64));
		$s->setBoundInstanceId('atlas-test-instance');
		foreach ($overrides as $setter => $value) {
			$s->$setter($value);
		}
		return $s;
	}

	private function withState(?LicenseState $state): void
	{
		// Fresh mock per state — PHPUnit queues willReturn values, so
		// reconfiguring the same mock would keep the first state.
		$this->mapper = $this->createMock(LicenseStateMapper::class);
		$this->mapper->method('findCurrent')->willReturn($state);
		$this->svc = new LicenseService(
			$this->mapper,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->instanceId,
			$this->createMock(ILockingProvider::class),
		);
	}

	private function assertGateThrows(callable $fn, string $code): void
	{
		try {
			$fn();
			self::fail("expected DomainException {$code}");
		} catch (DomainException $e) {
			self::assertSame($code, $e->errorCode);
		}
	}

	public function testStationPlanPassesWithValidState(): void
	{
		$this->withState($this->goldenState());
		$this->svc->requireStationPlan(); // must not throw
		$this->addToAssertionCount(1);
	}

	public function testStationPlanRejectsWithoutLicense(): void
	{
		$this->withState(null);
		$this->assertGateThrows(fn () => $this->svc->requireStationPlan(), 'license_required');
	}

	public function testStationPlanRejectsTamperedFingerprint(): void
	{
		$this->withState($this->goldenState(['setLicenseFingerprint' => 'forged-fingerprint']));
		$this->assertGateThrows(fn () => $this->svc->requireStationPlan(), 'license_required');
	}

	public function testStationPlanRejectsTamperedPayload(): void
	{
		// Fingerprint recomputed but Ed25519 signature no longer matches.
		$payloadB64 = rtrim(strtr(base64_encode('{"v":2,"product":"tourcheck","stations":99}'), '+/', '-_'), '=');
		$fixture = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/license_tck2_golden.json'), true, 512, JSON_THROW_ON_ERROR);
		[, , $sigB64] = explode('.', (string)$fixture['wireKey']);
		$s = $this->goldenState([
			'setPayloadB64' => $payloadB64,
			'setLicenseFingerprint' => LicenseFingerprint::fromWireParts($payloadB64, $sigB64),
		]);
		$this->withState($s);
		$this->assertGateThrows(fn () => $this->svc->requireStationPlan(), 'license_required');
	}

	public function testStationPlanRejectsExpired(): void
	{
		$this->withState($this->goldenState(['setValidUntil' => new \DateTime('2020-01-01')]));
		$this->assertGateThrows(fn () => $this->svc->requireStationPlan(), 'license_required');
	}

	public function testStationPlanRejectsForeignInstance(): void
	{
		$this->withState($this->goldenState(['setBoundInstanceId' => 'other-instance']));
		$this->assertGateThrows(fn () => $this->svc->requireStationPlan(), 'license_required');
	}

	public function testEnrollmentAllowsUpToLimit(): void
	{
		$this->withState($this->goldenState());
		$this->svc->requireStationLicense(2); // limit is 2 — boundary inclusive
		$this->addToAssertionCount(1);
	}

	public function testEnrollmentBlocksOverLimit(): void
	{
		$this->withState($this->goldenState());
		$this->assertGateThrows(fn () => $this->svc->requireStationLicense(3), 'no_device');
	}

	public function testEnrollmentBlocksWhenPlanInactiveEvenUnderLimit(): void
	{
		// stations=0 in the license → no station plan → license_required, not no_device
		$this->withState($this->goldenState(['setStations' => 0]));
		$this->assertGateThrows(fn () => $this->svc->requireStationLicense(1), 'license_required');
	}

	public function testMobileGate(): void
	{
		$this->withState($this->goldenState());
		$this->svc->requireMobileLicense();
		$this->addToAssertionCount(1);
		$this->withState($this->goldenState(['setMobileSeats' => 0]));
		$this->assertGateThrows(fn () => $this->svc->requireMobileLicense(), 'no_seat');
	}
}
