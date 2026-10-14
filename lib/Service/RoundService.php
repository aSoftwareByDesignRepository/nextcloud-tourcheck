<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundCheckpoint;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbalException;
use OCP\IDBConnection;

/**
 * Round lifecycle. LOCK ORDER (deadlock rule, enforced app-wide):
 *   round row lock  →  chain-head lock
 * Never the reverse. `recomputeStatus` is the ONLY writer of round.status.
 */
class RoundService
{
	public function __construct(
		private readonly RoundMapper $rounds,
		private readonly RoundCheckpointMapper $roundCps,
		private readonly CheckEventMapper $events,
		private readonly AccessControlService $acl,
		private readonly ChainService $chain,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
	) {
	}

	/** Internal read, no ACL — jobs/ingest only. */
	public function findById(int $roundId): ?Round
	{
		return $this->rounds->find($roundId);
	}

	/**
	 * Today's rounds visible to $uid — fail-closed: a caller with no site
	 * memberships gets an empty list unless they hold the admin/planner
	 * privilege (the only unrestricted scope). Single home for the idiom
	 * shared by the web API and the companion app.
	 */
	public function todayForUser(string $uid): array
	{
		$siteIds = $this->acl->visibleSiteIds($uid);
		$unrestricted = $siteIds === [] && ($this->acl->isAppAdmin($uid) || $this->acl->canPlan($uid));
		return $this->todayFor($siteIds, $unrestricted);
	}

	public function getVisible(int $roundId): Round
	{
		$round = $this->rounds->find($roundId);
		if ($round === null) {
			throw new NotFoundException();
		}
		$this->acl->assertCanViewSite((int)$round->getSiteId());
		return $round;
	}

	/** Round row lock — call inside an open transaction, BEFORE any chain append. */
	public function lockRound(int $roundId): Round
	{
		$round = $this->rounds->findForUpdate($roundId);
		if ($round === null) {
			throw new NotFoundException();
		}
		return $round;
	}

	/** @return list<RoundCheckpoint> */
	public function checkpointsOf(int $roundId): array
	{
		return $this->roundCps->findForRound($roundId);
	}

	/**
	 * Attach a captured event to the right round+stop.
	 *
	 * Candidates: non-terminal rounds on the site that contain the checkpoint,
	 * whose [earliest-of-first-stop, window_end] envelope could contain
	 * client_ts. Tie-breaks: explicit round_id > window containment >
	 * soonest scheduled_start. >1 after all that → 422 AMBIGUOUS (companion
	 * shows the picker — the ONLY time a worker picks a round).
	 *
	 * @return array{0: ?Round, 1: ?RoundCheckpoint} null pair = orphan (no candidate)
	 */
	public function attach(int $siteId, int $checkpointId, \DateTimeInterface $clientTs, ?int $explicitRoundId = null): array
	{
		if ($explicitRoundId !== null) {
			$round = $this->rounds->find($explicitRoundId);
			if ($round === null || (int)$round->getSiteId() !== $siteId
				|| in_array($round->getStatus(), [Constants::ROUND_COMPLETED, Constants::ROUND_SKIPPED], true)) {
				throw new NotFoundException();
			}
			$rcp = $this->roundCps->findForRoundAndCheckpoint($explicitRoundId, $checkpointId);
			if ($rcp === null) {
				throw new DomainException('no_checkpoint', 'This checkpoint is not part of the round.', 422);
			}
			if ($rcp->getStatus() !== 'pending' && $rcp->getStatus() !== 'missed') {
				throw new DomainException('already_confirmed', 'Checkpoint already confirmed.', 409);
			}
			return [$round, $rcp];
		}

		$candidates = $this->rounds->findAttachable($siteId, $clientTs);
		$matching = [];
		foreach ($candidates as $round) {
			$rcp = $this->roundCps->findForRoundAndCheckpoint((int)$round->getId(), $checkpointId);
			if ($rcp === null || ($rcp->getStatus() !== 'pending' && $rcp->getStatus() !== 'missed')) {
				continue;
			}
			$matching[] = [$round, $rcp];
		}
		if ($matching === []) {
			return [null, null]; // orphan — caller decides
		}
		// Prefer candidates whose per-checkpoint window contains client_ts.
		$inWindow = array_values(array_filter($matching, static function ($m) use ($clientTs) {
			$rcp = $m[1];
			$earliest = $rcp->getEarliest();
			$latest = $rcp->getLatest();
			$afterOpen = $earliest === null || $clientTs >= $earliest;
			$beforeClose = $latest === null || $clientTs <= $latest;
			return $afterOpen && $beforeClose;
		}));
		$pool = $inWindow !== [] ? $inWindow : $matching;
		if (count($pool) === 1) {
			return $pool[0];
		}
		usort($pool, static fn ($a, $b) => $a[0]->getScheduledStart() <=> $b[0]->getScheduledStart());
		throw new DomainException(
			'ambiguous_round',
			'Multiple rounds match. Pick the round.',
			422,
		);
	}

