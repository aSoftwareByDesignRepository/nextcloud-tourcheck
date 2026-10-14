<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Station> */
class StationMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_station', Station::class);
	}

	public function find(int $id): ?Station
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

	public function findBySecretHash(string $hash): ?Station
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('secret_hash', $qb->createNamedParameter($hash)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByPairingHash(string $hash): ?Station
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('pairing_code_hash', $qb->createNamedParameter($hash)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Station> */
	public function findForSite(int $siteId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('label', 'ASC');
		return $this->findEntities($qb);
	}

	public function countActive(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Atomic station-level fail-count bump (member-id probing must not escape lockout). */
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
