<?php

declare(strict_types=1);

/**
 * Tables and app-data paths included in pre-update upgrade backups.
 *
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regenerate via:
 *     php scripts/sync-upgrade-backup.php --app=tourcheck
 */
namespace OCA\TourCheck\Service;

final class UpgradeBackupCatalog
{
	public const APP_ID = 'tourcheck';

	public const FORMAT_VERSION = 1;

	public const APPDATA_ROOT = 'upgrade-backups';

	/** @var list<string> App-data folder names (under appdata_<instance>/tourcheck/) to include in snapshots. */
	public const APPDATA_FOLDERS = [

	];

	public const CONFIG_MAX_SNAPSHOTS = 'upgrade_backup_max_snapshots';

	public const CONFIG_LAST_SNAPSHOT_ID = 'upgrade_backup_last_snapshot_id';

	public const DEFAULT_MAX_SNAPSHOTS = 5;

	public const MAX_SNAPSHOTS_LIMIT = 20;

	/** @var list<string> */
	public const BACKUP_TABLES = [
		'tck_alert',
		'tck_alert_delivery',
		'tck_anchor',
		'tck_checkpoint',
		'tck_client_grant',
		'tck_corrective',
		'tck_cp_token',
		'tck_escalation_leg',
		'tck_event',
		'tck_geofence',
		'tck_geofence_state',
		'tck_incident',
		'tck_journal',
		'tck_key',
		'tck_key_log',
		'tck_license_state',
		'tck_live_link',
		'tck_logbook',
		'tck_logbook_ack',
		'tck_mandown_state',
		'tck_notif',
		'tck_plan',
		'tck_position',
		'tck_position_consent',
		'tck_report_dlv',
		'tck_report_sched',
		'tck_round',
		'tck_round_cp',
		'tck_site',
		'tck_site_member',
		'tck_station',
		'tck_station_mbr',
		'tck_task',
		'tck_template',
		'tck_webhook',
		'tck_webhook_delivery',
		'tck_worker_qual',
	];

	/** @var list<string> */
	public const RESTORE_TABLE_ORDER = [
		'tck_alert',
		'tck_alert_delivery',
		'tck_anchor',
		'tck_checkpoint',
		'tck_client_grant',
		'tck_corrective',
		'tck_cp_token',
		'tck_escalation_leg',
		'tck_event',
		'tck_geofence',
		'tck_geofence_state',
		'tck_incident',
		'tck_journal',
		'tck_key',
		'tck_key_log',
		'tck_license_state',
		'tck_live_link',
		'tck_logbook',
		'tck_logbook_ack',
		'tck_mandown_state',
		'tck_notif',
		'tck_plan',
		'tck_position',
		'tck_position_consent',
		'tck_report_dlv',
		'tck_report_sched',
		'tck_round',
		'tck_round_cp',
		'tck_site',
		'tck_site_member',
		'tck_station',
		'tck_station_mbr',
		'tck_task',
		'tck_template',
		'tck_webhook',
		'tck_webhook_delivery',
		'tck_worker_qual',
	];

	public static function isBackupTable(string $table): bool
	{
		return in_array($table, self::BACKUP_TABLES, true);
	}

	public static function clampMaxSnapshots(int $requested): int
	{
		return max(1, min(self::MAX_SNAPSHOTS_LIMIT, $requested));
	}

	/**
	 * @return list<string>
	 */
	public static function existingBackupTables(callable $tableExists): array
	{
		$existing = [];
		foreach (self::BACKUP_TABLES as $table) {
			if ($tableExists($table)) {
				$existing[] = $table;
			}
		}

		return $existing;
	}

	/**
	 * @param list<string> $presentTables
	 * @return list<string>
	 */
	public static function sortedRestoreTables(array $presentTables): array
	{
		$present = array_fill_keys($presentTables, true);
		$ordered = [];
		foreach (self::RESTORE_TABLE_ORDER as $table) {
			if (isset($present[$table])) {
				$ordered[] = $table;
			}
		}

		foreach ($presentTables as $table) {
			if (!in_array($table, $ordered, true)) {
				$ordered[] = $table;
			}
		}

		return $ordered;
	}
}