	/**
	 * THE single writer of round.status. Derives status from round_cp rows +
	 * clock. Call inside a transaction holding the round lock. Every status
	 * transition is journaled (US-108: the Wachbuch reflects status changes).
	 */
	public function recomputeStatus(int $roundId): void
	{
		$round = $this->rounds->findForUpdate($roundId);
		if ($round === null) {
			return;
		}
		if ($round->getSkipReason() !== null) {
			$this->setStatusIfChanged($round, Constants::ROUND_SKIPPED);
			return;
		}
		$cps = $this->roundCps->findForRound($roundId);
		$now = $this->timeFactory->getDateTime();
		$pending = 0;
		foreach ($cps as $cp) {
			if ($cp->getStatus() === 'pending' || $cp->getStatus() === 'missed') {
				$pending++;
			}
		}
		if ($cps !== [] && $pending === 0) {
			// 'skipped' cps only arise from closeEarly — a round reporting
			// 'completed' while half its checkpoints went undone is a lie the
			// SLA report would count as attainment (spec status vocabulary).
			$anySkipped = false;
			foreach ($cps as $cp) {
				if ($cp->getStatus() === 'skipped') {
					$anySkipped = true;
					break;
				}
			}
			$this->setStatusIfChanged($round, $anySkipped ? Constants::ROUND_PARTIAL : Constants::ROUND_COMPLETED);
			if ($round->getCompletedAt() === null) {
				$round->setCompletedAt($now);
				$this->rounds->update($round);
			}
			return;
		}
		if ($now > $round->getWindowEnd()) {
			$this->setStatusIfChanged($round, Constants::ROUND_MISSED);
			return;
		}
		if ($now >= $round->getScheduledStart() || $this->hasAnyEvent($roundId)) {
			$this->setStatusIfChanged($round, Constants::ROUND_ACTIVE);
			return;
		}
		$this->setStatusIfChanged($round, Constants::ROUND_SCHEDULED);
	}

	private function setStatusIfChanged(Round $round, string $status, ?string $actorUid = null): void
	{
		if ($round->getStatus() === $status) {
			return;
		}
		$from = (string)$round->getStatus();
		$round->setStatus($status);
		$round->setUpdatedAt($this->timeFactory->getDateTime());
		$this->rounds->update($round);
		$this->chain->append((int)$round->getSiteId(), [
			'roundId' => (int)$round->getId(),
			'kind' => Constants::KIND_ADMIN,
			'actorUid' => $actorUid,
			'body' => 'round_status:' . $from . '→' . $status,
			'payloadRef' => 'round:' . $round->getId(),
			'payloadFields' => ['round' => (int)$round->getId(), 'from' => $from, 'to' => $status],
		]);
	}

	private function hasAnyEvent(int $roundId): bool
	{
		return $this->events->countForRound($roundId) > 0;
	}

