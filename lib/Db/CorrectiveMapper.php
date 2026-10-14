<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Corrective> */
class CorrectiveMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_corrective', Corrective::class);
	}

	public function find(int $id): ?Corrective
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

	/** @return list<Corrective> */
	public function findForIncident(int $incidentId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('incident_id', $qb->createNamedParameter($incidentId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function countOpenForIncident(int $incidentId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('incident_id', $qb->createNamedParameter($incidentId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['open', 'done'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
