<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Incident> */
class IncidentMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_incident', Incident::class);
	}

	public function find(int $id): ?Incident
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

	public function findByClientId(string $cid): ?Incident
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('client_incident_id', $qb->createNamedParameter($cid)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Incident> */
	public function findForSite(int $siteId, ?string $status, int $limit, int $offset): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit)->setFirstResult($offset);
		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		return $this->findEntities($qb);
	}

	/** @param list<int> $siteIds @return list<Incident> */
	public function findForSites(array $siteIds, ?string $status, int $limit): array
	{
		if ($siteIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('site_id', $qb->createNamedParameter(array_map('intval', $siteIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit);
		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		return $this->findEntities($qb);
	}

	/** Open+in-flight incidents for a site (live-page metric). */
	public function countOpenForSite(int $siteId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('closed')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<Incident> */
	public function findForSiteBetween(int $siteId, \DateTimeInterface $from, \DateTimeInterface $to): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($from, 'datetime')))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($to, 'datetime')))
			->orderBy('created_at', 'ASC')
			->setMaxResults(5000);
		return $this->findEntities($qb);
	}
}
