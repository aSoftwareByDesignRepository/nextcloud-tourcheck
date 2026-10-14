<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Checkpoint> */
class CheckpointMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_checkpoint', Checkpoint::class);
	}

	public function find(int $id): ?Checkpoint
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

	/** @return list<Checkpoint> */
	public function findForSite(int $siteId, bool $includeInactive = false): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('order_hint', 'ASC')->addOrderBy('name', 'ASC');
		if (!$includeInactive) {
			$qb->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)));
		}
		return $this->findEntities($qb);
	}
}
