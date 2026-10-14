<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Logbook> */
class LogbookMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_logbook', Logbook::class);
	}

	public function find(int $id): ?Logbook
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Logbook> pinned first, then newest */
	public function findForSite(int $siteId, int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('pinned', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults(min(500, max(1, $limit)));
		return $this->findEntities($qb);
	}

	/** @return list<string> uids that acked this entry */
	public function acksFor(int $logbookId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('acker_uid')->from('tck_logbook_ack')
			->where($qb->expr()->eq('logbook_id', $qb->createNamedParameter($logbookId)));
		return array_column($qb->executeQuery()->fetchAll(), 'acker_uid');
	}

	public function insertAck(int $logbookId, string $uid, \DateTimeInterface $at): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('tck_logbook_ack')
			->setValue('logbook_id', $qb->createNamedParameter($logbookId))
			->setValue('acker_uid', $qb->createNamedParameter($uid))
			->setValue('acked_at', $qb->createNamedParameter($at, 'datetime'));
		try {
			$qb->executeStatement();
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// double-ack is idempotent — the row already exists
		}
	}
}
