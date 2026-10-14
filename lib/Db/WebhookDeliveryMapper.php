<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<WebhookDelivery> */
class WebhookDeliveryMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_webhook_delivery', WebhookDelivery::class);
	}

	/** @return list<WebhookDelivery> due retries + fresh pending rows */
	public function findDue(\DateTimeInterface $now, int $limit = 50): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('next_retry_at'),
				$qb->expr()->lte('next_retry_at', $qb->createNamedParameter($now, 'datetime'))
			))
			->orderBy('id', 'ASC')
			->setMaxResults(max(1, $limit));
		return $this->findEntities($qb);
	}

	public function findForUpdate(int $id): ?WebhookDelivery
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->forUpdate();
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** @return list<WebhookDelivery> recent deliveries for admin view */
	public function findRecent(int $webhookId, int $limit = 50): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('webhook_id', $qb->createNamedParameter($webhookId)))
			->orderBy('id', 'DESC')
			->setMaxResults(min(200, max(1, $limit)));
		return $this->findEntities($qb);
	}
}
