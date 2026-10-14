<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Service\UpgradeBackupCatalog;
use PHPUnit\Framework\TestCase;

final class UpgradeBackupCatalogTest extends TestCase
{
	public function testSortedRestoreTablesAppendsUnknownTables(): void
	{
		$ordered = UpgradeBackupCatalog::sortedRestoreTables(['extra_table']);

		self::assertSame(['extra_table'], $ordered);
	}

	public function testClampMaxSnapshots(): void
	{
		self::assertSame(1, UpgradeBackupCatalog::clampMaxSnapshots(0));
		self::assertSame(5, UpgradeBackupCatalog::clampMaxSnapshots(5));
		self::assertSame(20, UpgradeBackupCatalog::clampMaxSnapshots(999));
	}

	public function testBackupTablesAlignWithUninstallList(): void
	{
		$uninstall = \OCA\TourCheck\Repair\UninstallDropTables::TABLES;
		sort($uninstall);
		$backup = UpgradeBackupCatalog::BACKUP_TABLES;
		sort($backup);
		self::assertSame(
			$uninstall,
			$backup,
			'tourcheck: upgrade backup and uninstall table lists must match.',
		);
	}
}
