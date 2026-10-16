<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Integration;

use OCA\TourCheck\Service\DriveSegmentService;
use OCP\IConfig;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * F11 / NH-15 contract: segments derive ONLY from consecutive rounds the
 * same worker engaged in on one day — a non-completed predecessor, a
 * round that never started, or a same-site pair breaks the leg, and the
 * payload never carries site identity.
 * Fixtures sit on 2040-06-15: the suite runs against a live dev DB where
 * cron-generated occurrences could otherwise land in the gap and break
 * the leg (learned the hard way — the service was right, the test wasn't
 * hermetic).
 */
final class DriveSegmentsIntegrationTest extends TestCase
{
	private string $prefix;
	private array $creds;
	private array $roundIds = [];
	private array $planIds = [];
	private array $siteIds = [];

	protected function setUp(): void
	{
		parent::setUp();
		if (!\class_exists(\OC::class) || !isset(\OC::$server)) {
			self::markTestSkipped('Nextcloud not bootstrapped');
		}
		$db = \OC::$server->get(IDBConnection::class);
		$config = \OC::$server->get(IConfig::class);
		$this->prefix = (string)$config->getSystemValue('dbtableprefix', 'oc_');
		$this->creds = [
			'host' => (string)$config->getSystemValue('dbhost', 'localhost'),
			'name' => (string)$config->getSystemValue('dbname'),
			'user' => (string)$config->getSystemValue('dbuser'),
			'pass' => (string)$config->getSystemValue('dbpassword'),
			'type' => $db->getDatabaseProvider(),
		];
	}

	protected function tearDown(): void
	{
		if (isset($this->creds['name'])) {
			$pdo = $this->pdo();
			$p = $this->prefix;
			foreach ($this->roundIds as $id) {
				$pdo->exec("DELETE FROM `{$p}tck_round` WHERE id = " . (int)$id);
			}
			// test_teardown_orphan_leak: plan + site rows seeded per test must
			// go too — child-first order (round → plan → site).
			foreach ($this->planIds as $id) {
				$pdo->exec("DELETE FROM `{$p}tck_plan` WHERE id = " . (int)$id);
			}
			foreach ($this->siteIds as $id) {
				$pdo->exec("DELETE FROM `{$p}tck_site` WHERE id = " . (int)$id);
			}
		}
		parent::tearDown();
	}

