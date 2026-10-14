<?php

declare(strict_types=1);

namespace OCA\TourCheck\Cron;

use OCA\TourCheck\Service\ManDownService;
use OCA\TourCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Server-side man-down safety net: fires for watching workers whose
 * last_activity_at exceeded the site's inactivity window — covers the
 * dead/offline-phone case the device-side pre-alarm cannot reach.
 */
class ManDownSweepJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ManDownService $manDown,
		private readonly SettingsService $settings,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('tck.job.mandown', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return;
		}
		try {
			$this->manDown->sweep();
			$this->settings->markCronRun($this->time->getDateTime());
		} finally {
			$this->lockProvider->releaseLock('tck.job.mandown', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
