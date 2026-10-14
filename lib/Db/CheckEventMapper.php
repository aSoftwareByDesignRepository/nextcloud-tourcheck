<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<CheckEvent> */
class CheckEventMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_event', CheckEvent::class);
	}

	public function find(int $id): ?CheckEvent
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

	public function findByClientEventId(string $clientEventId): ?CheckEvent
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('client_event_id', $qb->createNamedParameter($clientEventId)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<CheckEvent> */
	public function findForRound(int $roundId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)))
			->andWhere($qb->expr()->eq('tombstoned', $qb->createNamedParameter(0)))
			->orderBy('server_received_at', 'ASC');
		return $this->findEntities($qb);
	}

	/** Live events attached to a round — resync freeze check (tombstones don't count). */
	public function countForRound(int $roundId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)))
			->andWhere($qb->expr()->eq('tombstoned', $qb->createNamedParameter(0)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<CheckEvent> */
	public function findForSite(int $siteId, int $limit, int $offset): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('server_received_at', 'DESC')
			->setMaxResults($limit)->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/** Events in a period — reports/SLA. @return list<CheckEvent> */
	public function findForSiteBetween(int $siteId, \DateTimeInterface $from, \DateTimeInterface $to): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->gte('server_received_at', $qb->createNamedParameter($from, 'datetime')))
			->andWhere($qb->expr()->lt('server_received_at', $qb->createNamedParameter($to, 'datetime')))
			->orderBy('server_received_at', 'ASC')
			->setMaxResults(20000);
		return $this->findEntities($qb);
	}
}
