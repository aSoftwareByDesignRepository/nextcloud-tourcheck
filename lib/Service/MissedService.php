<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Missed-detection sweep (cron). For each overdue round: mark pending
 * round_cps missed, recompute round status, dedupe-notify supervisors.
 * Round lock first, chain never held — lock order respected.
 */
class MissedService
{
	public function __construct(
		private readonly RoundService $rounds,
		private readonly NotificationService $notifications,
		private readonly SiteService $sites,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly \OCA\TourCheck\Db\TourPlanMapper $plans,
		private readonly ?\OCA\TourCheck\Db\TckKeyMapper $keys = null,
	) {
	}

	public function sweep(): int
	{
		$count = 0;
		foreach ($this->rounds->findOverdue() as $round) {
			$this->db->beginTransaction();
			try {
				$round = $this->rounds->lockRound((int)$round->getId());
				$now = $this->timeFactory->getDateTime();
				foreach ($this->rounds->checkpointsOf((int)$round->getId()) as $rcp) {
					if ($rcp->getStatus() === 'pending' && $rcp->getLatest() !== null && $now > $rcp->getLatest()) {
						$rcp->setStatus('missed');
						$qb = $this->db->getQueryBuilder();
						$qb->update('tck_round_cp')->set('status', $qb->createNamedParameter('missed'))
							->where($qb->expr()->eq('id', $qb->createNamedParameter($rcp->getId())));
						$qb->executeStatement();
					}
				}
				$this->rounds->recomputeStatus((int)$round->getId());
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			$round = $this->rounds->findById((int)$round->getId());
			if ($round !== null && $round->getStatus() === Constants::ROUND_MISSED) {
				$site = $this->sites->findById((int)$round->getSiteId());
				if ($site !== null) {
					$this->notifications->notifySite(
						(int)$round->getSiteId(),
						'missed:round:' . $round->getId(),
						'round_missed',
						'Round missed: ' . $site->getName(),
						$site,
					);
				}
			}
			$count++;
		}
		$count += $this->sweepDeadman();
		$this->sweepOverdueKeys();
		return $count;
	}

	/**
	 * NH-10 escalation: a key still 'out' after 24 h alerts the site once
	 * per day (dedupe key carries the date) until it comes back.
	 */
	private function sweepOverdueKeys(): void
	{
		if ($this->keys === null) {
			return;
		}
		$threshold = $this->timeFactory->getDateTime()->modify('-24 hours');
		foreach ($this->keys->findOverdue($threshold) as $key) {
			$site = $this->sites->findById((int)$key->getSiteId());
			if ($site === null) {
				continue;
			}
			$this->notifications->notifySite(
				(int)$key->getSiteId(),
				'keyover:' . $key->getId() . ':' . $this->timeFactory->getDateTime()->format('Ymd'),
				'key_overdue',
				'Key "' . $key->getLabel() . '" at ' . $site->getName() . ' has been checked out since ' . ($key->getUpdatedAt()?->format('Y-m-d H:i') ?? '?'),
				$site,
				['key' => (string)$key->getLabel(), 'since' => $key->getUpdatedAt()?->format('Y-m-d H:i') ?? '?'],
			);
		}
	}

	/**
	 * NH-07 dead-man check-in lite: for ACTIVE rounds on plans with a
	 * check-in interval, any evidence heartbeat (check event OR live
	 * position, server-received time) restarts the clock. Each elapsed
	 * interval beyond the last is a new strike — dedupe key carries the
	 * strike level, so strikes escalate instead of re-mailing the same one.
	 * Read-only sweep: nothing here mutates round state (the status stays
	 * ACTIVE — silence is not a round-outcome, it's an alert).
	 */
	private function sweepDeadman(): int
	{
		$now = $this->timeFactory->getDateTime();
		$fired = 0;
		foreach ($this->rounds->findActiveWithCheckin() as $round) {
			$plan = $this->plans->find((int)$round->getPlanId());
			$interval = $plan !== null ? (int)($plan->getCheckinIntervalMin() ?? 0) : 0;
			if ($interval <= 0) {
				continue;
			}
			$last = $round->getStartedAt();
			foreach (['tck_event', 'tck_position'] as $tbl) {
				$q = $this->db->getQueryBuilder();
				$q->select($q->func()->max('server_received_at'))->from($tbl)
					->where($q->expr()->eq('round_id', $q->createNamedParameter($round->getId())));
				$ts = $q->executeQuery()->fetchOne();
				if ($ts !== false && $ts !== null) {
					$dt = new \DateTime((string)$ts);
					if ($last === null || $dt > $last) {
						$last = $dt;
					}
				}
			}
			if ($last === null) {
				continue;
			}
			$elapsed = $now->getTimestamp() - $last->getTimestamp();
			$strikes = intdiv(max(0, $elapsed), $interval * 60);
			if ($strikes < 1) {
				continue;
			}
			$site = $this->sites->findById((int)$round->getSiteId());
			if ($site === null) {
				continue;
			}
			if ($this->notifications->notifySite(
				(int)$round->getSiteId(),
				'deadman:round:' . $round->getId() . ':' . $strikes,
				'deadman_alert',
				'No activity for ' . ($strikes * $interval) . ' min in round ' . $round->getId(),
				$site,
				['minutes' => $strikes * $interval, 'round' => (int)$round->getId()],
			)) {
				$fired++;
			}
		}
		return $fired;
	}
}
