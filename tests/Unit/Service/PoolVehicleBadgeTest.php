<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Integration\PoolVehicleService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * NH-15b / F11(b) consumer side: the badge is fail-closed — every
 * degraded state (no uid, MC disabled, facade absent/old, facade throw)
 * yields null, never an error. The happy path is covered by the
 * facade's own tests in mobilitycheck + the Docker integration surface.
 */
class PoolVehicleBadgeTest extends TestCase
{
	private function svc(bool $mcEnabled): PoolVehicleService
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($mcEnabled);
		return new PoolVehicleService($apps, $this->createMock(LoggerInterface::class));
	}

	public function testEmptyUidNeverProbes(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->never())->method('isEnabledForUser');
		$svc = new PoolVehicleService($apps, $this->createMock(LoggerInterface::class));
		$this->assertNull($svc->badgeFor('', new \DateTimeImmutable()));
	}

	public function testMcDisabledYieldsNull(): void
	{
		$this->assertNull($this->svc(false)->badgeFor('uid:worker', new \DateTimeImmutable()));
		$this->assertFalse($this->svc(false)->isAvailable());
	}

	public function testFacadeProbeIsBoolean(): void
	{
		// With MC enabled: available only when the versioned facade class
		// resolves (true in Docker, false in host runs without the app).
		$svc = $this->svc(true);
		$this->assertSame(
			class_exists(\OCA\MobilityCheck\Public\TodayBookingReadFacade::class),
			$svc->isAvailable()
		);
	}
}
