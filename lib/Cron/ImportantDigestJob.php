<?php

declare(strict_types=1);

namespace OCA\TourCheck\Cron;

use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\Logbook;
use OCA\TourCheck\Db\LogbookMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\NotificationService;
use OCA\TourCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * F2: morning "wichtig" digest — once per site per day, at the configured
 * local hour. Dedupe rides on the unique notif dedupe_key, so cron overlap
 * and retries can never double-notify; per-site try/catch keeps one sick
 * site from starving the rest.
 */
class ImportantDigestJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settings,
		private readonly SiteMapper $sites,
		private readonly IncidentMapper $incidents,
		private readonly LogbookMapper $logbook,
		private readonly NotificationService $notifs,
		private readonly AccessControlService $acl,
		private readonly IUserManager $users,
		private readonly IMailer $mailer,
		private readonly IL10N $l,
		private readonly ILockingProvider $lockProvider,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('tck.job.importantdigest', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return; // another worker holds the sweep — next tick covers it
		}
		try {
			if (!$this->settings->isDigestEnabled()) {
				$this->settings->markCronRun($this->time->getDateTime());
				return;
			}
			$tz = new \DateTimeZone($this->settings->getDefaultTimezone());
			$local = new \DateTimeImmutable('now', $tz);
			// Cron-health stamp is unconditional — the "last cron run"
			// indicator must advance even when the job is only passing
			// through outside the digest hour.
			$this->settings->markCronRun($this->time->getDateTime());
			if ((int)$local->format('G') !== $this->settings->getDigestHour()) {
				return; // fires inside the configured local hour only
			}
			$since = $local->sub(new \DateInterval('PT24H'));
			foreach ($this->sites->findAll() as $site) {
				try {
					if ((int)$site->getActive() !== 1) {
						continue;
					}
					$siteId = (int)$site->getId();
					$incidents = $this->incidents->findImportantSince($siteId, $since);
					$entries = $this->logbook->findImportantSince($siteId, $since);
					$total = count($incidents) + count($entries);
					if ($total === 0) {
						continue; // quiet morning — no mail, no notification
					}
					$sent = $this->notifs->notifySite(
						$siteId,
						'digest:' . $siteId . ':' . $local->format('Y-m-d'),
						'important_digest',
						$total . ' flagged item(s) at ' . $site->getName(),
						$site,
						['count' => $total, 'incidents' => count($incidents), 'logbook' => count($entries)],
					);
					// NH-01: email leg rides the same dedupe outcome — sent
					// only when the notification actually fired (first run).
					if ($sent && $this->settings->isDigestEmailEnabled()) {
						$this->mailDigest($site, $incidents, $entries, $local);
					}
				} catch (\Throwable $e) {
					$this->logger->warning('tourcheck: digest site failed', ['site' => $site->getId(), 'e' => $e->getMessage()]);
				}
			}
		} finally {
			$this->lockProvider->releaseLock('tck.job.importantdigest', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Email leg — supervisors+planners with a configured address. Runs
	 * only when the notification dedupe won (no duplicate mail on retry);
	 * a mail failure is logged, never fatal.
	 * @param list<Incident> $incidents @param list<Logbook> $entries
	 */
	private function mailDigest(Site $site, array $incidents, array $entries, \DateTimeImmutable $local): void
	{
		$recipients = [];
		foreach ($this->acl->supervisorRecipients((int)$site->getId()) as $uid) {
			$user = $this->users->get($uid);
			$email = $user === null ? null : trim((string)$user->getEMailAddress());
			if ($email !== null && $email !== '') {
				$recipients[$email] = $user->getDisplayName() !== '' ? $user->getDisplayName() : $email;
			}
		}
		if ($recipients === []) {
			return;
		}
		$plain = [];
		$rows = '';
		foreach ($incidents as $i) {
			$label = ($i->getTitle() ?? '') !== '' ? (string)$i->getTitle() : (string)$i->getCategory();
			$plain[] = '• ' . $this->l->t('Incident') . ' [' . $i->getSeverity() . '] ' . $label . ' — ' . ($i->getCreatedAt()?->format('H:i') ?? '?') . ' (' . $i->getCreatedBy() . ')';
			$rows .= '<tr><td>' . $this->l->t('Incident') . '</td><td>' . htmlspecialchars((string)$i->getSeverity()) . '</td><td>'
				. htmlspecialchars($label) . '</td><td>' . htmlspecialchars($i->getCreatedAt()?->format('H:i') ?? '?') . '</td></tr>';
		}
		foreach ($entries as $e) {
			$body = mb_strimwidth((string)$e->getBody(), 0, 140, '…');
			$plain[] = '• ' . $this->l->t('Logbook') . ' [' . $e->getKind() . '] ' . $body . ' — ' . ($e->getCreatedAt()?->format('H:i') ?? '?') . ' (' . $e->getAuthorUid() . ')';
			$rows .= '<tr><td>' . $this->l->t('Logbook') . '</td><td>' . htmlspecialchars((string)$e->getKind()) . '</td><td>'
				. htmlspecialchars($body) . '</td><td>' . htmlspecialchars($e->getCreatedAt()?->format('H:i') ?? '?') . '</td></tr>';
		}
		$subject = $this->l->t('TourCheck morning digest — %s — %s', [$site->getName(), $local->format('Y-m-d')]);
		$html = '<h2>' . htmlspecialchars($subject) . '</h2>'
			. '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse">'
			. '<thead><tr><th>' . htmlspecialchars($this->l->t('Type')) . '</th><th>' . htmlspecialchars($this->l->t('Severity') . '/' . $this->l->t('Kind')) . '</th><th>' . htmlspecialchars($this->l->t('Item')) . '</th><th>' . htmlspecialchars($this->l->t('Time')) . '</th></tr></thead>'
			. '<tbody>' . $rows . '</tbody></table>'
			. '<p>' . htmlspecialchars($this->l->t('Flagged as important by staff in the last 24 hours.')) . '</p>';
		try {
			$msg = $this->mailer->createMessage();
			$msg->setTo($recipients);
			$msg->setSubject($subject);
			$msg->setPlainBody(implode("\n", $plain));
			$msg->setHtmlBody($html);
			$this->mailer->send($msg);
		} catch (\Throwable $e) {
			$this->logger->warning('tourcheck: digest mail failed', ['site' => $site->getId(), 'e' => $e->getMessage()]);
		}
	}
}
