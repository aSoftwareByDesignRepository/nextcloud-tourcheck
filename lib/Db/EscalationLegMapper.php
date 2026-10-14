<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<EscalationLeg> */
class EscalationLegMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_escalation_leg', EscalationLeg::class);
	}

	/** @return list<EscalationLeg> legs in firing order */
	public function findForSite(int $siteId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('leg_no', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForSite(int $siteId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)));
		$qb->executeStatement();
	}
}
