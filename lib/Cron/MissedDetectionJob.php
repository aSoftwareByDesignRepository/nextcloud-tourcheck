<?php

declare(strict_types=1);

namespace OCA\TourCheck\Cron;

use OCA\TourCheck\Service\MissedService;
use OCA\TourCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class MissedDetectionJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly MissedService $missed,
		private readonly SettingsService $settings,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('tck.job.misseddetection', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return; // another worker is running this sweep — next interval covers it
		}
		try {
			$this->missed->sweep();
			$this->settings->markCronRun($this->time->getDateTime());
		} finally {
			$this->lockProvider->releaseLock('tck.job.misseddetection', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
