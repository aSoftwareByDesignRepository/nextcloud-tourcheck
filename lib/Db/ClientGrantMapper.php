<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<ClientGrant> */
class ClientGrantMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_client_grant', ClientGrant::class);
	}

	/** @return list<int> site ids the client may currently see */
	public function siteIdsFor(string $clientUid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('site_id')->from($this->getTableName())
			->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($clientUid)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		return array_map('intval', array_column($qb->executeQuery()->fetchAll(), 'site_id'));
	}

	public function hasAccess(string $clientUid, int $siteId): bool
	{
		return in_array($siteId, $this->siteIdsFor($clientUid), true);
	}

	/** @return list<ClientGrant> active grants for a site (admin list) */
	public function findForSite(int $siteId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		return $this->findEntities($qb);
	}

	public function find(string $clientUid, int $siteId): ?ClientGrant
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('client_uid', $qb->createNamedParameter($clientUid)))
			->andWhere($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}
}