	private function pdo(): \PDO
	{
		$c = $this->creds;
		if ($c['type'] === IDBConnection::PLATFORM_POSTGRES) {
			[$host, $port] = array_pad(explode(':', $c['host'], 2), 2, '5432');
			$dsn = "pgsql:host={$host};port={$port};dbname={$c['name']}";
		} else {
			[$host, $port] = array_pad(explode(':', $c['host'], 2), 2, '3306');
			$dsn = "mysql:host={$host};port={$port};dbname={$c['name']};charset=utf8mb4";
		}
		return new \PDO($dsn, $c['user'], $c['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
	}

	private function seedSite(): int
	{
		$pdo = $this->pdo();
		$p = $this->prefix;
		$cols = $pdo->query("SHOW COLUMNS FROM `{$p}tck_site`")->fetchAll(\PDO::FETCH_COLUMN);
		$vals = [];
		foreach ($cols as $col) {
			$vals[$col] = match ($col) {
				'name' => 'SegSite-' . mt_rand(),
				'tz' => 'UTC', 'live_position' => '0',
				'mandown_pre_s' => '60',
				'active' => '1', 'revision' => '1',
				'created_at', 'updated_at' => '2026-10-13 00:00:00',
				default => null,
			};
		}
		unset($vals['id']);
		$keys = implode(',', array_keys($vals));
		$ph = implode(',', array_fill(0, count($vals), '?'));
		$pdo->prepare("INSERT INTO `{$p}tck_site` ({$keys}) VALUES ({$ph})")->execute(array_values($vals));
		$id = (int)$pdo->lastInsertId();
		$this->siteIds[] = $id;
		return $id;
	}

	private function seedPlan(int $siteId): int
	{
		$pdo = $this->pdo();
		$p = $this->prefix;
		$cols = $pdo->query("SHOW COLUMNS FROM `{$p}tck_plan`")->fetchAll(\PDO::FETCH_COLUMN);
		$vals = [];
		foreach ($cols as $col) {
			$vals[$col] = match ($col) {
				'site_id' => (string)$siteId,
				'name' => 'SegPlan',
				'recurrence_json' => '{}', 'cps_json' => '[]',
				'active' => '1', 'revision' => '1', 'tolerance_min' => '15',
				'created_at', 'updated_at' => '2026-10-13 00:00:00',
				default => null,
			};
		}
		unset($vals['id']);
		$keys = implode(',', array_keys($vals));
		$ph = implode(',', array_fill(0, count($vals), '?'));
		$pdo->prepare("INSERT INTO `{$p}tck_plan` ({$keys}) VALUES ({$ph})")->execute(array_values($vals));
		$id = (int)$pdo->lastInsertId();
		$this->planIds[] = $id;
		return $id;
	}

	private function seedRound(int $siteId, int $planId, array $r): void
	{
		$pdo = $this->pdo();
		$p = $this->prefix;
		$pdo->prepare("INSERT INTO `{$p}tck_round`
			(plan_id, site_id, snapshot_json, scheduled_start, window_end, status,
			 started_at, started_by, completed_at, completed_by, created_at, updated_at)
			VALUES (?, ?, '{}', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
			->execute([
				$planId, $siteId, $r['sched'], $r['end'], $r['status'],
				$r['started'] ?? null, $r['actor'] ?? null,
				$r['completed'] ?? null, $r['actor'] ?? null,
			]);
		$this->roundIds[] = (int)$pdo->lastInsertId();
	}

	public function testConsecutiveCompletedRoundsProduceSegments(): void
	{
		// Far-future date: the dev DB carries foreign unclaimed rounds on
		// "today" almost every minute, and any stray one inside the A→B
		// schedule gap legitimately suppresses the segment (never emit a
		// phantom leg). A date with zero foreign rounds makes the leg
		// deterministic on every run.
		$day = '2030-01-15';
		$siteA = $this->seedSite();
		$siteB = $this->seedSite();
		$planA = $this->seedPlan($siteA);
		$planB = $this->seedPlan($siteB);
		$this->seedRound($siteA, $planA, ['sched' => "$day 08:00:00", 'end' => "$day 09:00:00", 'status' => 'completed', 'started' => "$day 08:02:00", 'completed' => "$day 08:50:00", 'actor' => 'u_seg1']);
		$this->seedRound($siteB, $planB, ['sched' => "$day 10:00:00", 'end' => "$day 11:00:00", 'status' => 'completed', 'started' => "$day 09:20:00", 'completed' => "$day 10:40:00", 'actor' => 'u_seg1']);

		$svc = \OC::$server->get(DriveSegmentService::class);
		$segs = array_values(array_filter(
			$svc->segments($day, $day),
			static fn ($s) => $s['executor'] === 'u_seg1'
		));
		$this->assertCount(1, $segs);
		$this->assertSame('Dienstfahrt Tour', $segs[0]['generic_purpose']);
		$this->assertStringContainsString('08:50', $segs[0]['departure_ts']);
		$this->assertStringContainsString('09:20', $segs[0]['arrival_ts']);
		$this->assertSame('2030-01-15', $segs[0]['date']);
		// allow-list: no site-identifying field may cross the flange
		$this->assertSame(
			['segment_id', 'date', 'executor', 'departure_ts', 'arrival_ts', 'generic_purpose'],
			array_keys($segs[0])
		);
	}

	public function testMissedMiddleRoundBreaksTheChain(): void
	{
		$siteA = $this->seedSite();
		$siteB = $this->seedSite();
		$siteC = $this->seedSite();
		$planA = $this->seedPlan($siteA);
		$planB = $this->seedPlan($siteB);
		$planC = $this->seedPlan($siteC);
		$this->seedRound($siteA, $planA, ['sched' => '2026-10-13 08:00:00', 'end' => '2026-10-13 09:00:00', 'status' => 'completed', 'started' => '2026-10-13 08:02:00', 'completed' => '2026-10-13 08:50:00', 'actor' => 'u_seg2']);
		$this->seedRound($siteB, $planB, ['sched' => '2026-10-13 09:00:00', 'end' => '2026-10-13 10:00:00', 'status' => 'missed', 'actor' => 'u_seg2']);
		$this->seedRound($siteC, $planC, ['sched' => '2026-10-13 11:00:00', 'end' => '2026-10-13 12:00:00', 'status' => 'completed', 'started' => '2026-10-13 10:30:00', 'completed' => '2026-10-13 11:30:00', 'actor' => 'u_seg2']);

		$svc = \OC::$server->get(DriveSegmentService::class);
		$segs = array_values(array_filter(
			$svc->segments('2026-10-13', '2026-10-13'),
			static fn ($s) => $s['executor'] === 'u_seg2'
		));
		// A→B broken (B never started); B→C broken (B not completed). No
		// phantom A→C leg across the missed round.
		$this->assertCount(0, $segs);
	}

	public function testSameSitePairIsNoFahrt(): void
	{
		$siteA = $this->seedSite();
		$planA = $this->seedPlan($siteA);
		$this->seedRound($siteA, $planA, ['sched' => '2026-10-13 08:00:00', 'end' => '2026-10-13 09:00:00', 'status' => 'completed', 'started' => '2026-10-13 08:02:00', 'completed' => '2026-10-13 08:50:00', 'actor' => 'u_seg3']);
		$this->seedRound($siteA, $planA, ['sched' => '2026-10-13 10:00:00', 'end' => '2026-10-13 11:00:00', 'status' => 'completed', 'started' => '2026-10-13 10:05:00', 'completed' => '2026-10-13 10:40:00', 'actor' => 'u_seg3']);

		$svc = \OC::$server->get(DriveSegmentService::class);
		$segs = array_values(array_filter(
			$svc->segments('2026-10-13', '2026-10-13'),
			static fn ($s) => $s['executor'] === 'u_seg3'
		));
		$this->assertCount(0, $segs);
	}
}
