<?php

declare(strict_types=1);

namespace OCA\TourCheck\Integration;

use OCA\TourCheck\Db\SchemaProbe;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * NH-15b / F11: read-only pool-vehicle badge ("today's car") sourced from a
 * MobilityCheck install on the same instance. Fail-closed by construction:
 * MC absent/disabled, schema drift, or any query error → null badge, never
 * an error surfaced to the worker. Allow-listed fields only (label, plate,
 * pickup/return window) — no booking purpose, cost centre, or other member
 * data crosses the seam.
 */
final class PoolVehicleService
{
	private const MC_APP_ID = 'mobilitycheck';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool
	{
		try {
			return $this->appManager->isEnabledForUser(self::MC_APP_ID);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @return array{vehicle_label:string,plate:string,pickup:string,return:string}|null
	 */
	public function badgeFor(string $uid, \DateTimeInterface $now): ?array
	{
		if ($uid === '' || !$this->isAvailable()) {
			return null;
		}
		try {
			if (!SchemaProbe::tableExists($this->db, 'mc_bookings')
				|| !SchemaProbe::tableExists($this->db, 'mc_vehicles')) {
				return null;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select('v.internal_name', 'v.licence_plate', 'b.start_datetime', 'b.end_datetime')
				->from('mc_bookings', 'b')
				->innerJoin('b', 'mc_vehicles', 'v', 'v.id = b.vehicle_id')
				->where($qb->expr()->eq('b.driver_user_id', $qb->createNamedParameter($uid)))
				->andWhere($qb->expr()->in('b.status', $qb->createNamedParameter(['approved', 'active'], IQueryBuilder::PARAM_STR_ARRAY)))
				// Today inside the booking window (bookings can span days).
				->andWhere($qb->expr()->lte('b.start_datetime', $qb->createNamedParameter($now, 'datetime')))
				->andWhere($qb->expr()->gte('b.end_datetime', $qb->createNamedParameter($now, 'datetime')))
				->orderBy('b.start_datetime', 'ASC')
				->setMaxResults(1);
			$row = $qb->executeQuery()->fetch();
			if ($row === false) {
				return null;
			}
			$label = trim((string)$row['internal_name']);
			$pickup = $this->fmt($row['start_datetime']);
			$return = $this->fmt($row['end_datetime']);
			if ($label === '' || $pickup === null || $return === null) {
				return null;
			}
			return [
				'vehicle_label' => $label,
				'plate' => (string)$row['licence_plate'],
				'pickup' => $pickup,
				'return' => $return,
			];
		} catch (\Throwable $e) {
			// Fail closed — a broken MC install must never break a round.
			$this->logger->info('tourcheck pool-vehicle badge read failed', ['exception' => $e->getMessage()]);
			return null;
		}
	}

	private function fmt(mixed $v): ?string
	{
		if ($v === null || $v === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable((string)$v))->format('c');
		} catch (\Throwable) {
			return null;
		}
	}
}
