<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<RoundCheckpoint> */
class RoundCheckpointMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_round_cp', RoundCheckpoint::class);
	}

	/** @return list<RoundCheckpoint> */
	public function findForRound(int $roundId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)))
			->orderBy('sort', 'ASC');
		return $this->findEntities($qb);
	}

	public function findForRoundAndCheckpoint(int $roundId, int $checkpointId): ?RoundCheckpoint
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)))
			->andWhere($qb->expr()->eq('checkpoint_id', $qb->createNamedParameter($checkpointId)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function countPendingForRound(int $roundId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'missed'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Bulk delete of a round's snapshot rows — plan-edit resync only. */
	public function deleteForRound(int $roundId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)));
		$qb->executeStatement();
	}
}
