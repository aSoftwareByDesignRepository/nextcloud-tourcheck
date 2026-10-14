<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<GeofenceState> */
class GeofenceStateMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'tck_geofence_state', GeofenceState::class);
	}

	public function findFor(string $workerUid, int $geofenceId, bool $forUpdate = false): ?GeofenceState
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('worker_uid', $qb->createNamedParameter($workerUid)))
			->andWhere($qb->expr()->eq('geofence_id', $qb->createNamedParameter($geofenceId)));
		if ($forUpdate) {
			$qb->forUpdate();
		}
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** Workers currently inside this geofence (excluding one uid). */
	public function countInsideExcluding(int $geofenceId, string $excludeUid): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('geofence_id', $qb->createNamedParameter($geofenceId)))
			->andWhere($qb->expr()->eq('inside', $qb->createNamedParameter(1)))
			->andWhere($qb->expr()->neq('worker_uid', $qb->createNamedParameter($excludeUid)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
