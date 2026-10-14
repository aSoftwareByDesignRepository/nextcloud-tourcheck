<?php

declare(strict_types=1);

namespace OCA\TourCheck\Cron;

use OCA\TourCheck\Db\ReportSchedMapper;
use OCA\TourCheck\Service\ReportService;
use OCA\TourCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Scheduled branded reports — idempotent per (sched, period) via the
 * unique delivery key; missed runs backfill the previous period only
 * (no catch-up storms).
 */
class ReportScheduleJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ReportSchedMapper $schedules,
		private readonly ReportService $reports,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('tck.job.reportschedule', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return; // another worker is running this sweep — next interval covers it
		}
		try {
			foreach ($this->schedules->findActive() as $sched) {
				// non-fatal: unique (sched, period) key makes retry safe.
				try {
					$period = $this->duePeriod($sched->getCadence(), (string)($sched->getLastPeriod() ?? ''));
					if ($period === null) {
						continue;
					}
					// The delivery lands in the first site-admin's Files.
					$uid = $this->reports->scheduleOwner((int)$sched->getSiteId());
					if ($uid === null) {
						continue;
					}
					$r = $this->reports->generate((int)$sched->getSiteId(), $period, 'sched', (int)$sched->getId(), $uid, ReportService::audienceOf($sched));
					$this->reports->sendDelivery($r['delivery']);
					$sched->setLastPeriod($period);
					$this->schedules->update($sched);
				} catch (\Throwable $e) {
					$this->logger->warning('tourcheck: scheduled report failed', ['sched' => $sched->getId(), 'e' => $e->getMessage()]);
				}
			}
			$this->settings->markCronRun($this->time->getDateTime());
		} finally {
			$this->lockProvider->releaseLock('tck.job.reportschedule', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Returns the period due to generate, or null when nothing is due. */
	private function duePeriod(string $cadence, string $lastPeriod): ?string
	{
		if ($cadence === 'weekly') {
			$due = (new \DateTimeImmutable('monday last week'))->format('o-\WW');
			return $lastPeriod === $due ? null : $due;
		}
		$due = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
		return $lastPeriod === $due ? null : $due;
	}
}
