<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/** Dashboard aggregates — one query set, no N+1. */
class DashboardService
{
	public function __construct(
		private readonly SiteService $sites,
		private readonly AccessControlService $acl,
		private readonly RoundService $rounds,
		private readonly SettingsService $settings,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/** @return array<string,mixed> */
	public function home(): array
	{
		$uid = $this->acl->assertAppAccess();
		$sites = $this->sites->listForCurrentUser();
		$siteIds = array_map(static fn ($s) => (int)$s->getId(), $sites);
		$unrestricted = $this->acl->visibleSiteIds($uid) === [] && ($this->acl->isAppAdmin($uid) || $this->acl->canPlan($uid));

		$open = $this->rounds->todayFor($siteIds, $unrestricted);
		$now = $this->timeFactory->getDateTime();
		$active = $missed = $upcoming = 0;
		foreach ($open as $r) {
			match ($r->getStatus()) {
				Constants::ROUND_ACTIVE => $active++,
				Constants::ROUND_MISSED => $missed++,
				default => $upcoming++,
			};
		}

		$lastCron = $this->settings->getLastCronRun();
		$cronStale = $lastCron === null || $lastCron->getTimestamp() < $now->getTimestamp() - 2 * 3600;

		return [
			'sites' => array_map(static fn ($s) => ['id' => (int)$s->getId(), 'name' => $s->getName()], $sites),
			'rounds' => [
				'active' => $active,
				'missed' => $missed,
				'upcoming' => $upcoming,
				'next' => array_map(static fn ($r) => [
					'id' => (int)$r->getId(),
					'site_id' => (int)$r->getSiteId(),
					'start' => $r->getScheduledStart()->format('c'),
					'window_end' => $r->getWindowEnd()->format('c'),
					'status' => $r->getStatus(),
				], array_slice($open, 0, 10)),
			],
			'cron_stale' => $cronStale,
			'can_plan' => $this->acl->canPlan($uid),
			'can_admin' => $this->acl->isAppAdmin($uid),
		];
	}
}
