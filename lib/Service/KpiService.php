<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Site KPIs (US-F8-01) — SLA metrics computed from canonical tables.
 * Formulas (documented in-app via tooltips, never mystery math):
 *   hit_rate_pct      = confirmed checkpoints / planned checkpoints in the
 *                       period's non-scheduled rounds (completed+partial+missed)
 *   punctuality_pct   = rounds that went active ≤ window_start + punct_min
 *                       of all non-scheduled rounds
 *   open_incidents    = incidents not closed
 *   median_incident_age_h = age of the oldest open incident, hours
 *   coverage_pct      = rounds not missed / all scheduled+started rounds
 * Insufficient data (<3 rounds) returns nulls + insufficient=true — never a
 * misleading 0 % or 100 %.
 */
class KpiService
{
	public const MIN_ROUNDS = 3;

	public function __construct(
		private readonly AccessControlService $acl,
		private readonly SettingsService $settings,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @return array{
	 *   period_from:string, period_to:string, rounds_total:int,
	 *   insufficient:bool, hit_rate_pct:?float, punctuality_pct:?float,
	 *   coverage_pct:?float, open_incidents:int, oldest_open_incident_h:?float,
	 *   sla_hit_target_pct:int, sla_punctuality_min:int
	 * }
	 */
	public function forSite(int $siteId, \DateTimeInterface $from, \DateTimeInterface $to): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->compute($siteId, $from, $to);
	}

	/**
	 * Portal path — the caller (ClientPortalService::assertSiteScope) already
	 * proved the client grant; KPIs are site-aggregate so no worker data
	 * crosses. Kept separate so the staff ACL isn't silently weakened.
	 */
	public function forSiteBypassingStaffAcl(int $siteId, \DateTimeInterface $from, \DateTimeInterface $to): array
	{
		return $this->compute($siteId, $from, $to);
	}

	private function compute(int $siteId, \DateTimeInterface $from, \DateTimeInterface $to): array
	{
		$qb = $this->db->getQueryBuilder();

		$rounds = $qb->select('id', 'status', 'scheduled_start', 'started_at')
			->from('tck_round')
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->gte('scheduled_start', $qb->createNamedParameter($from, 'datetime')))
			->andWhere($qb->expr()->lt('scheduled_start', $qb->createNamedParameter($to, 'datetime')))
			->executeQuery()->fetchAll();

		$roundIds = array_map(static fn ($r) => (int)$r['id'], $rounds);
		$total = count($rounds);

		$base = [
			'period_from' => $from->format('Y-m-d'),
			'period_to' => $to->format('Y-m-d'),
			'rounds_total' => $total,
			'insufficient' => $total < self::MIN_ROUNDS,
			'hit_rate_pct' => null,
			'punctuality_pct' => null,
			'coverage_pct' => null,
			'sla_hit_target_pct' => $this->settings->getSlaHitTargetPct(),
			'sla_punctuality_min' => $this->settings->getSlaPunctualityMin(),
		];

		// incidents (always computable — zero is meaningful here)
		$qb = $this->db->getQueryBuilder();
		$openInc = $qb->select($qb->func()->count('id'), $qb->func()->min('created_at'))
			->from('tck_incident')
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('closed')))
			->executeQuery()->fetch(\PDO::FETCH_NUM);
		$base['open_incidents'] = (int)($openInc[0] ?? 0);
		$base['oldest_open_incident_h'] = $openInc[1] !== null
			? round(($this->timeFactory->getTime() - strtotime((string)$openInc[1])) / 3600, 1)
			: null;

		if ($base['insufficient']) {
			return $base;
		}

		// coverage: not-missed / all
		$missed = count(array_filter($rounds, static fn ($r) => $r['status'] === 'missed'));
		$base['coverage_pct'] = round(100 * ($total - $missed) / $total, 1);

		// punctuality: active within window_start + punct min
		$punct = $this->settings->getSlaPunctualityMin();
		$started = 0;
		$onTime = 0;
		foreach ($rounds as $r) {
			if ($r['status'] === 'scheduled' || $r['started_at'] === null) {
				continue;
			}
			$started++;
			$diff = strtotime((string)$r['started_at']) - strtotime((string)$r['scheduled_start']);
			if ($diff <= $punct * 60) {
				$onTime++;
			}
		}
		$base['punctuality_pct'] = $started > 0 ? round(100 * $onTime / $started, 1) : null;

		// hit rate: done round-checkpoints / all planned in non-scheduled rounds
		$finishedIds = [];
		foreach ($rounds as $r) {
			if ($r['status'] !== 'scheduled') {
				$finishedIds[] = (int)$r['id'];
			}
		}
		if ($finishedIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$rows = $qb->select('status', $qb->func()->count('id', 'cnt'))
				->from('tck_round_cp')
				->where($qb->expr()->in('round_id', $qb->createNamedParameter($finishedIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->groupBy('status')
				->executeQuery()->fetchAll();
			$planned = 0;
			$done = 0;
			foreach ($rows as $row) {
				$planned += (int)$row['cnt'];
				if (in_array($row['status'], ['done', 'attested'], true)) {
					$done += (int)$row['cnt'];
				}
			}
			$base['hit_rate_pct'] = $planned > 0 ? round(100 * $done / $planned, 1) : null;
		}

		return $base;
	}
}
