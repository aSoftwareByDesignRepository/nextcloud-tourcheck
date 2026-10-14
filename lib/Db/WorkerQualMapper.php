<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<WorkerQual> */
class WorkerQualMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_worker_qual', WorkerQual::class);
	}

	/** @return list<WorkerQual> */
	/** True when the worker holds a non-expired qualification. */
	public function hasQual(string $uid, string $qualCode, \DateTimeInterface $now): bool
	{
		foreach ($this->findForUser($uid) as $q) {
			if ($q->getQualCode() !== $qualCode) {
				continue;
			}
			$exp = $q->getExpiresAt();
			if ($exp === null || $exp->getTimestamp() >= $now->getTimestamp()) {
				return true;
			}
		}
		return false;
	}

	public function findForUser(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return $this->findEntities($qb);
	}

	public function deleteFor(int $id): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $qb->executeStatement();
	}
}
