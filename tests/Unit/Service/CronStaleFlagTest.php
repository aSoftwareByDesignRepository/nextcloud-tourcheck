<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\DashboardService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * §474 cron_stale: the dashboard flag must flip on a missing OR old
 * last-cron marker and clear on a fresh one — a dead cron must never
 * fail silently.
 */
final class CronStaleFlagTest extends TestCase
{
	private SettingsService&MockObject $settings;
	private DashboardService $svc;
	private \DateTimeImmutable $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTimeImmutable('2026-11-04 12:00:00');
		$this->settings = $this->createMock(SettingsService::class);
		$sites = $this->createMock(SiteService::class);
		$acl = $this->createMock(AccessControlService::class);
		$rounds = $this->createMock(RoundService::class);
		$db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(\DateTime::createFromImmutable($this->now));
		$acl->method('assertAppAccess')->willReturn('u1');
		$acl->method('visibleSiteIds')->willReturn(['u1' => [1]]);
		$sites->method('listForCurrentUser')->willReturn([]);
		$rounds->method('todayFor')->willReturn([]);
		$this->svc = new DashboardService($sites, $acl, $rounds, $this->settings, $db, $time);
	}

	public function testNeverRunIsStale(): void
	{
		$this->settings->method('getLastCronRun')->willReturn(null);
		self::assertTrue($this->svc->home()['cron_stale']);
	}

	public function testOldRunIsStale(): void
	{
		$this->settings->method('getLastCronRun')
			->willReturn($this->now->sub(new \DateInterval('PT3H')));
		self::assertTrue($this->svc->home()['cron_stale']);
	}

	public function testFreshRunIsNotStale(): void
	{
		$this->settings->method('getLastCronRun')
			->willReturn($this->now->sub(new \DateInterval('PT5M')));
		self::assertFalse($this->svc->home()['cron_stale']);
	}
}
