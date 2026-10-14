<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<JournalEntry> */
class JournalEntryMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_journal', JournalEntry::class);
	}

	public function findHead(int $siteId): ?JournalEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('seq', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<JournalEntry> ordered by seq ASC */
	public function findAllForSite(int $siteId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('seq', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<JournalEntry> */
	public function findPage(int $siteId, ?string $kind, ?int $roundId, int $limit, int $offset): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('seq', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		if ($kind !== null && $kind !== '') {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}
		if ($roundId !== null) {
			$qb->andWhere($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)));
		}
		return $this->findEntities($qb);
	}

	public function countForSite(int $siteId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Latest entry before a seq — for anchor windows and purge ranges. */
	public function countSinceSeq(int $siteId, int $seq): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->gt('seq', $qb->createNamedParameter($seq)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
