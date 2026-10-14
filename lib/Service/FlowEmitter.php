<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * NH-04 suite gravity: emits the domain events a Nextcloud Flow rule can
 * hang on (entity OCA\TourCheck\Flow\TourCheckEntity). Legacy string-name
 * dispatch — that is the dispatch path workflowengine listeners attach to.
 *
 * Two invariants, both security/integrity relevant:
 *  - Subject carries IDENTIFIERS ONLY (NN-08): a Flow operation can fan
 *    out to webhooks/mail — names, notes, or uids in the subject would
 *    leak personal data beyond the app's ACL boundary.
 *  - Post-commit, never-throw: callers emit AFTER their transaction has
 *    committed, and a broken Flow op must never roll back recorded
 *    evidence — every dispatch failure is logged and swallowed.
 */
class FlowEmitter
{
	public const EVENT_ROUND_MISSED = 'OCA\TourCheck::roundMissed';
	public const EVENT_ROUND_COMPLETED = 'OCA\TourCheck::roundCompleted';
	public const EVENT_ROUND_SKIPPED = 'OCA\TourCheck::roundSkipped';
	public const EVENT_INCIDENT_CREATED = 'OCA\TourCheck::incidentCreated';

	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array{siteId:int,roundId?:int,incidentId?:int,status?:string} $subject
	 */
	public function emit(string $eventName, array $subject): void
	{
		try {
			$this->dispatcher->dispatch($eventName, new GenericEvent($subject));
		} catch (\Throwable $e) {
			$this->logger->warning('tourcheck: flow emit failed', [
				'event' => $eventName,
				'error' => $e->getMessage(),
			]);
		}
	}
}
