<?php

declare(strict_types=1);

namespace OCA\TourCheck;

final class Constants
{
	public const APP_ID = 'tourcheck';
	public const PREFIX = 'tck';

	// Roles (site_member.role) and Nextcloud groups (provisioned by EnsureGroups repair step)
	public const ROLE_ADMIN = 'admin';
	public const ROLE_PLANNER = 'planner';
	public const ROLE_SUPERVISOR = 'supervisor';
	public const ROLE_WORKER = 'worker';
	public const GROUP_ADMIN = 'tourcheck_admin';
	public const GROUP_PLANNER = 'tourcheck_planner';
	public const GROUP_SUPERVISOR = 'tourcheck_supervisor';
	public const GROUP_WORKER = 'tourcheck_worker';

	// Wire error codes — uniform NOT_FOUND collapses existence+authorization (no oracles)
	public const ERR_NOT_FOUND = 'NOT_FOUND';
	public const ERR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERR_NOT_AUTHENTICATED = 'NOT_AUTHENTICATED';
	public const ERR_VALIDATION = 'VALIDATION';
	public const ERR_CONFLICT_STALE = 'CONFLICT_STALE';
	public const ERR_RATE_LIMITED = 'RATE_LIMITED';
	public const ERR_TOKEN_INVALID = 'TOKEN_INVALID';
	public const ERR_ROUND_CLOSED = 'ROUND_CLOSED';
	public const ERR_PAYMENT_REQUIRED = 'PAYMENT_REQUIRED';
	public const ERR_LICENSE_INVALID = 'LICENSE_INVALID';
	public const ERR_CSV_REQUIRED = 'REQUIRED_FIELD';
	public const ERR_BUSY = 'BUSY';

	// Journal entry kinds
	public const KIND_CHECK = 'check';
	public const KIND_MANUAL = 'manual';
	public const KIND_INCIDENT = 'incident';
	public const KIND_ADMIN = 'admin';
	public const KIND_ERASURE = 'erasure';
	public const KIND_PURGED_RANGE = 'purged_range';
	public const KIND_ATTEST = 'attest';
	public const KIND_ANCHOR = 'anchor';

	// Capture methods — server-derived only (TH-01): clients cannot set method.
	public const METHOD_SCAN_QR = 'scan_qr';
	public const METHOD_NFC = 'nfc';
	public const METHOD_STATION = 'station';
	public const METHOD_ATTEST = 'attested';
	public const METHOD_MANUAL = 'manual';
	public const METHOD_GPS = 'gps';   // geofence-radius confirm (no tag)
	public const METHOD_BLE = 'ble';   // beacon-proximity confirm

	// Alert types (tck_alert.type)
	public const ALERT_MANDOWN = 'mandown';
	public const ALERT_SOS = 'sos';
	public const ALERT_COVERAGE = 'coverage_gap';
	public const ALERT_ZONE = 'zone_violation';

	public const ALERT_STATES = ['fired', 'escalating', 'acked', 'resolved', 'resolved_false_alarm'];

	public const GROUP_CLIENT = 'tourcheck_client'; // read-only portal role (Q1: NC accounts)

	// Round statuses — status derived, never client-set (single writer: recompute)
	public const ROUND_SCHEDULED = 'scheduled';
	public const ROUND_ACTIVE = 'active';
	public const ROUND_COMPLETED = 'completed';
	public const ROUND_PARTIAL = 'partial'; // terminal: closed early with checkpoints left undone
	public const ROUND_MISSED = 'missed';
	public const ROUND_SKIPPED = 'skipped';

	// Evidence integrity: chain secret lives in config.php (system config), never DB.
	public const CONFIG_CHAIN_SECRET = 'chain_secret';
	public const CONFIG_ACCESS_MODE = 'access_mode';            // open|restricted
	public const CONFIG_ALLOWED_GROUPS = 'allowed_groups';      // JSON list
	public const CONFIG_ALLOWED_USERS = 'allowed_users';        // JSON list
	public const CONFIG_APP_ADMINS = 'app_admins';              // JSON list (delegated)
	public const CONFIG_TSA_URL = 'tsa_url';
	public const CONFIG_LATE_SYNC_DAYS = 'late_sync_days';      // default 7
	public const CONFIG_POSITION_RETENTION_H = 'position_retention_hours'; // default 24
	public const CONFIG_JOURNAL_RETENTION_Y = 'journal_retention_years';   // default 10
	public const CONFIG_PIN_LENGTH = 'pin_length';              // default 6
	public const CONFIG_PIN_LOCKOUT_N = 'pin_lockout_attempts'; // default 5
	public const CONFIG_PIN_LOCKOUT_MIN = 'pin_lockout_minutes'; // default 15
	public const CONFIG_BRAND_NAME = 'brand_name';
	public const CONFIG_BRAND_COLOR = 'brand_color';
	public const CONFIG_LAST_CRON_RUN = 'last_cron_run_at';
	public const CONFIG_TILE_URL = 'map_tile_url';              // empty = schematic fallback (Q2)

	// Map tile providers (settings UI) — 'schematic' stores no URL (privacy default, Q2)
	public const TILE_PROVIDER_SCHEMATIC = 'schematic';
	public const TILE_PROVIDER_OSM = 'osm';
	public const TILE_PROVIDER_CUSTOM = 'custom';
	public const TILE_URL_OPENSTREETMAP = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
	public const TILE_ATTRIBUTION_OSM = '© OpenStreetMap contributors';
	public const CONFIG_TILE_ATTRIBUTION = 'map_tile_attribution'; // optional, custom provider only
	public const CONFIG_SLA_HIT_PCT = 'sla_hit_target_pct';     // default 95
	public const CONFIG_SLA_PUNCT_MIN = 'sla_punctuality_min';  // default 15

	public const ANCHOR_EVERY_N = 200; // journal entries between anchors
}
