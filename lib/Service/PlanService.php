<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\TourPlan;
use OCA\TourCheck\Db\TourPlanMapper;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Tour plans. `cps_json` = ordered checkpoint list with per-stop offsets:
 *   [{checkpoint_id, offset_min, before_min, after_min}]
 * `recurrence_json`:
 *   {type:'daily'|'weekly'|'interval', days:[0-6]?, time:'HH:MM',
 *    interval_hours?:int, anchor?:ISO8601, duration_min:int}
 */
class PlanService
{
	public function __construct(
		private readonly TourPlanMapper $plans,
		private readonly CheckpointService $checkpoints,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly ChainService $chain,
		private readonly SchedulingService $sched,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
	) {
	}

	/** @return list<TourPlan> */
	public function listForSite(int $siteId): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->plans->findForSite($siteId);
	}

	public function get(int $planId): TourPlan
	{
		$plan = $this->plans->find($planId);
		if ($plan === null) {
			throw new NotFoundException();
		}
		$this->acl->assertCanViewSite((int)$plan->getSiteId());
		return $plan;
	}

	public function create(int $siteId, array $data): TourPlan
	{
		$this->sites->getVisible($siteId);
		$uid = $this->acl->assertCanManageSite($siteId);
		$plan = $this->fill(new TourPlan(), $siteId, $data);
		$plan->setActive(0);
		$plan->setRevision(1);
		$now = $this->timeFactory->getDateTime();
		$plan->setCreatedAt($now);
		$plan->setUpdatedAt($now);
		$plan = $this->plans->insert($plan);
		$this->journal($siteId, $uid, 'plan_created', (int)$plan->getId(), (int)$plan->getRevision());
		return $plan;
	}

	/**
	 * Plan edit. CAS is evaluated UNDER the plan row lock — the old
	 * read-then-check let two concurrent updates both pass. While the txn
	 * is open, SCHEDULED-not-started rounds of this plan are dropped
	 * (resync spec); occurrences regenerate from the new revision after
	 * commit. ACTIVE/past rounds and rounds an early scan attached to keep
	 * their snapshot — evidence freezes them (GA-5, NN-10).
	 */
	public function update(int $planId, array $data, ?int $baseRevision): TourPlan
	{
		$plan = $this->get($planId);
		$siteId = (int)$plan->getSiteId();
		$uid = $this->acl->assertCanManageSite($siteId);
		$this->db->beginTransaction();
		try {
			$plan = $this->plans->findForUpdate($planId);
			if ($plan === null) {
				throw new NotFoundException();
			}
			$this->sites->assertRevision($plan->getRevision(), $baseRevision);
			$plan = $this->fill($plan, $siteId, $data);
			$plan->setRevision((int)$plan->getRevision() + 1);
			$plan->setUpdatedAt($this->timeFactory->getDateTime());
			$plan = $this->plans->update($plan);
			$resynced = (int)$plan->getActive() === 1 ? $this->sched->deleteStaleScheduledRounds($planId) : 0;
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		// Regeneration outside the txn: per-occurrence unique-key collisions
		// are expected (a frozen round may sit at that start) and must not
		// abort the edit — the cron generator heals any remainder anyway.
		$created = (int)$plan->getActive() === 1 ? $this->sched->generateForPlan($plan) : 0;
		$this->journal($siteId, $uid, 'plan_updated', $planId, (int)$plan->getRevision(), ['resynced' => $resynced, 'generated' => $created]);
		return $plan;
	}

	/** Activate → future occurrences generated from this revision's snapshot. */
	public function activate(int $planId): TourPlan
	{
		return $this->setActive($planId, 1, 'plan_activated');
	}

	public function deactivate(int $planId): TourPlan
	{
		return $this->setActive($planId, 0, 'plan_deactivated');
	}

	private function setActive(int $planId, int $active, string $body): TourPlan
	{
		$plan = $this->get($planId);
		$siteId = (int)$plan->getSiteId();
		$uid = $this->acl->assertCanManageSite($siteId);
		$resynced = 0;
		$this->db->beginTransaction();
		try {
			$plan = $this->plans->findForUpdate($planId);
			if ($plan === null) {
				throw new NotFoundException();
			}
			$plan->setActive($active);
			$plan->setRevision((int)$plan->getRevision() + 1);
			$plan->setUpdatedAt($this->timeFactory->getDateTime());
			$plan = $this->plans->update($plan);
			// Deactivating retires pending occurrences — otherwise they'd
			// still go missed and notify for a plan nobody runs anymore.
			if ($active === 0) {
				$resynced = $this->sched->deleteStaleScheduledRounds($planId);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		// Activating materializes the horizon immediately — workers should
		// not wait for the next cron tick to see today's round.
		$created = $active === 1 ? $this->sched->generateForPlan($plan) : 0;
		$this->journal($siteId, $uid, $body, $planId, (int)$plan->getRevision(), ['resynced' => $resynced, 'generated' => $created]);
		return $plan;
	}

	private function journal(int $siteId, string $uid, string $body, int $planId, int $revision, array $extra = []): void
	{
		$this->chain->append($siteId, [
			'kind' => Constants::KIND_ADMIN,
			'actorUid' => $uid,
			'body' => $body,
			'payloadRef' => 'plan:' . $planId,
			'payloadFields' => ['plan' => $planId, 'revision' => $revision] + $extra,
		]);
	}

	/**
	 * Checkpoint list — every id must be an active checkpoint OF THIS SITE.
	 * Cross-site references rejected (evidence can't leak site scope).
	 * @return list<array{checkpoint_id:int,offset_min:int,before_min:int,after_min:int}>
	 */
	public function validateCps(int $siteId, mixed $cps): array
	{
		if (!is_array($cps) || $cps === []) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'A plan needs at least one checkpoint.', ['checkpoints' => 'required']);
		}
		$active = [];
		foreach ($this->checkpoints->listForSite($siteId) as $cp) {
			$active[(int)$cp->getId()] = $cp;
		}
		$out = [];
		foreach ($cps as $i => $entry) {
			$cpId = (int)($entry['checkpoint_id'] ?? 0);
			if (!isset($active[$cpId])) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown checkpoint.', ['checkpoints' => 'invalid:' . $i]);
			}
			$out[] = [
				'checkpoint_id' => $cpId,
				'offset_min' => max(0, min(24 * 60, (int)($entry['offset_min'] ?? 0))),
				'before_min' => max(0, min(24 * 60, (int)($entry['before_min'] ?? 15))),
				'after_min' => max(0, min(24 * 60, (int)($entry['after_min'] ?? 60))),
			];
			if (count($out) > 100) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Too many checkpoints.', ['checkpoints' => 'invalid']);
			}
		}
		return $out;
	}

	/** @return array{type:string,days?:list<int>,time?:string,interval_hours?:int,anchor?:string,duration_min:int} */
	public function validateRecurrence(mixed $rec): array
	{
		if (!is_array($rec)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Recurrence required.', ['recurrence' => 'required']);
		}
		$type = (string)($rec['type'] ?? '');
		$duration = (int)($rec['duration_min'] ?? 0);
		if ($duration < 5 || $duration > 24 * 60) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Duration must be 5 min – 24 h.', ['duration_min' => 'invalid']);
		}
		if ($type === 'daily' || $type === 'weekly') {
			$time = (string)($rec['time'] ?? '');
			if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Time must be HH:MM.', ['time' => 'invalid']);
			}
			$out = ['type' => $type, 'time' => $time, 'duration_min' => $duration];
			if ($type === 'weekly') {
				$days = $rec['days'] ?? [];
				if (!is_array($days) || $days === []) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Pick at least one weekday.', ['days' => 'required']);
				}
				$days = array_values(array_unique(array_map('intval', $days)));
				foreach ($days as $d) {
					if ($d < 0 || $d > 6) {
						throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid weekday.', ['days' => 'invalid']);
					}
				}
				sort($days);
				$out['days'] = $days;
			}
			return $out;
		}
		if ($type === 'interval') {
			$h = (int)($rec['interval_hours'] ?? 0);
			if ($h < 1 || $h > 24 * 14) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Interval must be 1–336 h.', ['interval_hours' => 'invalid']);
			}
			$anchor = (string)($rec['anchor'] ?? '');
			$dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $anchor);
			if ($dt === false) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Anchor must be ISO-8601.', ['anchor' => 'invalid']);
			}
			return ['type' => 'interval', 'interval_hours' => $h, 'anchor' => $anchor, 'duration_min' => $duration];
		}
		throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown recurrence type.', ['recurrence' => 'invalid']);
	}

	private function fill(TourPlan $plan, int $siteId, array $data): TourPlan
	{
		if (array_key_exists('name', $data) || $plan->getName() === null) {
			$name = trim((string)($data['name'] ?? ''));
			if ($name === '' || strlen($name) > 190) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Plan name is required.', ['name' => 'required']);
			}
			$plan->setName($name);
		}
		if (array_key_exists('checkpoints', $data) || $plan->getCpsJson() === null) {
			$plan->setCpsJson(json_encode($this->validateCps($siteId, $data['checkpoints'] ?? null)));
		}
		if (array_key_exists('recurrence', $data) || $plan->getRecurrenceJson() === null) {
			$plan->setRecurrenceJson(json_encode($this->validateRecurrence($data['recurrence'] ?? null)));
		}
		if (array_key_exists('assignee', $data)) {
			$assignee = trim((string)$data['assignee']);
			if ($assignee !== '') {
				// Ghost-UID rule: existence AND site membership — assigning a
				// plan to a stranger or a deleted user is a silent no-op hole.
				$this->acl->assertValidUid($assignee);
				if ($this->acl->siteRole($assignee, $siteId) === null) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Assignee is not a member of this site.', ['assignee' => 'invalid']);
				}
			}
			$plan->setAssignee($assignee !== '' ? $assignee : null);
		}
		if (array_key_exists('tolerance_min', $data)) {
			$plan->setToleranceMin(max(0, min(120, (int)$data['tolerance_min'])));
		}
		// NH-07 dead-man check-in: minutes without round activity before the
		// escalation fires. Strict digits — 'abc'/'2.9' must not silently
		// coerce into a config the operator never asked for. null/0 = off.
		if (array_key_exists('checkin_interval_min', $data)) {
			$v = $data['checkin_interval_min'];
			if ($v === null || $v === '') {
				$plan->setCheckinIntervalMin(null);
			} elseif (is_int($v) || (is_string($v) && preg_match('/^\d{1,4}$/', $v))) {
				$plan->setCheckinIntervalMin((int)$v > 0 ? min(480, (int)$v) : null);
			} else {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Check-in interval must be whole minutes.', ['checkin_interval_min' => 'invalid']);
			}
		}
		if ($plan->getToleranceMin() === null) {
			$plan->setToleranceMin(15);
		}
		$plan->setSiteId($siteId);
		return $plan;
	}
}
