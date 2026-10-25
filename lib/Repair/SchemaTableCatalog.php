<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after tourcheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=tourcheck
 */
namespace OCA\TourCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
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

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [

	];
}
