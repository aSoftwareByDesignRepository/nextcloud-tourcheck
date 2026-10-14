<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<SiteMember> */
class SiteMemberMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_site_member', SiteMember::class);
	}

	public function find(int $id): ?SiteMember
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

	public function roleFor(int $siteId, string $uid): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('role')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : (string)$row['role'];
	}

	/** @return list<SiteMember> */
	public function findBySite(int $siteId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('uid', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<SiteMember> */
	public function findByUidAndRole(string $uid, string $role): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role)))
			->setMaxResults(1);
		return $this->findEntities($qb);
	}

	/** @return list<int> */
	public function siteIdsForUid(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('site_id')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$res = $qb->executeQuery();
		$ids = [];
		while ($row = $res->fetch()) {
			$ids[] = (int)$row['site_id'];
		}
		$res->closeCursor();
		return $ids;
	}

	public function deleteFor(int $siteId, string $uid): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}
}
