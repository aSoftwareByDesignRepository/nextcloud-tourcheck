<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Geofence;
use OCA\TourCheck\Db\GeofenceMapper;
use OCA\TourCheck\Db\GeofenceState;
use OCA\TourCheck\Db\GeofenceStateMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Support\GeoMath;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Geofences — site perimeter + restricted zones. Transition detection uses
 * hysteresis so GPS jitter cannot storm (US-F1-04 AC-3):
 *   enter commits after 30 s continuously inside (or the first two fixes);
 *   exit  commits after 60 s continuously outside OR a fix >100 m clearly
 *         beyond a circle boundary.
 * Position fixes with accuracy > 200 m are ignored for evaluation — a
 * degraded fix must never flap an episode.
 */
class GeofenceService
{
	public const ENTER_DWELL_S = 30;
	public const EXIT_DWELL_S = 60;
	public const EXIT_CLEAR_M = 100;
	public const MAX_EVAL_ACCURACY_M = 200;

	public function __construct(
		private readonly GeofenceMapper $geofences,
		private readonly GeofenceStateMapper $states,
		private readonly AccessControlService $acl,
		private readonly AlertService $alerts,
		private readonly EventService $events,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
	) {
	}

	/** @return list<Geofence> */
	public function listForSite(int $siteId): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->geofences->findForSite($siteId);
	}

	/** @param list<array{0:float,1:float}>|null $polygon */
	public function create(int $siteId, string $kind, string $shape, ?float $lat, ?float $lon, ?int $radiusM, ?array $polygon): Geofence
	{
		$this->acl->assertCanManageSite($siteId);
		$this->validateShape($kind, $shape, $lat, $lon, $radiusM, $polygon);
		$g = new Geofence();
		$g->setSiteId($siteId);
		$g->setKind($kind);
		$g->setShape($shape);
		$g->setCenterLat($lat);
		$g->setCenterLon($lon);
		$g->setRadiusM($radiusM);
		$g->setPolygonJson($polygon === null ? null : json_encode($polygon));
		$now = $this->timeFactory->getDateTime();
		$g->setCreatedAt($now);
		$g->setUpdatedAt($now);
		return $this->geofences->insert($g);
	}

	public function update(int $id, string $shape, ?float $lat, ?float $lon, ?int $radiusM, ?array $polygon): Geofence
	{
		$g = $this->geofences->find($id);
		if ($g === null) {
			throw new NotFoundException();
		}
		$this->acl->assertCanManageSite((int)$g->getSiteId());
		$this->validateShape((string)$g->getKind(), $shape, $lat, $lon, $radiusM, $polygon);
		$g->setShape($shape);
		$g->setCenterLat($lat);
		$g->setCenterLon($lon);
		$g->setRadiusM($radiusM);
		$g->setPolygonJson($polygon === null ? null : json_encode($polygon));
		$g->setUpdatedAt($this->timeFactory->getDateTime());
		$this->geofences->update($g);
		return $g;
	}

	public function delete(int $id): void
	{
		$g = $this->geofences->find($id);
		if ($g === null) {
			throw new NotFoundException();
		}
		$this->acl->assertCanManageSite((int)$g->getSiteId());
		$this->geofences->delete($g);
	}

	/** @param list<array{0:float,1:float}>|null $polygon */
	private function validateShape(string $kind, string $shape, ?float $lat, ?float $lon, ?int $radiusM, ?array $polygon): void
	{
		$fields = [];
		if (!in_array($kind, ['perimeter', 'restricted'], true)) {
			$fields['kind'] = 'invalid';
		}
		if (!in_array($shape, ['circle', 'polygon'], true)) {
			$fields['shape'] = 'invalid';
		}
		if ($shape === 'circle') {
			if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) {
				$fields['center'] = 'invalid';
			}
			if ($radiusM === null || $radiusM < 10 || $radiusM > 100000) {
				$fields['radiusM'] = 'invalid';
			}
		}
		if ($shape === 'polygon') {
			$ok = is_array($polygon) && count($polygon) >= 3 && count($polygon) <= 200;
			if ($ok) {
				foreach ($polygon as $p) {
					if (!is_array($p) || count($p) !== 2 || !is_numeric($p[0]) || !is_numeric($p[1])
						|| abs((float)$p[0]) > 90 || abs((float)$p[1]) > 180) {
						$ok = false;
						break;
					}
				}
			}
			if (!$ok) {
				$fields['polygon'] = 'invalid';
			}
		}
		if ($fields !== []) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid geofence.', $fields);
		}
	}

	public function contains(Geofence $g, float $lat, float $lon): bool
	{
		if ($g->getShape() === 'circle') {
			return GeoMath::distance($lat, $lon, (float)$g->getCenterLat(), (float)$g->getCenterLon()) <= (float)$g->getRadiusM();
		}
		$poly = json_decode((string)$g->getPolygonJson(), true);
		return is_array($poly) && GeoMath::pointInPolygon($lat, $lon, $poly);
	}

	/**
	 * Evaluate one position fix against every geofence of the site and emit
	 * transition events (journaled + alerts). Called from PositionService on
	 * each accepted ingest — the worker is already consent-gated upstream.
	 * @return list<string> signal names emitted (test-visible)
	 */
	public function evaluate(string $workerUid, int $siteId, int $roundId, float $lat, float $lon, int $accuracyM): array
	{
		if ($accuracyM > self::MAX_EVAL_ACCURACY_M) {
			return []; // degraded fix — never flap episodes on junk GPS
		}
		$emitted = [];
		foreach ($this->geofences->findForSite($siteId) as $g) {
			$inside = $this->contains($g, $lat, $lon);
			$transition = $this->transition((int)$g->getId(), $workerUid, $inside, $g, $lat, $lon);
			if ($transition === 'enter') {
				if ($g->getKind() === 'perimeter') {
					$emitted[] = 'geofence_enter';
					$this->events->journalSignal($siteId, 'geofence_enter', [
						'actor_uid' => $workerUid, 'geofence_id' => (int)$g->getId(), 'round_id' => $roundId,
					]);
				} else {
					$emitted[] = 'zone_violation';
					$this->alerts->fire(Constants::ALERT_ZONE, $siteId, $workerUid, 'high', [
						'geofence_id' => (int)$g->getId(), 'lat' => $lat, 'lon' => $lon,
					], 'zv_' . $g->getId() . '_' . $workerUid . '_' . $roundId . '_' . floor($this->timeFactory->getTime() / 300), $roundId);
				}
			} elseif ($transition === 'exit' && $g->getKind() === 'perimeter') {
				$emitted[] = 'geofence_exit';
				$this->events->journalSignal($siteId, 'geofence_exit', [
					'actor_uid' => $workerUid, 'geofence_id' => (int)$g->getId(), 'round_id' => $roundId,
				]);
				$this->maybeCoverageGap($g, $siteId, $workerUid);
			}
		}
		return $emitted;
	}

	/**
	 * Hysteresis state machine. Returns 'enter' | 'exit' | null.
	 * Transaction + row lock: two concurrent fixes for the same worker would
	 * otherwise both observe "outside" and double-fire the transition.
	 */
	private function transition(int $geofenceId, string $workerUid, bool $inside, Geofence $g, float $lat, float $lon): ?string
	{
		$now = $this->timeFactory->getDateTime();
		$this->db->beginTransaction();
		try {
			// serialize on the state row — upsert-first so the row exists to lock
			$st = $this->states->findFor($workerUid, $geofenceId, true);
			if ($st === null) {
				$st = new GeofenceState();
				$st->setWorkerUid($workerUid);
				$st->setGeofenceId($geofenceId);
				$st->setInside(0);
				$st->setChangedAt($now);
				try {
					$st = $this->states->insert($st);
				} catch (\OCP\DB\Exception $e) {
					// lost the create race — re-read winner under the lock
					if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
						throw $e;
					}
					$st = $this->states->findFor($workerUid, $geofenceId, true);
				}
			}
			$event = null;
			if ($inside === ((int)$st->getInside() === 1)) {
				if ($st->getPendingSince() !== null) {
					$st->setPendingSince(null);
					$this->states->update($st);
				}
			} else {
				if ($st->getPendingSince() === null) {
					$st->setPendingSince($now);
					$this->states->update($st);
				} else {
					$elapsed = $now->getTimestamp() - $st->getPendingSince()->getTimestamp();
					if ($inside) {
						if ($elapsed >= self::ENTER_DWELL_S) {
							$event = 'enter';
						}
					} else {
						$clearlyOut = $g->getShape() === 'circle'
							&& GeoMath::distance($lat, $lon, (float)$g->getCenterLat(), (float)$g->getCenterLon()) > (float)$g->getRadiusM() + self::EXIT_CLEAR_M;
						if ($elapsed >= self::EXIT_DWELL_S || $clearlyOut) {
							$event = 'exit';
						}
					}
					if ($event !== null) {
						$st->setInside($inside ? 1 : 0);
						$st->setPendingSince(null);
						$st->setChangedAt($now);
						$this->states->update($st);
					}
				}
			}
			$this->db->commit();
			return $event;
		} catch (\Throwable $t) {
			$this->db->rollBack();
			throw $t;
		}
	}

	/**
	 * coverage_gap: the last inside worker exits a perimeter while ≥1 round
	 * on the site is still active (spec US-F1-04 AC-2). Fires once per open
	 * episode — an existing open coverage alert suppresses a re-fire.
	 */
	private function maybeCoverageGap(Geofence $g, int $siteId, string $workerUid): void
	{
		$insideCount = $this->states->countInsideExcluding((int)$g->getId(), $workerUid);
		if ($insideCount > 0) {
			return;
		}
		$activeRounds = $this->db->getQueryBuilder()
			->select('id')->from('tck_round')
			->where('site_id = :sid')->andWhere("status = 'active'")
			->setParameter('sid', $siteId)
			->setMaxResults(1)->executeQuery()->fetchAll();
		if ($activeRounds === []) {
			return;
		}
		if ($this->alerts->hasOpenOfType($siteId, Constants::ALERT_COVERAGE)) {
			return; // one open gap alert per site — no spam
		}
		$this->alerts->fire(Constants::ALERT_COVERAGE, $siteId, $workerUid, 'high', [
			'geofence_id' => (int)$g->getId(), 'round_id' => (int)$activeRounds[0]['id'],
		], 'cg_' . $siteId . '_' . floor($this->timeFactory->getTime() / 600), (int)$activeRounds[0]['id']);
	}
}
