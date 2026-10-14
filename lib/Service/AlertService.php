<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Alert;
use OCA\TourCheck\Db\AlertDeliveryMapper;
use OCA\TourCheck\Db\AlertMapper;
use OCA\TourCheck\Db\EscalationLeg;
use OCA\TourCheck\Db\EscalationLegMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Mail\IMailer;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Alert lifecycle (SPEC §9): fired → acked | escalating → resolved |
 * resolved_false_alarm. Terminal states immutable; resolve requires a note.
 *
 * Exactly-once escalation (NN-04): each leg is claimed by a unique
 * tck_alert_delivery row BEFORE dispatch — two sweeps can never send the
 * same leg twice, and a crash mid-send leaves a pending row the next sweep
 * retries. No row lock is held across outbound I/O.
 */
class AlertService
{
	public function __construct(
		private readonly AlertMapper $alerts,
		private readonly AlertDeliveryMapper $deliveries,
		private readonly EscalationLegMapper $legs,
		private readonly AccessControlService $acl,
		private readonly NotificationService $notify,
		private readonly SiteService $sites,
		private readonly EventService $events,
		private readonly WebhookService $webhooks,
		private readonly IMailer $mailer,
		private readonly IUserManager $users,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Fire an alert — idempotent on $clientEventId (replays return the stored
	 * row). Caller passes a deterministic key for server-derived alerts or the
	 * client's id for device-originated ones.
	 */
	public function fire(string $type, int $siteId, ?string $workerUid, string $severity, array $payload, string $clientEventId, ?int $roundId = null): Alert
	{
		if ($clientEventId !== '') {
			$existing = $this->alerts->findByClientEventId($clientEventId);
			if ($existing !== null) {
				return $existing;
			}
		}
		$a = new Alert();
		$a->setType($type);
		$a->setSiteId($siteId);
		$a->setWorkerUid($workerUid);
		$a->setRoundId($roundId);
		$a->setSeverity(in_array($severity, ['info', 'high', 'critical'], true) ? $severity : 'high');
		$a->setState('fired');
		$a->setPayloadJson(json_encode($payload));
		$a->setEscalatedLeg(0);
		$a->setFiredAt($this->timeFactory->getDateTime());
		$a->setClientEventId($clientEventId === '' ? null : $clientEventId);
		try {
			$a = $this->alerts->insert($a);
		} catch (\OCP\DB\Exception $e) {
			// unique collision on client_event_id — adopt the winner (NN-04
			// path: the alert must never be double-fired OR lost)
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION && $clientEventId !== '') {
				$winner = $this->alerts->findByClientEventId($clientEventId);
				if ($winner !== null) {
					return $winner;
				}
			}
			throw $e;
		}
		$this->events->journalSignal($siteId, 'alert_fired:' . $type, [
			'actor_uid' => $workerUid, 'alert_id' => (int)$a->getId(), 'round_id' => $roundId,
		]);
		$this->webhooks->emit('alert_' . (int)$a->getId(), 'alert_fired', [
			'alert_id' => (int)$a->getId(), 'type' => $type, 'site_id' => $siteId,
			'worker_uid' => $workerUid, 'severity' => $a->getSeverity(),
			'fired_at' => $a->getFiredAt()->format(\DateTimeInterface::ATOM),
		]);
		return $a;
	}

	/** Acknowledge — halts escalation; records actor+time. Idempotent. */
	public function ack(int $alertId, string $actorUid): Alert
	{
		$this->db->beginTransaction();
		try {
			$a = $this->alerts->findForUpdate($alertId);
			if ($a === null) {
				throw new NotFoundException();
			}
			$this->acl->assertCanSuperviseSite((int)$a->getSiteId());
			if (in_array($a->getState(), ['resolved', 'resolved_false_alarm'], true)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Alert already resolved.', ['alert' => 'terminal']);
			}
			if ($a->getAckedAt() === null) {
				$a->setAckedAt($this->timeFactory->getDateTime());
				$a->setAckedBy($actorUid);
				if ($a->getState() !== 'acked') {
					$a->setState('acked');
				}
				$this->alerts->update($a);
				$this->events->journalSignal((int)$a->getSiteId(), 'alert_acked', [
					'actor_uid' => $actorUid, 'alert_id' => $alertId, 'type' => $a->getType(),
				]);
			}
			$this->db->commit();
			return $a;
		} catch (\Throwable $t) {
			$this->db->rollBack();
			throw $t;
		}
	}

