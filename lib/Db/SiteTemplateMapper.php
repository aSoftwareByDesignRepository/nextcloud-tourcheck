<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<SiteTemplate> */
class SiteTemplateMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_template', SiteTemplate::class);
	}

	public function findByKey(string $tkey): ?SiteTemplate
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tkey', $qb->createNamedParameter($tkey)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<SiteTemplate> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('vertical', 'ASC')->addOrderBy('name', 'ASC');
		return $this->findEntities($qb);
	}
}
