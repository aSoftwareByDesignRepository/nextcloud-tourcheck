<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @extends QBMapper<ManDownState> */
class ManDownStateMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_mandown_state', ManDownState::class);
	}

	public function findForWorker(int $roundId, string $uid): ?ManDownState
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)))
			->andWhere($qb->expr()->eq('worker_uid', $qb->createNamedParameter($uid)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Rows whose inactivity window has expired and that have not alarmed yet.
	 * Bounded sweep set — the phase index keeps this cheap.
	 * @return list<ManDownState>
	 */
	public function findStale(\DateTimeInterface $cutoff, int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('phase', $qb->createNamedParameter('watching')))
			->andWhere($qb->expr()->lt('last_activity_at', $qb->createNamedParameter($cutoff, 'datetime')))
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function closeForRound(int $roundId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('phase', $qb->createNamedParameter('closed'))
			->where($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)))
			->andWhere($qb->expr()->eq('phase', $qb->createNamedParameter('watching')));
		$qb->executeStatement();
	}
}