	/**
	 * Resolve — terminal. Requires a note ≥10 chars (NN/spec: no silent
	 * dismiss). $falseAlarm marks the worker's own "I'm OK" self-resolve.
	 */
	public function resolve(int $alertId, string $actorUid, string $note, bool $falseAlarm = false): Alert
	{
		$note = trim($note);
		if (mb_strlen($note) < 10) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Resolution note required (min. 10 characters).', ['resolution_note' => 'required']);
		}
		$this->db->beginTransaction();
		try {
			$a = $this->alerts->findForUpdate($alertId);
			if ($a === null) {
				throw new NotFoundException();
			}
			if ($falseAlarm) {
				// self-resolve: only the affected worker may false-alarm-close
				if ($a->getWorkerUid() !== $actorUid) {
					throw new NotFoundException();
				}
			} else {
				$this->acl->assertCanSuperviseSite((int)$a->getSiteId());
			}
			if (in_array($a->getState(), ['resolved', 'resolved_false_alarm'], true)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Alert already resolved.', ['alert' => 'terminal']);
			}
			$now = $this->timeFactory->getDateTime();
			$a->setState($falseAlarm ? 'resolved_false_alarm' : 'resolved');
			$a->setResolvedAt($now);
			$a->setResolvedBy($actorUid);
			$a->setResolutionNote($note);
			if ($a->getAckedAt() === null) {
				$a->setAckedAt($now);
				$a->setAckedBy($actorUid);
			}
			$this->alerts->update($a);
			$this->db->commit();
		} catch (\Throwable $t) {
			$this->db->rollBack();
			throw $t;
		}
		$this->events->journalSignal((int)$a->getSiteId(), $falseAlarm ? 'alert_false_alarm' : 'alert_resolved', [
			'actor_uid' => $actorUid, 'alert_id' => $alertId, 'type' => $a->getType(),
		]);
		return $a;
	}

	/** @return list<Alert> */
	public function listForSite(int $siteId, int $limit = 100): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->alerts->findForSite($siteId, $limit);
	}

	/** @return list<Alert> open alerts across sites the caller supervises */
	public function openFor(array $siteIds): array
	{
		$out = [];
		foreach ($siteIds as $sid) {
			foreach ($this->alerts->findOpenForSite($sid) as $a) {
				$out[] = $a;
			}
		}
		return $out;
	}

	public function hasOpenOfType(int $siteId, string $type): bool
	{
		foreach ($this->alerts->findOpenForSite($siteId) as $a) {
			if ($a->getType() === $type) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Escalation sweep (cron, single-flight via caller's distributed lock):
	 * for each open alert, fire every due-but-unclaimed leg in order.
	 */
	public function sweepEscalations(): int
	{
		$now = $this->timeFactory->getDateTime();
		$fired = 0;
		foreach ($this->alerts->findOpen() as $a) {
			// pending legs are claimed by another worker — treat 'fired' and
			// 'escalating' identically: state is display, claims are truth.
			foreach ($this->legsFor((int)$a->getSiteId()) as $leg) {
				$due = $a->getFiredAt()->getTimestamp() + (int)$leg->getDelayMin() * 60;
				if ($now->getTimestamp() < $due) {
					continue;
				}
				$legNo = (int)$leg->getLegNo();
				if (!$this->deliveries->claim((int)$a->getId(), $legNo, (string)$leg->getChannel(), $now)) {
					continue; // already claimed/sent — exactly-once (NN-04)
				}
				try {
					$this->dispatch($a, $leg);
					$this->deliveries->markSent((int)$a->getId(), $legNo, $this->timeFactory->getDateTime());
					$fired++;
				} catch (\Throwable $e) {
					$this->deliveries->bumpAttempt((int)$a->getId(), $legNo);
					$this->logger->warning('tourcheck: escalation leg failed', [
						'alert' => (int)$a->getId(), 'leg' => $legNo, 'e' => $e->getMessage(),
					]);
					// leg stays 'pending' — retried next sweep, never dropped
				}
				if ($a->getEscalatedLeg() < $legNo) {
					$a->setEscalatedLeg($legNo);
					if ($a->getState() === 'fired') {
						$a->setState('escalating');
					}
					$this->alerts->update($a);
				}
			}
		}
		return $fired;
	}

	/**
	 * Escalation chain for a site: configured legs, else the default —
	 * leg 0: NC notify all site supervisors immediately;
	 * leg 1: +2 min mail to supervisors' addresses.
	 * @return list<EscalationLeg>
	 */
	public function legsFor(int $siteId): array
	{
		$legs = $this->legs->findForSite($siteId);
		if ($legs !== []) {
			return $legs;
		}
		$def = [];
		foreach ([[0, 'nc_notify', ['role' => 'supervisor']], [2, 'mail', ['role' => 'supervisor']]] as $i => $d) {
			$l = new EscalationLeg();
			$l->setSiteId($siteId);
			$l->setLegNo($i + 1);
			$l->setDelayMin($d[0]);
			$l->setChannel($d[1]);
			$l->setTargetJson(json_encode($d[2]));
			$def[] = $l;
		}
		return $def;
	}

	private function dispatch(Alert $a, EscalationLeg $leg): void
	{
		$site = $this->sites->findById((int)$a->getSiteId());
		$siteName = $site === null ? ('#' . $a->getSiteId()) : (string)$site->getName();
		$subject = 'alert.' . $a->getType();
		$message = sprintf('Alert %s on site %s (worker %s)', $a->getType(), $siteName, $a->getWorkerUid() ?? '-');
		$target = json_decode((string)$leg->getTargetJson(), true) ?? [];

		switch ($leg->getChannel()) {
			case 'nc_notify':
				$this->notify->notifySite((int)$a->getSiteId(), 'alert_' . $a->getId() . '_leg' . $leg->getLegNo(), $subject, $message, $site, ['alert' => (string)$a->getId()]);
				return;
			case 'mail':
				foreach ($this->mailTargets((int)$a->getSiteId(), $target) as $addr) {
					$msg = $this->mailer->createMessage();
					$msg->setTo([$addr => $addr]);
					$msg->setSubject('[TourCheck] ' . $message);
					$msg->setBodyPlain($message . "\nFired: " . $a->getFiredAt()->format('Y-m-d H:i:s'));
					$this->mailer->send($msg);
				}
				return;
			case 'webhook':
				$this->webhooks->emit('alert_' . (int)$a->getId() . '_leg' . $leg->getLegNo(), 'alert_escalated', [
					'alert_id' => (int)$a->getId(), 'type' => $a->getType(), 'site_id' => (int)$a->getSiteId(), 'leg' => (int)$leg->getLegNo(),
				]);
				return;
			default:
				throw new \RuntimeException('unknown channel ' . $leg->getChannel());
		}
	}

	/** @return list<string> email addresses for the leg target */
	private function mailTargets(int $siteId, array $target): array
	{
		if (isset($target['mail']) && is_string($target['mail']) && trim($target['mail']) !== '') {
			return [trim($target['mail'])];
		}
		$out = [];
		foreach ($this->acl->supervisorRecipients($siteId) as $uid) {
			$user = $this->users->get($uid);
			$mail = $user === null ? '' : (string)$user->getEMailAddress();
			if ($mail !== '') {
				$out[] = $mail;
			}
		}
		return $out;
	}
}
