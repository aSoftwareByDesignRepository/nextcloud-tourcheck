<?php

declare(strict_types=1);

namespace OCA\TourCheck\Cron;

use OCA\TourCheck\Service\AnchorService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Db\SiteMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Delivers pending anchors + fires periodic/nightly anchor requests.
 * The capture path never blocks on TSA — this job is the only sender.
 */
class AnchorSenderJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly AnchorService $anchors,
		private readonly SiteMapper $sites,
		private readonly SettingsService $settings,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(10 * 60);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('tck.job.anchorsender', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return; // another worker is running this sweep — next interval covers it
		}
		try {
			// Periodic trigger: every N journal entries per site.
			foreach ($this->sites->findAll() as $site) {
				if ((int)$site->getActive() === 1) {
					$this->anchors->requestPeriodicIfDue((int)$site->getId());
				}
			}
			$this->anchors->processPending();
			$this->settings->markCronRun($this->time->getDateTime());
		} finally {
			$this->lockProvider->releaseLock('tck.job.anchorsender', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
