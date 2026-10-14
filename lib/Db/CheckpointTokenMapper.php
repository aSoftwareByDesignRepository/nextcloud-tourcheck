<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<CheckpointToken> */
class CheckpointTokenMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_cp_token', CheckpointToken::class);
	}

	public function findActiveByHash(string $hash): ?CheckpointToken
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($hash)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Any row for a hash — active or revoked. Failure-path only: lets the
	 * anomaly service attribute rotated-tag scans to their site (GA-3).
	 */
	public function findAnyByHash(string $hash): ?CheckpointToken
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($hash)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<CheckpointToken> */
	public function findActiveForCheckpoints(array $checkpointIds): array
	{
		if ($checkpointIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('checkpoint_id', $qb->createNamedParameter(array_map('intval', $checkpointIds), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		return $this->findEntities($qb);
	}

	/** @return list<CheckpointToken> */
	public function findActiveForCheckpoint(int $checkpointId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('checkpoint_id', $qb->createNamedParameter($checkpointId)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		return $this->findEntities($qb);
	}
}
