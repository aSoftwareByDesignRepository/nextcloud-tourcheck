<?php

declare(strict_types=1);

namespace OCA\TourCheck\Cron;

use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** Webhook deliveries: pending + retry-due, bounded per run. */
class WebhookDeliveryJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly WebhookService $webhooks,
		private readonly SettingsService $settings,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('tck.job.webhooks', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return;
		}
		try {
			$this->webhooks->sweepDeliveries();
			$this->settings->markCronRun($this->time->getDateTime());
		} finally {
			$this->lockProvider->releaseLock('tck.job.webhooks', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
