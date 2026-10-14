<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<StationMember> */
class StationMemberMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_station_mbr', StationMember::class);
	}

	public function find(int $id): ?StationMember
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

	/** @return list<StationMember> */
	public function findForStation(int $stationId, bool $activeOnly = true): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('station_id', $qb->createNamedParameter($stationId)))
			->orderBy('display_name', 'ASC');
		if ($activeOnly) {
			$qb->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)));
		}
		return $this->findEntities($qb);
	}
	/**
	 * Atomic fail-count bump — two parallel wrong PINs must not under-count
	 * (read-modify-write loses one increment and delays lockout). Returns
	 * the stored count after the increment.
	 */
	public function bumpFailCount(int $id): int
	{
		$upd = $this->db->getQueryBuilder();
		$upd->update($this->getTableName())
			->set('fail_count', $upd->createFunction('fail_count + 1'))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($id)));
		$upd->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->select('fail_count')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$row = $qb->executeQuery()->fetch();
		return is_array($row) ? (int)$row['fail_count'] : 1;
	}
}
