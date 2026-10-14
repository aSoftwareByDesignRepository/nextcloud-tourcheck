<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundCheckpoint;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Db\TourPlan;
use OCA\TourCheck\Db\TourPlanMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbalException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Occurrence generation. Idempotent by construction: unique key
 * (plan_id, scheduled_start) — a second generator pass hitting the same
 * occurrence loses the INSERT race and moves on (NN-02).
 *
 * Snapshots: each generated round freezes plan.cps_json + checkpoint
 * names/profiles/windows into round_cp rows — later plan edits never
 * retroactively change a live or past round (spec: plan snapshot integrity).
 */
class SchedulingService
{
	private const HORIZON_DAYS = 7;

	public function __construct(
		private readonly RoundMapper $rounds,
		private readonly RoundCheckpointMapper $roundCps,
		private readonly CheckEventMapper $events,
		private readonly TourPlanMapper $plans,
		private readonly CheckpointService $checkpoints,
		private readonly SiteService $sites,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/** Generate occurrences for all active plans within the horizon. Called by cron. */
	public function generateAll(): int
	{
		$count = 0;
		foreach ($this->plans->findActive() as $plan) {
			$count += $this->generateForPlan($plan);
		}
		return $count;
	}

	/**
	 * Plan-edit resync, delete phase — call INSIDE the plan-update txn.
	 * Spec: "SCHEDULED-not-started rounds resync to the new plan revision."
	 * Deletes eligible pending rounds + their snapshot rows; regeneration
	 * is a separate step (generateForPlan after commit) so a unique-key
	 * collision can't poison the update transaction.
	 *
	 * Frozen by design (evidence beats convenience):
	 *  - ACTIVE/past rounds keep their snapshot (GA-5, NN-10);
	 *  - a scheduled round an early scan already attached to keeps its
	 *    snapshot — recomputeStatus() flips it ACTIVE on next ingest anyway;
	 *  - the FOR UPDATE row lock serializes against event attach
	 *    (EventService locks the same row before inserting an event).
	 *
	 * @return int number of rounds deleted
	 */
	public function deleteStaleScheduledRounds(int $planId): int
	{
		$deleted = 0;
		foreach ($this->rounds->findPendingResync($planId) as $round) {
			$roundId = (int)$round->getId();
			$locked = $this->rounds->findForUpdate($roundId);
			if ($locked === null
				|| $locked->getStatus() !== Constants::ROUND_SCHEDULED
				|| $locked->getStartedAt() !== null) {
				continue;
			}
			if ($this->events->countForRound($roundId) > 0) {
				continue;
			}
			if ($this->rounds->deleteIfPending($roundId)) {
				$this->roundCps->deleteForRound($roundId);
				$deleted++;
			}
		}
		return $deleted;
	}

	public function generateForPlan(TourPlan $plan): int
	{
		$rec = json_decode((string)$plan->getRecurrenceJson(), true);
		$cps = json_decode((string)$plan->getCpsJson(), true);
		if (!is_array($rec) || !is_array($cps) || $cps === []) {
			return 0;
		}
		$site = $this->sites->findById((int)$plan->getSiteId());
		if ($site === null || (int)$site->getActive() !== 1) {
			return 0;
		}
		$tz = new \DateTimeZone($site->getTz());
		$now = $this->timeFactory->getDateTime();
		$starts = $this->computeStarts($rec, $now, $tz);
		$created = 0;
		foreach ($starts as $start) {
			if ($this->createOccurrence($plan, $cps, $start, $rec)) {
				$created++;
			}
		}
		return $created;
	}

	/** @return list<\DateTimeImmutable> candidate start times (site tz, then converted to UTC for storage) */
	private function computeStarts(array $rec, \DateTimeInterface $now, \DateTimeZone $tz): array
	{
		$localNow = \DateTimeImmutable::createFromInterface($now)->setTimezone($tz);
		$starts = [];
		if ($rec['type'] === 'daily' || $rec['type'] === 'weekly') {
			[$h, $m] = array_map('intval', explode(':', (string)$rec['time']));
			for ($d = 0; $d <= self::HORIZON_DAYS; $d++) {
				$day = $localNow->modify('+' . $d . ' days')->setTime($h, $m);
				if ($day <= $localNow) {
					continue; // no retro-active occurrences
				}
				if ($rec['type'] === 'weekly' && !in_array((int)$day->format('w'), $rec['days'], true)) {
					continue;
				}
				$starts[] = $day->setTimezone(new \DateTimeZone('UTC'));
			}
			return $starts;
		}
		// interval
		$anchor = new \DateTimeImmutable((string)$rec['anchor']);
		$step = new \DateInterval('PT' . (int)$rec['interval_hours'] . 'H');
		$until = $localNow->modify('+' . self::HORIZON_DAYS . ' days');
		$cur = $anchor->setTimezone(new \DateTimeZone('UTC'));
		$guard = 0;
		while ($cur <= $until && $guard++ < 2000) {
			if ($cur > \DateTimeImmutable::createFromInterface($now)) {
				$starts[] = $cur;
			}
			$cur = $cur->add($step);
		}
		return $starts;
	}

	/** @return bool true when a new round row was created */
	private function createOccurrence(TourPlan $plan, array $cps, \DateTimeImmutable $start, array $rec): bool
	{
		$now = $this->timeFactory->getDateTime();
		$windowEnd = $start->add(new \DateInterval('PT' . (int)$rec['duration_min'] . 'M'));

		$this->db->beginTransaction();
		try {
			$round = new Round();
			$round->setPlanId((int)$plan->getId());
			$round->setSiteId((int)$plan->getSiteId());
			$round->setSnapshotJson(json_encode(['plan' => ['id' => (int)$plan->getId(), 'name' => $plan->getName(), 'revision' => (int)$plan->getRevision()], 'cps' => $cps, 'recurrence' => $rec]));
			$round->setScheduledStart(\DateTime::createFromImmutable($start));
			$round->setWindowEnd(\DateTime::createFromImmutable($windowEnd));
			$round->setStatus(Constants::ROUND_SCHEDULED);
			$round->setCreatedAt($now);
			$round->setUpdatedAt($now);
			$round = $this->rounds->insert($round);

			$sort = 0;
			foreach ($cps as $stop) {
				$cp = $this->checkpoints->getAny((int)$stop['checkpoint_id']);
				if ($cp === null) {
					continue; // checkpoint archived between plan edit and generation — snapshot keeps name anyway
				}
				$due = $start->add(new \DateInterval('PT' . (int)$stop['offset_min'] . 'M'));
				$rcp = new RoundCheckpoint();
				$rcp->setRoundId((int)$round->getId());
				$rcp->setCheckpointId((int)$cp->getId());
				$rcp->setNameSnap((string)$cp->getName());
				$rcp->setSort($sort++);
				$rcp->setEarliest(\DateTime::createFromImmutable($due->sub(new \DateInterval('PT' . (int)$stop['before_min'] . 'M'))));
				$rcp->setDue(\DateTime::createFromImmutable($due));
				$rcp->setLatest(\DateTime::createFromImmutable($due->add(new \DateInterval('PT' . (int)$stop['after_min'] . 'M'))));
				$rcp->setProfileJson($cp->getProfileJson());
				$rcp->setStatus('pending');
				$this->roundCps->insert($rcp);
			}
			$this->db->commit();
			return true;
		} catch (DbalException $e) {
			$this->db->rollBack();
			if ($e->getReason() === DbalException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false; // occurrence already exists — idempotent replay
			}
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
