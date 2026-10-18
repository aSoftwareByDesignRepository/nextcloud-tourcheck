<?php

declare(strict_types=1);

namespace OCA\TourCheck\Public;

use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Db\SiteMapper;

/**
 * F17 producer side — DutyCheck reads round status for linked assignments
 * (`dc_assignments.tck_round_id`) and offers linkable rounds to the shift
 * planner. Server-side contract, never public HTTP. Read-only: DutyCheck
 * never writes `tck_*` — the link lives in DutyCheck's own table.
 *
 * Wire shapes (additive only; consumers must tolerate missing keys):
 *  statusesOf([roundId,…])        → {roundId: {status, siteId, siteName, scheduledStart, windowEnd}}
 *  openRoundsForDate('YYYY-MM-DD') → [{id, siteId, siteName, scheduledStart, windowEnd}]
 */
class RoundLinkReadFacade
{
	public const FACADE_VERSION = 1;

	public function __construct(
		private readonly RoundMapper $rounds,
		private readonly SiteMapper $sites,
	) {
	}

	/**
	 * Status lookup for the coverage-gap engine. Unknown/deleted rounds are
	 * simply absent from the map — the caller treats a missing entry like
	 * "no information", never as "missed" (fail-safe, not fail-deadly).
	 *
	 * @param list<int|string> $roundIds
	 * @return array<int, array{status:string, siteId:int, siteName:string, scheduledStart:?string, windowEnd:?string}>
	 */
	public function statusesOf(array $roundIds): array
	{
		$out = [];
		foreach (array_slice($roundIds, 0, 200) as $raw) {
			$id = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 ? (int)$raw : 0);
			if ($id <= 0 || isset($out[$id])) {
				continue;
			}
			$round = $this->rounds->find($id);
			if ($round === null) {
				continue;
			}
			$site = $this->sites->find((int)$round->getSiteId());
			$out[$id] = [
				'status' => (string)$round->getStatus(),
				'siteId' => (int)$round->getSiteId(),
				'siteName' => $site !== null ? (string)$site->getName() : '',
				'scheduledStart' => $round->getScheduledStart()?->format('Y-m-d H:i:s'),
				'windowEnd' => $round->getWindowEnd()?->format('Y-m-d H:i:s'),
			];
		}
		return $out;
	}

	/**
	 * Link candidates for the duty planner: non-terminal rounds on a date.
	 * Completed/partial rounds stay linkable (they ran — the record is
	 * historical); skipped ones too. Returns an empty list on bad input.
	 *
	 * @return list<array{id:int, siteId:int, siteName:string, status:string, scheduledStart:?string, windowEnd:?string}>
	 */
	public function openRoundsForDate(string $ymd): array
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) !== 1) {
			return [];
		}
		try {
			$start = new \DateTimeImmutable($ymd . ' 00:00:00', new \DateTimeZone('UTC'));
		} catch (\Throwable) {
			return [];
		}
		$rounds = $this->rounds->findScheduledOn($start, $start->modify('+1 day'));
		$siteNames = [];
		$out = [];
		foreach ($rounds as $round) {
			$siteId = (int)$round->getSiteId();
			if (!array_key_exists($siteId, $siteNames)) {
				$site = $this->sites->find($siteId);
				$siteNames[$siteId] = $site !== null ? (string)$site->getName() : '';
			}
			$out[] = [
				'id' => (int)$round->getId(),
				'siteId' => $siteId,
				'siteName' => $siteNames[$siteId],
				'status' => (string)$round->getStatus(),
				'scheduledStart' => $round->getScheduledStart()?->format('Y-m-d H:i'),
				'windowEnd' => $round->getWindowEnd()?->format('Y-m-d H:i'),
			];
		}
		return $out;
	}
}
