<?php

declare(strict_types=1);

namespace OCA\TourCheck\Cron;

use OCA\TourCheck\Service\SchedulingService;
use OCA\TourCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** Occurrence generation sweep — idempotent by unique key (NN-02). */
class GenerateOccurrencesJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly SchedulingService $scheduling,
		private readonly SettingsService $settings,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('tck.job.generateoccurrences', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return; // another worker is running this sweep — next interval covers it
		}
		try {
			$this->scheduling->generateAll();
			$this->settings->markCronRun($this->time->getDateTime());
		} finally {
			$this->lockProvider->releaseLock('tck.job.generateoccurrences', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
