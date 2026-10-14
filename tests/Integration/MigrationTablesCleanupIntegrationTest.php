<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Integration;

use OCA\TourCheck\Repair\UninstallDropTables;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Orphan-leak guard for migration-created tables that no owning suite cleans
 * (bc-m4 class: fixture rows accumulate run-over-run when teardown forgets a
 * table). Seeds one real row into each table, proves it is readable back —
 * catches a migration silently marked complete without creating a writable
 * table — and purges every seeded row in tearDown so the suite leaves no
 * residue. Also asserts UninstallDropTables::TABLES matches the live schema
 * and every migration createTable, so an app removal can never leave an
 * orphan table behind or reference a table that never existed.
 */
final class MigrationTablesCleanupIntegrationTest extends TestCase
{
	/** @var array<string, int[]> table => inserted row ids */
	private array $seeded = [];

	private function insert(IDBConnection $db, string $table, array $values): int
	{
		$qb = $db->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $col => $val) {
			$type = $val instanceof \DateTimeInterface ? 'datetime' : null;
			$qb->setValue($col, $qb->createNamedParameter($val, $type));
		}
		$qb->executeStatement();
		$id = (int)$db->lastInsertId($table);
		$this->seeded[$table][] = $id;
		return $id;
	}

	private function rowExists(IDBConnection $db, string $table, int $id): bool
	{
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $qb->executeQuery()->fetch() !== false;
	}

	public function testEveryMigrationTableAcceptsAndPurgesRows(): void
	{
		/** @var IDBConnection $db */
		$db = \OC::$server->get(IDBConnection::class);
		$now = new \DateTime('2026-01-01 00:00:00');
		$run = 'mcit-' . bin2hex(random_bytes(6));

		// Parents first: site → plan → round, site → checkpoint, site → station.
		$siteId = $this->insert($db, 'tck_site', [
			'name' => 'MigrationCleanupIT-' . $run,
			'tz' => 'UTC',
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$planId = $this->insert($db, 'tck_plan', [
			'site_id' => $siteId,
			'name' => 'mcit plan',
			'cps_json' => '[]',
			'recurrence_json' => '{}',
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$roundId = $this->insert($db, 'tck_round', [
			'plan_id' => $planId,
			'site_id' => $siteId,
			'snapshot_json' => '{}',
			'scheduled_start' => $now,
			'window_end' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$checkpointId = $this->insert($db, 'tck_checkpoint', [
			'site_id' => $siteId,
			'name' => 'mcit cp',
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$stationId = $this->insert($db, 'tck_station', [
			'site_id' => $siteId,
			'label' => 'mcit station',
			'secret_hash' => hash('sha256', $run . 'station'),
			'created_at' => $now,
		]);

		// The 11 tables the teardown gate flagged as never named in tests.
		$leafInserts = [
			'tck_round_cp' => [
				'round_id' => $roundId,
				'checkpoint_id' => $checkpointId,
				'name_snap' => 'mcit snap',
			],
			'tck_cp_token' => [
				'checkpoint_id' => $checkpointId,
				'kind' => 'qr',
				'token_hash' => hash('sha256', $run . 'token'),
				'created_at' => $now,
			],
			'tck_station_mbr' => [
				'station_id' => $stationId,
				'display_name' => 'mcit member',
				'pin_hash' => hash('sha256', $run . 'pin'),
				'created_at' => $now,
			],
			'tck_template' => [
				'tkey' => $run,
				'vertical' => 'guard',
				'name' => 'mcit template',
				'payload_json' => '{}',
			],
			'tck_license_state' => [
				'customer_id' => $run,
				'valid_until' => new \DateTime('2030-01-01'),
				'key_applied_at' => $now,
				'payload_b64' => 'AAAA',
				'signature_b64' => 'AAAA',
				'license_fingerprint' => hash('sha256', $run . 'fp'),
				'bound_instance_id' => hash('sha256', $run . 'inst'),
			],
			'tck_live_link' => [
				'site_id' => $siteId,
				'token_hash' => hash('sha256', $run . 'live'),
				'created_at' => $now,
			],
			'tck_notif' => [
				'dedupe_key' => $run,
				'sent_at' => $now,
			],
			'tck_report_sched' => [
				'site_id' => $siteId,
				'cadence' => 'weekly',
				'recipient' => 'ops@example.invalid',
				'created_at' => $now,
				'updated_at' => $now,
			],
			'tck_report_dlv' => [
				'site_id' => $siteId,
				'period' => '2026-W01',
				'kind' => 'pdf',
				'status' => 'done',
				'created_at' => $now,
			],
		];

		foreach ($leafInserts as $table => $values) {
			$id = $this->insert($db, $table, $values);
			$this->assertGreaterThan(0, $id, "$table did not return an insert id — table missing or not writable");
			$this->assertTrue($this->rowExists($db, $table, $id), "seeded row not readable from $table");
		}
	}

	public function testUninstallTableListMatchesMigrationsAndLiveSchema(): void
	{
		/** @var IDBConnection $db */
		$db = \OC::$server->get(IDBConnection::class);
		foreach (UninstallDropTables::TABLES as $table) {
			$this->assertTrue(
				$db->tableExists($table),
				"UninstallDropTables names $table but the migration schema never created it — orphan DROP",
			);
		}

		$migDir = \dirname(__DIR__, 2) . '/lib/Migration';
		$created = [];
		foreach (glob($migDir . '/*.php') ?: [] as $file) {
			if (preg_match_all("/createTable\('(\w+)'\)/", (string)file_get_contents($file), $m)) {
				$created = array_merge($created, $m[1]);
			}
		}
		$this->assertNotEmpty($created, 'no createTable calls found in migrations — parity check would be vacuous');
		$this->assertEqualsCanonicalizing(
			array_values(array_unique($created)),
			array_values(UninstallDropTables::TABLES),
			'uninstall table list drifted from migrations — app removal would orphan or phantom-drop tables',
		);
	}

	private function purgeSeededRows(IDBConnection $db, string $table): void
	{
		foreach ($this->seeded[$table] ?? [] as $id) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
				->executeStatement();
		}
		unset($this->seeded[$table]);
	}

	protected function tearDown(): void
	{
		if ($this->seeded !== []) {
			/** @var IDBConnection $db */
			$db = \OC::$server->get(IDBConnection::class);
			// teardown purge — child tables before parents
			$this->purgeSeededRows($db, 'tck_round_cp');
			$this->purgeSeededRows($db, 'tck_cp_token');
			$this->purgeSeededRows($db, 'tck_station_mbr');
			$this->purgeSeededRows($db, 'tck_template');
			$this->purgeSeededRows($db, 'tck_license_state');
			$this->purgeSeededRows($db, 'tck_live_link');
			$this->purgeSeededRows($db, 'tck_notif');
			$this->purgeSeededRows($db, 'tck_report_sched');
			$this->purgeSeededRows($db, 'tck_report_dlv');
			$this->purgeSeededRows($db, 'tck_checkpoint');
			$this->purgeSeededRows($db, 'tck_station');
			$this->purgeSeededRows($db, 'tck_round');
			$this->purgeSeededRows($db, 'tck_plan');
			$this->purgeSeededRows($db, 'tck_site');
		}
		parent::tearDown();
	}
}
