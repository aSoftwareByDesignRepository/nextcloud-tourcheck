<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Notif;
use OCA\TourCheck\Db\NotifMapper;
use OCA\TourCheck\Db\Site;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbalException;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * In-app notifications with hard dedupe: dedupe_key is UNIQUE — two cron
 * workers racing a missed-detection alert produce exactly one row, one
 * notification (NN: dedupe, not "best effort").
 */
class NotificationService
{
	public function __construct(
		private readonly NotifMapper $notifs,
		private readonly INotificationManager $notifications,
		private readonly AccessControlService $acl,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Send a deduped notification to site supervisors+planners.
	 * @return bool true when actually sent (false = dedupe hit)
	 */
	public function notifySite(int $siteId, string $dedupeKey, string $subject, string $message, Site $site, array $subjectParams = []): bool
	{
		$n = new Notif();
		$n->setDedupeKey($dedupeKey);
		$n->setSentAt($this->timeFactory->getDateTime());
		try {
			$this->notifs->insert($n);
		} catch (DbalException) {
			return false; // already sent — unique key won the race
		}

		$recipients = $this->acl->supervisorRecipients($siteId);
		foreach ($recipients as $uid) {
			try {
				$notif = $this->notifications->createNotification();
				$notif->setApp(Constants::APP_ID)
					->setUser($uid)
					->setDateTime(new \DateTime())
					->setObject('site', (string)$siteId)
					->setSubject($subject, ['site' => $site->getName()] + $subjectParams)
					// NC rejects messages >64 chars (isset($message[64])) —
					// the localized render lives in Notification\Notifier;
					// the wire value is a bounded fallback only.
					->setMessage(mb_substr($message, 0, 64));
				$this->notifications->notify($notif);
			} catch (\Throwable $e) {
				$this->logger->warning('tourcheck: notification failed', ['uid' => $uid, 'e' => $e->getMessage()]);
			}
		}
		return true;
	}
}
