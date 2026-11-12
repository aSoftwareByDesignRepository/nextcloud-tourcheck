<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\Logbook;
use OCA\TourCheck\Db\LogbookMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * F3: one entry → one customer mail. Supervisor+ only; the recipient is
 * the site's configured client contact unless a supervisor explicitly
 * overrides it (override is journaled — accountability over convenience).
 * A mail failure never touches the entry: the send leg is post-commit,
 * journaled either way, and reported back to the caller as {sent:false}.
 */
class EntryMailService
{
	public function __construct(
		private readonly IncidentMapper $incidents,
		private readonly LogbookMapper $logbook,
		private readonly SiteMapper $sites,
		private readonly PhotoService $photos,
		private readonly AccessControlService $acl,
		private readonly EventService $events,
		private readonly RateLimitService $rateLimit,
		private readonly IMailer $mailer,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
	}

	/** @return array{sent: bool, sent_to: ?string} */
	public function sendIncident(int $id, ?string $recipient): array
	{
		$inc = $this->incidents->find($id);
		if ($inc === null) {
			throw new NotFoundException();
		}
		$site = $this->requireSite((int)$inc->getSiteId());
		$actorUid = $this->acl->assertCanSuperviseSite((int)$inc->getSiteId());
		// Outbound side-effect: per-actor bucket so a hijacked supervisor
		// session cannot bulk-mail customer contacts (429 on burst).
		$this->rateLimit->check('entrymail', (int)$inc->getSiteId() . '|' . $actorUid, 10, 600);
		$to = $this->resolveRecipient($recipient, $site);

		$subject = $this->l->t('TourCheck — %s — incident %s', [
			$site->getName(), $inc->getCreatedAt()?->format('Y-m-d H:i') ?? '',
		]);
		$body = $this->l->t("Site: %s\nTime: %s\nCategory: %s\nSeverity: %s\nTitle: %s\n\n%s", [
			$site->getName(),
			$inc->getCreatedAt()?->format('Y-m-d H:i') ?? '',
			(string)$inc->getCategory(),
			(string)$inc->getSeverity(),
			(string)($inc->getTitle() ?? '—'),
			(string)($inc->getNote() ?? ''),
		]);

		$attachments = [];
		$photoId = (int)$inc->getPhotoFileid();
		if ($photoId > 0) {
			$file = $this->photos->read((int)$inc->getSiteId(), $photoId);
			if ($file !== null) {
				$attachments[] = $this->mailer->createAttachment($file->getContent(), 'incident-' . $id . '.jpg', 'image/jpeg');
			}
		}
		return $this->send((int)$inc->getSiteId(), 'incident', $id, $to, $subject, $body, $attachments, $actorUid);
	}

	/** @return array{sent: bool, sent_to: ?string} */
	public function sendLogbook(int $id, ?string $recipient): array
	{
		$l = $this->logbook->find($id);
		if ($l === null) {
			throw new NotFoundException();
		}
		$site = $this->requireSite((int)$l->getSiteId());
		$actorUid = $this->acl->assertCanSuperviseSite((int)$l->getSiteId());
		$this->rateLimit->check('entrymail', (int)$l->getSiteId() . '|' . $actorUid, 10, 600);
		$to = $this->resolveRecipient($recipient, $site);

		$subject = $this->l->t('TourCheck — %s — logbook entry %s', [
			$site->getName(), $l->getCreatedAt()?->format('Y-m-d H:i') ?? '',
		]);
		$body = $this->l->t("Site: %s\nTime: %s\nKind: %s\nAuthor: %s\n\n%s", [
			$site->getName(),
			$l->getCreatedAt()?->format('Y-m-d H:i') ?? '',
			(string)$l->getKind(),
			(string)$l->getAuthorUid(),
			(string)$l->getBody(),
		]);
		return $this->send((int)$l->getSiteId(), 'logbook', $id, $to, $subject, $body, [], $actorUid);
	}

	private function requireSite(int $siteId): Site
	{
		$site = $this->sites->find($siteId);
		if ($site === null) {
			throw new NotFoundException();
		}
		return $site;
	}

	/** Explicit recipient must still be a syntactically valid address; default = site client contact. */
	private function resolveRecipient(?string $recipient, Site $site): string
	{
		$to = trim((string)($recipient ?? ''));
		if ($to === '') {
			$to = trim((string)$site->getClientContact());
		}
		if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false || mb_strlen($to) > 254) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'No valid recipient.', ['recipient' => 'invalid']);
		}
		return $to;
	}

	/** @param list<\OCP\Mail\IAttachment> $attachments */
	private function send(int $siteId, string $kind, int $id, string $to, string $subject, string $body, array $attachments, string $actorUid): array
	{
		try {
			$msg = $this->mailer->createMessage();
			$msg->setTo([$to => $to]);
			$msg->setSubject($subject);
			$msg->setPlainBody($body);
			// NH-02: HTML twin — escaped verbatim rows, no external assets.
			$msg->setHtmlBody(
				'<h2 style="font-family:sans-serif">' . htmlspecialchars($subject) . '</h2>'
				. '<pre style="font-family:sans-serif;font-size:14px;white-space:pre-wrap">'
				. htmlspecialchars($body) . '</pre>'
			);
			foreach ($attachments as $att) {
				$msg->attach($att);
			}
			$this->mailer->send($msg);
		} catch (\Throwable $e) {
			$this->logger->warning('tourcheck: entry mail failed', ['kind' => $kind, 'id' => $id, 'e' => $e->getMessage()]);
			$this->events->journalSignal($siteId, 'entry_send_failed', [
				'actor_uid' => $actorUid, 'ref' => $kind . ':' . $id, 'recipient' => $to,
			]);
			return ['sent' => false, 'sent_to' => null];
		}
		$this->events->journalSignal($siteId, 'entry_sent_customer', [
			'actor_uid' => $actorUid, 'ref' => $kind . ':' . $id, 'recipient' => $to,
		]);
		return ['sent' => true, 'sent_to' => $to];
	}
}