	/**
	 * Worker-facing early close (UX-3 "Runde abschließen"): remaining pending
	 * checkpoints become 'skipped', status recomputes to completed.
	 * One transaction: round lock → cp updates → recompute → journal.
	 */
	public function closeEarly(int $roundId): Round
	{
		$round = $this->rounds->find($roundId);
		if ($round === null) {
			throw new NotFoundException();
		}
		$uid = $this->acl->assertCanViewSite((int)$round->getSiteId());
		$this->db->beginTransaction();
		try {
			$round = $this->lockRound($roundId);
			// Only an in-progress round may be closed early. Closing a
			// SCHEDULED round would skip every checkpoint before it started
			// and fabricate a 'partial' that never ran — a supervisor cancels
			// planned rounds via skip() instead.
			if ($round->getStatus() !== Constants::ROUND_ACTIVE) {
				throw new DomainException(Constants::ERR_ROUND_CLOSED, 'Round is not active.', 409);
			}
			$closedCount = 0;
			foreach ($this->roundCps->findForRound($roundId) as $rcp) {
				if ($rcp->getStatus() === 'pending' || $rcp->getStatus() === 'missed') {
					$rcp->setStatus('skipped');
					$this->roundCps->update($rcp);
					$closedCount++;
				}
			}
			$this->recomputeStatus($roundId);
			$round = $this->rounds->findForUpdate($roundId);
			if (in_array($round->getStatus(), [Constants::ROUND_COMPLETED, Constants::ROUND_PARTIAL], true) && $round->getCompletedBy() === null) {
				$round->setCompletedBy($uid);
				$this->rounds->update($round);
			}
			$this->chain->append((int)$round->getSiteId(), [
				'roundId' => $roundId,
				'kind' => Constants::KIND_ADMIN,
				'actorUid' => $uid,
				'body' => 'round closed early: ' . $closedCount . ' checkpoint(s) skipped',
				'payloadRef' => 'round:' . $roundId,
				'payloadFields' => ['round' => $roundId, 'skipped_cps' => $closedCount],
			]);
			$this->db->commit();
			return $round;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Planner/supervisor marks a round skipped (holiday, site closed). */
	public function skip(int $roundId, string $reason): Round
	{
		$round = $this->getVisible($roundId);
		$uid = $this->acl->assertCanSuperviseSite((int)$round->getSiteId());
		$reason = mb_substr(trim($reason), 0, 500);
		$this->db->beginTransaction();
		try {
			$round = $this->lockRound($roundId);
			// Only a round that has not produced work may be cancelled.
			// Relabelling completed/partial rewrites attainment into "planned
			// non-run", 'missed'→'skipped' whitewashes a no-show, and an
			// active round with events hides work already done — all of it
			// must go through closeEarly (→ partial) instead.
			$status = $round->getStatus();
			if (!in_array($status, [Constants::ROUND_SCHEDULED, Constants::ROUND_ACTIVE], true)
				|| ($status === Constants::ROUND_ACTIVE && $this->hasAnyEvent($roundId))) {
				throw new DomainException(Constants::ERR_ROUND_CLOSED, 'Round already finished or has work recorded.', 409);
			}
			// recomputeStatus is the single writer of round.status — skip sets
			// the reason flag and lets the derivation produce ROUND_SKIPPED.
			$round->setSkipReason($reason !== '' ? $reason : 'skipped');
			$this->rounds->update($round);
			$this->recomputeStatus($roundId);
			$this->chain->append((int)$round->getSiteId(), [
				'roundId' => $roundId,
				'kind' => Constants::KIND_ADMIN,
				'actorUid' => $uid,
				'body' => 'round_skipped:' . $reason,
				'payloadRef' => 'round:' . $roundId,
				'payloadFields' => ['round' => $roundId, 'reason' => $reason],
			]);
			$this->db->commit();
			return $this->rounds->find($roundId) ?? $round;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Rounds visible on the worker "today" surface: scheduled/active on
	 * visible sites, window not long past.
	 * @return list<Round>
	 */
	public function todayFor(array $siteIds, bool $unrestricted): array
	{
		return $this->rounds->findOpen($siteIds, $unrestricted, $this->timeFactory->getDateTime());
	}

	/**
	 * Web Rounds overview — every status inside ±14 days around now.
	 * Unlike todayFor() this includes finished/skipped rounds: the web
	 * list is the record view and must not dead-end at "today".
	 * @param list<int> $siteIds
	 * @return list<Round>
	 */
	public function inWindow(array $siteIds, bool $unrestricted): array
	{
		$now = $this->timeFactory->getDateTime();
		$from = (new \DateTime())->setTimestamp($now->getTimestamp() - 14 * 86400);
		$to = (new \DateTime())->setTimestamp($now->getTimestamp() + 14 * 86400);
		return $this->rounds->findInWindow($siteIds, $unrestricted, $from, $to);
	}

	/** Finished rounds (completed/partial) since $since — dashboard "Done" tile. */
	public function countFinishedSince(array $siteIds, bool $unrestricted, \DateTimeInterface $since): int
	{
		return $this->rounds->countFinishedSince($siteIds, $unrestricted, $since);
	}

	/** @return list<Round> */
	public function listForSite(int $siteId, ?string $status, int $limit, int $offset): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->rounds->findForSite($siteId, $status, $limit, $offset);
	}

	/** Rounds whose window has closed and which still have pending cps — missed-detection sweep. @return list<Round> */
	public function findOverdue(): array
	{
		return $this->rounds->findOverdue($this->timeFactory->getDateTime());
	}

	/** NH-07: active rounds on plans with a dead-man interval. */
	public function findActiveWithCheckin(): array
	{
		return $this->rounds->findActiveWithCheckin();
	}
}
