<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/** @template-extends QBMapper<AlertDelivery> */
class AlertDeliveryMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_alert_delivery', AlertDelivery::class);
	}

	/**
	 * Claim a leg: returns true when the insert landed (this caller owns the
	 * dispatch), false when the row already existed (another worker owns it
	 * or it was already sent).
	 */
	public function claim(int $alertId, int $legNo, string $channel, \DateTimeInterface $now): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->setValue('alert_id', $qb->createNamedParameter($alertId))
			->setValue('leg_no', $qb->createNamedParameter($legNo))
			->setValue('channel', $qb->createNamedParameter($channel))
			->setValue('status', $qb->createNamedParameter('pending'))
			->setValue('attempt', $qb->createNamedParameter(0))
			->setValue('created_at', $qb->createNamedParameter($now, 'datetime'));
		try {
			$qb->executeStatement();
			return true;
		} catch (Exception $e) {
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
	}

	public function markSent(int $alertId, int $legNo, \DateTimeInterface $at): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('sent'))
			->set('sent_at', $qb->createNamedParameter($at, 'datetime'))
			->set('attempt', $qb->expr()->literal('attempt + 1'));
		$qb->where($qb->expr()->eq('alert_id', $qb->createNamedParameter($alertId)))
			->andWhere($qb->expr()->eq('leg_no', $qb->createNamedParameter($legNo)));
		$qb->executeStatement();
	}

	public function bumpAttempt(int $alertId, int $legNo): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('attempt', $qb->expr()->literal('attempt + 1'))
			->where($qb->expr()->eq('alert_id', $qb->createNamedParameter($alertId)))
			->andWhere($qb->expr()->eq('leg_no', $qb->createNamedParameter($legNo)));
		$qb->executeStatement();
	}

	/** @return list<int> leg numbers already claimed/sent for an alert */
	public function claimedLegs(int $alertId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('leg_no')->from($this->getTableName())
			->where($qb->expr()->eq('alert_id', $qb->createNamedParameter($alertId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sent')));
		return array_map('intval', array_column($qb->executeQuery()->fetchAll(), 'leg_no'));
	}
}
