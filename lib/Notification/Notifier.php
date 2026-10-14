<?php

declare(strict_types=1);

namespace OCA\TourCheck\Notification;

use OCA\TourCheck\Constants;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders app notifications (missed rounds, dead-man escalations, anchor
 * failures, threshold + token anomalies, worker help requests, panic).
 * Without this INotifier every stored notification is undeliverable — the
 * server drops rows it cannot prepare. Subjects are stable machine keys;
 * parameters carry site/round context, text is localized per recipient.
 */
class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return Constants::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10nFactory->get(Constants::APP_ID)->t('TourCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Constants::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Constants::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$site = (string)($p['site'] ?? '');
		$siteId = (string)$notification->getObjectId();
		$link = $notification->getObjectType() === 'site' && ctype_digit($siteId)
			? $this->urlGenerator->linkToRouteAbsolute('tourcheck.page.site', ['siteId' => (int)$siteId])
			: $this->urlGenerator->linkToRouteAbsolute('tourcheck.page.dashboard');

		switch ($notification->getSubject()) {
			case 'round_missed':
				$notification->setParsedSubject($l->t('Round missed: %s', [$site]));
				$notification->setParsedMessage($l->t('A scheduled round at %s was not completed in its window.', [$site]));
				break;
			case 'deadman_alert':
				$minutes = (int)($p['minutes'] ?? 0);
				$notification->setParsedSubject($l->t('No check-in from guard at %s', [$site]));
				$notification->setParsedMessage($l->t('No scan or position update for %d minutes during an active round at %s. Check on the worker.', [$minutes, $site]));
				break;
			case 'anchor_failed':
				$notification->setParsedSubject($l->t('Timestamp anchoring failed: %s', [$site]));
				$notification->setParsedMessage($l->t('External timestamping failed permanently — the evidence chain head stays UNANCHORED. Check the TSA endpoint in settings.'));
				break;
			case 'threshold_alert':
				$notification->setParsedSubject($l->t('Checklist value out of range: %s', [$site]));
				$notification->setParsedMessage((string)($p['detail'] ?? '') !== '' ? (string)$p['detail'] : $l->t('A recorded value exceeded its allowed range; an incident was created.'));
				break;
			case 'checklist_failure':
				$notification->setParsedSubject($l->t('Required check failed: %s', [$site]));
				$notification->setParsedMessage((string)($p['detail'] ?? '') !== '' ? $l->t('A worker reported a failed required checklist item at %s: %s', [$site, (string)$p['detail']]) : $l->t('A worker reported a failed required checklist item; an incident was created.'));
				break;
			case 'token_anomaly_burst':
				$notification->setParsedSubject($l->t('Unusual tag activity: %s', [$site]));
				$notification->setParsedMessage($l->t('More than 20 suspicious token scans in 5 minutes (rotated or inactive tags). Check whether old tags are still posted or tokens circulate.'));
				break;
			case 'token_invalid_burst':
				$scope = (string)($p['scope'] ?? 'global');
				$notification->setParsedSubject($l->t('Invalid token burst detected'));
				$notification->setParsedMessage($l->t('More than 20 invalid checkpoint-token submissions in 5 minutes (scope: %s). Possible scanner misconfiguration or enumeration probing.', [$scope]));
				break;
			case 'help_request':
				$notification->setParsedSubject($l->t('Worker needs assistance: %s', [$site]));
				$notification->setParsedMessage($l->t('%s signalled "%s" at %s.', [(string)($p['actor'] ?? '?'), (string)($p['signal'] ?? 'help'), $site]));
				break;
			case 'incident_critical':
				$notification->setParsedSubject($l->t('Critical incident: %s', [$site]));
				$notification->setParsedMessage($l->t('%s reported a critical incident at %s — respond immediately.', [(string)($p['actor'] ?? 'System'), $site]));
				break;
			case 'panic_alert':
				$notification->setParsedSubject($l->t('PANIC triggered: %s', [$site]));
				$notification->setParsedMessage($l->t('%s triggered the emergency action at %s. An incident was created — respond now.', [(string)($p['actor'] ?? '?'), $site]));
				break;
			case 'key_overdue':
				$notification->setParsedSubject($l->t('Key still checked out: %s', [$site]));
				$notification->setParsedMessage($l->t('Key "%s" at %s has been checked out since %s.', [(string)($p['key'] ?? '?'), $site, (string)($p['since'] ?? '?')]));
				break;
			default:
				throw new UnknownNotificationException();
		}
		$notification->setLink($link);
		$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Constants::APP_ID, 'app.svg')));
		return $notification;
	}
}
