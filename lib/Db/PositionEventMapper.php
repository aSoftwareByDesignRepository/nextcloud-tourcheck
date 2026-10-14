<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<PositionEvent> */
class PositionEventMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_position', PositionEvent::class);
	}

	/** @return list<PositionEvent> */
	public function findForRound(int $roundId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)))
			->orderBy('server_received_at', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteExpired(\DateTimeInterface $now): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, 'datetime')));
		return $qb->executeStatement();
	}
}
