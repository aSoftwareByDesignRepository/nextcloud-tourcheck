<?php

declare(strict_types=1);

namespace OCA\TourCheck\Flow;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\FlowEmitter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\GenericEntityEvent;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IRuleMatcher;

/**
 * NH-04: exposes TourCheck domain events to Nextcloud Flow so admins can
 * wire e.g. "round missed → mail the duty officer" without code.
 *
 * The rule matcher stores the GenericEvent subject here during
 * prepareRuleMatcher; isLegitimatedForUserId then gates on SITE scope —
 * a flow must never run for a user who cannot even see the site (uniform
 * fail-closed, same bar as assertCanViewSite).
 */
class TourCheckEntity implements IEntity
{
	private ?Event $event = null;

	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly AccessControlService $acl,
	) {
	}

	public function getName(): string
	{
		return $this->l10n->t('TourCheck round');
	}

	public function getIcon(): string
	{
		return $this->urlGenerator->imagePath(Constants::APP_ID, 'app.svg');
	}

	public function getEvents(): array
	{
		return [
			new GenericEntityEvent($this->l10n->t('Round missed'), FlowEmitter::EVENT_ROUND_MISSED),
			new GenericEntityEvent($this->l10n->t('Round completed'), FlowEmitter::EVENT_ROUND_COMPLETED),
			new GenericEntityEvent($this->l10n->t('Round skipped'), FlowEmitter::EVENT_ROUND_SKIPPED),
			new GenericEntityEvent($this->l10n->t('Incident created'), FlowEmitter::EVENT_INCIDENT_CREATED),
		];
	}

	public function prepareRuleMatcher(IRuleMatcher $ruleMatcher, string $eventName, Event $event): void
	{
		if (!$event instanceof GenericEvent) {
			return;
		}
		$subject = $event->getSubject();
		if (!is_array($subject) || !isset($subject['siteId'])) {
			return;
		}
		$this->event = $event;
		// Passes the id-only subject to configured checks — same call the
		// core File entity makes.
		$ruleMatcher->setEntitySubject($this, $subject);
	}

	public function isLegitimatedForUserId(string $userId): bool
	{
		if (!$this->event instanceof GenericEvent) {
			return false;
		}
		$subject = $this->event->getSubject();
		if (!is_array($subject) || !isset($subject['siteId']) || !is_numeric($subject['siteId'])) {
			return false;
		}
		return $this->acl->siteRole($userId, (int)$subject['siteId']) !== null;
	}
}
