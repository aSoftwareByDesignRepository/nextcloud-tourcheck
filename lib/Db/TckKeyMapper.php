<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/** @extends QBMapper<TckKey> */
class TckKeyMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_key', TckKey::class);
	}

	public function find(int $id): ?TckKey
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

	/** @return list<TckKey> */
	public function findForSite(int $siteId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('label', 'ASC')
			->setMaxResults(500);
		return $this->findEntities($qb);
	}

	/**
	 * Atomic take: flips in→out + stamps the holder in ONE statement —
	 * two concurrent takes cannot both win (affected-rows CAS).
	 */
	public function atomicTake(int $keyId, ?string $holderUid, ?int $holderMemberId, \DateTimeInterface $now): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('out'))
			->set('holder_uid', $qb->createNamedParameter($holderUid))
			->set('holder_member_id', $qb->createNamedParameter($holderMemberId))
			->set('updated_at', $qb->createNamedParameter($now, 'datetime'))
			->set('revision', $qb->createFunction('revision + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($keyId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('in')));
		return $qb->executeStatement() === 1;
	}

	/** Atomic return: out→in, clears the holder in the same statement. */
	public function atomicReturn(int $keyId, \DateTimeInterface $now): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('in'))
			->set('holder_uid', $qb->createNamedParameter(null))
			->set('holder_member_id', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter($now, 'datetime'))
			->set('revision', $qb->createFunction('revision + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($keyId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('out')));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Keys still out longer than $olderThan — the overdue sweep re-alerts
	 * daily via dedupe key, so 'out since' stays the take timestamp
	 * (updated_at IS the take time; nothing else mutates an 'out' key).
	 * @return list<TckKey>
	 */
	public function findOverdue(\DateTimeInterface $olderThan): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('out')))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($olderThan, 'datetime')))
			->setMaxResults(1000);
		return $this->findEntities($qb);
	}
}
