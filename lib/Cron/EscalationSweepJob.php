<?php

declare(strict_types=1);

namespace OCA\TourCheck\Cron;

use OCA\TourCheck\Service\AlertService;
use OCA\TourCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Alert escalation sweep (NN-04): every minute, claims due escalation legs
 * transactionally, releases locks, then performs NC-notification/email/
 * webhook I/O outside any transaction. Overlapping workers lose the claim
 * on the unique (alert, leg) key — legs can never double-fire.
 */
class EscalationSweepJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly AlertService $alerts,
		private readonly SettingsService $settings,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('tck.job.escalation', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return;
		}
		try {
			$this->alerts->sweepEscalations();
			$this->settings->markCronRun($this->time->getDateTime());
		} finally {
			$this->lockProvider->releaseLock('tck.job.escalation', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
