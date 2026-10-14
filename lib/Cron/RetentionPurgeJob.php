<?php

declare(strict_types=1);

namespace OCA\TourCheck\Cron;

use OCA\TourCheck\Service\ErasureService;
use OCA\TourCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** Retention sweep — tombstone past the retention window + purged_range entry. */
class RetentionPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ErasureService $erasure,
		private readonly SettingsService $settings,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('tck.job.retentionpurge', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return; // another worker is running this sweep — next interval covers it
		}
		try {
			$this->erasure->purgeAllSites();
			$this->settings->markCronRun($this->time->getDateTime());
		} finally {
			$this->lockProvider->releaseLock('tck.job.retentionpurge', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
