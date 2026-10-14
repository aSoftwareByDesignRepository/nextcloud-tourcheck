<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @extends QBMapper<KeyLog> */
class KeyLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_key_log', KeyLog::class);
	}

	/** @return list<KeyLog> newest first */
	public function findForSite(int $siteId, int $limit = 100, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(min(500, max(1, $limit)))
			->setFirstResult(max(0, $offset));
		return $this->findEntities($qb);
	}

	/** @return list<KeyLog> */
	public function findForKey(int $keyId, int $limit = 50): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('key_id', $qb->createNamedParameter($keyId)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(min(200, max(1, $limit)));
		return $this->findEntities($qb);
	}
}
