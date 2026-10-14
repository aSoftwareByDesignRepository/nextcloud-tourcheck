<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Integration;

use OCA\TourCheck\Exception\UpgradeBackupException;
use OCA\TourCheck\Service\UpgradeBackupService;
use OCP\IDBConnection;
use Test\TestCase;

final class UpgradeBackupIntegrationTest extends TestCase
{
	private UpgradeBackupService $backupService;
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->backupService = \OC::$server->get(UpgradeBackupService::class);
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	public function testCreateListAndRestoreRoundTrip(): void
	{
		if (!$this->db->tableExists('tck_anchor')) {
			self::markTestSkipped('TourCheck tables not present in this instance.');
		}

		$before = $this->countRows('tck_anchor');

		$result = $this->backupService->createSnapshot('integration-test');
		$snapshotId = $result['id'];
		self::assertNotSame('', $snapshotId);
		self::assertTrue($result['manifest']['complete'] ?? false);
		self::assertNotEmpty($result['manifest']['tables'] ?? [], 'Snapshot must include table metadata when tables exist.');

		$snapshots = $this->backupService->listSnapshots();
		$ids = array_map(static fn (array $snapshot): string => (string)($snapshot['id'] ?? ''), $snapshots);
		self::assertContains($snapshotId, $ids, 'listSnapshots must find the snapshot just created');

		$this->db->getQueryBuilder()
			->delete('tck_anchor')
			->executeStatement();
		self::assertSame(0, $this->countRows('tck_anchor'));

		$this->backupService->restoreSnapshot($snapshotId, false);
		self::assertSame($before, $this->countRows('tck_anchor'));
	}

	public function testRestoreSkipsGeneratedColumns(): void
	{
		if (!$this->db->tableExists('tck_anchor')) {
			self::markTestSkipped('TourCheck tables not present in this instance.');
		}

		// Add a STORED GENERATED column so snapshot rows carry a non-NULL
		// generated value — restore must skip it or MariaDB throws 1906.
		$physical = \OC::$server->get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix', 'oc_') . 'tck_anchor';
		$this->db->executeStatement(
			"ALTER TABLE `{$physical}` ADD COLUMN `tck_gen_test` VARCHAR(80) GENERATED ALWAYS AS (concat(`trigger_kind`, ':', `status`)) STORED"
		);
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('tck_anchor')
				->setValue('site_id', $qb->createNamedParameter(1))
				->setValue('seq', $qb->createNamedParameter(1))
				->setValue('head_hash', $qb->createNamedParameter(str_repeat('0', 64)))
				->setValue('trigger_kind', $qb->createNamedParameter('gentest'))
				->setValue('status', $qb->createNamedParameter('pending'))
				->setValue('requested_at', $qb->createNamedParameter('2026-10-20 09:00:00'))
				->executeStatement();

			$before = $this->countRows('tck_anchor');
			$result = $this->backupService->createSnapshot('integration-test-generated-column');
			$snapshotId = $result['id'];

			$this->db->getQueryBuilder()->delete('tck_anchor')->executeStatement();
			self::assertSame(0, $this->countRows('tck_anchor'));

			$this->backupService->restoreSnapshot($snapshotId, false);
			self::assertSame($before, $this->countRows('tck_anchor'));
		} finally {
			$this->db->executeStatement("ALTER TABLE `{$physical}` DROP COLUMN `tck_gen_test`");
		}
	}

	public function testRestoreRejectsInvalidSnapshotId(): void
	{
		$this->expectException(UpgradeBackupException::class);
		$this->backupService->restoreSnapshot('../evil', false);
	}

	private function countRows(string $table): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
