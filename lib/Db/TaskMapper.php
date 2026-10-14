<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Task> */
class TaskMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_task', Task::class);
	}

	public function find(int $id): ?Task
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

	public function findForUpdate(int $id): ?Task
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

	/** @return list<Task> */
	public function findForSite(int $siteId, ?string $status = null, int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('due_at', 'ASC')->addOrderBy('id', 'DESC')
			->setMaxResults(min(500, max(1, $limit)));
		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		return $this->findEntities($qb);
	}

	/** @return list<Task> open tasks for one worker */
	public function findOpenForAssignee(string $uid, int $limit = 100): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('assignee_uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->orderBy('due_at', 'ASC')
			->setMaxResults(min(500, max(1, $limit)));
		return $this->findEntities($qb);
	}

	/** Open tasks past due — used by the ops board overdue flag. @return list<Task> */
	public function findOverdue(int $siteId, \DateTimeInterface $now): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->lt('due_at', $qb->createNamedParameter($now, 'datetime')));
		return $this->findEntities($qb);
	}
}
