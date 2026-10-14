<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Round> */
class RoundMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_round', Round::class);
	}

	public function find(int $id): ?Round
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Row lock — SELECT ... FOR UPDATE inside the caller's transaction. */
	public function findForUpdate(int $id): ?Round
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->forUpdate();
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Non-terminal rounds on a site whose envelope could contain client_ts:
	 * scheduled_start - 24h ≤ client_ts ≤ window_end. Early scans attach and
	 * are flagged; stale client_ts misses window_end and becomes an orphan.
	 * @return list<Round>
	 */
	public function findAttachable(int $siteId, \DateTimeInterface $clientTs): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['scheduled', 'active', 'missed'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('scheduled_start', $qb->createNamedParameter((new \DateTime())->setTimestamp($clientTs->getTimestamp() - 86400), 'datetime')))
			->andWhere($qb->expr()->lte('scheduled_start', $qb->createNamedParameter((new \DateTime())->setTimestamp($clientTs->getTimestamp() + 86400), 'datetime')))
			->andWhere($qb->expr()->gte('window_end', $qb->createNamedParameter($clientTs, 'datetime')))
			->orderBy('scheduled_start', 'ASC')
			->setMaxResults(10);
		return $this->findEntities($qb);
	}

	/**
	 * Worker "today" list: scheduled/active/missed rounds on the given sites
	 * (or all when $unrestricted), window not older than 24 h, near-future.
	 * @param list<int> $siteIds
	 * @return list<Round>
	 */
	public function findOpen(array $siteIds, bool $unrestricted, \DateTimeInterface $now): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(['scheduled', 'active', 'missed'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('window_end', $qb->createNamedParameter((new \DateTime())->setTimestamp($now->getTimestamp() - 86400), 'datetime')))
			->andWhere($qb->expr()->lte('scheduled_start', $qb->createNamedParameter((new \DateTime())->setTimestamp($now->getTimestamp() + 7 * 86400), 'datetime')))
			->orderBy('scheduled_start', 'ASC')
			->setMaxResults(200);
		if (!$unrestricted) {
			if ($siteIds === []) {
				return [];
			}
			$qb->andWhere($qb->expr()->in('site_id', $qb->createNamedParameter(array_map('intval', $siteIds), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		}
		return $this->findEntities($qb);
	}

	/** @return list<Round> */
	/** @return list<Round> rounds of a site whose scheduled_start falls in [from,to) */
	public function findScheduledBetween(int $siteId, \DateTimeInterface $from, \DateTimeInterface $to): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->gte('scheduled_start', $qb->createNamedParameter($from, 'datetime')))
			->andWhere($qb->expr()->lt('scheduled_start', $qb->createNamedParameter($to, 'datetime')))
			->orderBy('scheduled_start', 'ASC');
		return $this->findEntities($qb);
	}

	public function findForSite(int $siteId, ?string $status, int $limit = 100, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('scheduled_start', 'DESC')
			->setMaxResults($limit)->setFirstResult($offset);
		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * NH-07: ACTIVE rounds whose plan defines a dead-man check-in interval.
	 * @return list<Round>
	 */
	public function findActiveWithCheckin(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.*')->from($this->getTableName(), 'r')
			->innerJoin('r', 'tck_plan', 'p', 'p.id = r.plan_id')
			->where($qb->expr()->eq('r.status', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->isNotNull('p.checkin_interval_min'))
			->andWhere($qb->expr()->gt('p.checkin_interval_min', $qb->createNamedParameter(0)))
			->setMaxResults(200);
		return $this->findEntities($qb);
	}

	/** Window passed, still open status — the missed-detection sweep. @return list<Round> */
	public function findOverdue(\DateTimeInterface $now): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->lt('window_end', $qb->createNamedParameter($now, 'datetime')))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['scheduled', 'active'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(500);
		return $this->findEntities($qb);
	}

	/**
	 * Plan-edit resync candidates: SCHEDULED and never started. Rounds an
	 * early scan already attached to stay in the list — the caller checks
	 * tck_event per round and freezes evidence-bearing rounds instead.
	 * @return list<Round>
	 */
	public function findPendingResync(int $planId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('plan_id', $qb->createNamedParameter($planId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('scheduled')))
			->andWhere($qb->expr()->isNull('started_at'))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Conditional delete — only when the row is still SCHEDULED and not
	 * started. Guards the plan-edit vs. round-start/event-attach race: a
	 * round that transitioned between select and delete is kept.
	 */
	public function deleteIfPending(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('scheduled')))
			->andWhere($qb->expr()->isNull('started_at'));
		return $qb->executeStatement() === 1;
	}
}
