<?php

declare(strict_types=1);

namespace OCA\TourCheck\Cron;

use OCA\TourCheck\Service\PositionService;
use OCA\TourCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** Position breadcrumbs: hard-delete past expires_at (default 24 h). */
class PositionPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly PositionService $positions,
		private readonly SettingsService $settings,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('tck.job.positionpurge', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return; // another worker is running this sweep — next interval covers it
		}
		try {
			$this->positions->purgeExpired();
			$this->settings->markCronRun($this->time->getDateTime());
		} finally {
			$this->lockProvider->releaseLock('tck.job.positionpurge', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
