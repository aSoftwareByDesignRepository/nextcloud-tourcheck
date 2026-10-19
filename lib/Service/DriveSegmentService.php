<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * NH-15 / suite flange F11 — `check:drive-segments` read facade.
 *
 * MobilityCheck pulls these and turns them into Fahrtenbuch DRAFTS the
 * driver confirms (km/Tachostand are never auto-final). A segment exists
 * only between two consecutive rounds the same worker engaged in on one
 * day: departure = completed_at of round N, arrival = started_at of the
 * worker's next round. Inter-site legs only — a segment spanning a
 * missed/skipped round in between is never emitted (the driver's movement
 * then wasn't a clean leg; they log it themselves). Fields are
 * allow-listed: no site or checkpoint identity ever crosses the flange.
 */
class DriveSegmentService
{
	/** Contract version consumers must match (class + const + segments()). */
	public const FACADE_VERSION = 1;

	/** Generic purpose text — spec-pinned, never site-identifying. */
	public const PURPOSE = 'Dienstfahrt Tour';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	/**
	 * @return list<array{segment_id:string, date:string, executor:string,
	 *   departure_ts:string, arrival_ts:string, generic_purpose:string}>
	 */
	public function segments(string $from, string $to): array
	{
		$this->assertDate($from, 'from');
		$this->assertDate($to, 'to');
		if ($from > $to) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Validation failed.', ['to' => 'invalid']);
		}

		// Every round a named worker engaged in during the window — engaged
		// means started or completed (kiosk/site-attributed rounds have no
		// driver and are excluded by the NOT NULL actor filters below).
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'site_id', 'status', 'scheduled_start', 'started_at', 'started_by', 'completed_at', 'completed_by')
			->from('tck_round')
			->where($qb->expr()->gte('scheduled_start', $qb->createNamedParameter($from . ' 00:00:00')))
			->andWhere($qb->expr()->lte('scheduled_start', $qb->createNamedParameter($to . ' 23:59:59')))
			->orderBy('scheduled_start', 'ASC')
			->addOrderBy('id', 'ASC');

		$byActor = [];
		// Rounds nobody engaged with (missed/skipped carry no actor) are
		// collected separately: one sitting in the (A,B) gap may have been
		// the driver's missed stop — never fabricate a leg across it.
		$unclaimed = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$actor = (string)($row['completed_by'] ?: ($row['started_by'] ?: ''));
			if ($actor === '') {
				$unclaimed[] = (string)$row['scheduled_start'];
				continue;
			}
			$byActor[$actor][] = $row;
		}

		$out = [];
		foreach ($byActor as $actor => $rounds) {
			// Walk the actor's rounds in schedule order; a leg needs round A
			// completed and round B (the actor's chronologically NEXT engaged
			// round) started after A closed. Any missed/skipped/other round in
			// between breaks the chain — never emit a phantom segment.
			for ($i = 0; $i < count($rounds) - 1; $i++) {
				$a = $rounds[$i];
				$b = $rounds[$i + 1];
				// A finished, B physically started (missed/skipped rounds
				// carry no timestamps → chain naturally breaks there).
				if ((string)$a['status'] !== Constants::ROUND_COMPLETED
					|| $a['completed_at'] === null || $b['started_at'] === null) {
					continue;
				}
				if ((int)$a['site_id'] === (int)$b['site_id']) {
					continue; // same-site movement is not a Fahrt
				}
				// Same-day legs only — an overnight gap is not a documented leg.
				if (substr((string)$a['completed_at'], 0, 10) !== substr((string)$b['started_at'], 0, 10)) {
					continue;
				}
				// An unclaimed round scheduled between the pair may have been
				// the driver's missed stop → never emit a segment across it.
				foreach ($unclaimed as $gap) {
					if ($gap > (string)$a['scheduled_start'] && $gap < (string)$b['scheduled_start']) {
						continue 2;
					}
				}
				$dep = strtotime((string)$a['completed_at']);
				$arr = strtotime((string)$b['started_at']);
				if ($dep === false || $arr === false || $arr < $dep) {
					continue; // implausible timestamps → skip, never fabricate
				}
				$out[] = [
					// Deterministic id for consumer-side dedupe
					// (F11: idempotency on (source_app, segment_id)).
					'segment_id' => 'tck-' . (int)$a['id'] . '-' . (int)$b['id'],
					'date' => substr((string)$a['completed_at'], 0, 10),
					'executor' => $actor,
					'departure_ts' => date(\DateTimeInterface::ATOM, $dep),
					'arrival_ts' => date(\DateTimeInterface::ATOM, $arr),
					'generic_purpose' => self::PURPOSE,
				];
			}
		}
		return $out;
	}

	private function assertDate(string $d, string $field): void
	{
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) !== 1
			|| !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Validation failed.', [$field => 'invalid']);
		}
	}
}
