<?php

declare(strict_types=1);

/**
 * TourCheck transfer manifest — every appconfig key and every tck_* table
 * classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * The 'transfer' settings section lives in
 * {@see \OCA\TourCheck\Service\SettingsSectionCatalog} — the settings page
 * renders the synced part via templates/pages/settings/transfer.php.
 *
 * Embedded-id gap (documented, fail-closed): tck_plan.cps_json and
 * tck_round.snapshot_json embed checkpoint/plan ids inside NOT NULL JSON
 * documents. The engine remaps declared FK COLUMNS only — there is no
 * JSON-FK mechanism — and env_columns cannot drop a NOT NULL column.
 * A verbatim copy would silently point stops at the wrong checkpoints on
 * the target (the wrong-file class), so plan/round/round_cp stay internal
 * until the pack grows a json_fk spec. Events, incidents and alerts keep
 * their evidence value and transfer with their (nullable) round links
 * env-dropped instead.
 */

namespace OCA\TourCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — written atomically through
			// SettingsService::setAccess + setAppAdmins (never raw setAppValue) ──
			'access_mode' => ['class' => 'mirrored', 'security' => true, 'section' => 'access'],
			'allowed_groups' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'allowed_users' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'app_admins' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			// ── site defaults (Settings → Site defaults) ──
			'default_timezone' => ['class' => 'plain', 'section' => 'site-defaults'],
			'map_tile_url' => ['class' => 'plain', 'section' => 'site-defaults'],
			'map_tile_attribution' => ['class' => 'plain', 'section' => 'site-defaults'],
			// F2 morning digest — written through SettingsService::setDigest.
			'digest_enabled' => ['class' => 'plain', 'section' => 'site-defaults'],
			'digest_hour' => ['class' => 'plain', 'section' => 'site-defaults'],
			'digest_email' => ['class' => 'plain', 'section' => 'site-defaults'],
			// SLA knobs are read-only (no settings input — honored at runtime
			// by KpiService); carried so a tuned test instance keeps them.
			'sla_hit_target_pct' => ['class' => 'plain', 'section' => 'site-defaults'],
			'sla_punctuality_min' => ['class' => 'plain', 'section' => 'site-defaults'],
			// ── evidence & timestamping (Settings → Evidence) ──
			'tsa_url' => ['class' => 'plain', 'section' => 'evidence'],
			'late_sync_days' => ['class' => 'plain', 'section' => 'evidence'],
			// ── retention (Settings → Retention) ──
			'position_retention_hours' => ['class' => 'plain', 'section' => 'retention'],
			'journal_retention_years' => ['class' => 'plain', 'section' => 'retention'],
			// ── station PIN policy (Settings → Stations) ──
			'pin_length' => ['class' => 'plain', 'section' => 'stations'],
			'pin_lockout_attempts' => ['class' => 'plain', 'section' => 'stations'],
			'pin_lockout_minutes' => ['class' => 'plain', 'section' => 'stations'],
			// ── branding (Settings → Branding) ──
			'brand_name' => ['class' => 'plain', 'section' => 'branding'],
			'brand_color' => ['class' => 'plain', 'section' => 'branding'],
			// ── instance-bound — never transferred ──
			// tsa_ca_bundle is an absolute filesystem path to a PEM bundle on
			// THIS host — meaningless elsewhere.
			'tsa_ca_bundle' => 'env',
			// chain_secret / nfc_sign_secret live in config.php (system
			// values, not appconfig) — declared env so nothing ever tries to
			// carry them through the pipeline. A DB dump alone can neither
			// re-chain the journal nor mint tag signatures.
			'chain_secret' => 'env',
			'nfc_sign_secret' => 'env',
			// ── internal bookkeeping — never transferred ──
			'last_cron_run_at' => 'internal',
			'schema_incomplete' => 'internal',
			'uninstall_repair_pass' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ sites + membership ══
			// created_at pins row identity across instances (a prod-authored
			// site of the same name never gets silently overwritten);
			// logo_fileid is a Files id on this instance → env.
			'tck_site' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name', 'created_at'],
				'env_columns' => ['logo_fileid']],
			'tck_site_member' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'uid'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site'],
				'uid_ref' => ['uid']],
			// Client-portal access grant — uid-gated like memberships.
			'tck_client_grant' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['client_uid', 'site_id'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site'],
				'uid_ref' => ['client_uid'], 'uid_ref_nullable' => ['created_by']],
			// Position tracking consent — per-user legal record.
			'tck_position_consent' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['uid', 'site_id'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site'],
				'uid_ref' => ['uid']],
			'tck_worker_qual' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['uid', 'qual_code'],
				'uid_ref' => ['uid']],

			// ══ site-scoped configuration ══
			'tck_checkpoint' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'name'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site']],
			'tck_geofence' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'kind', 'shape', 'created_at'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site']],
			'tck_escalation_leg' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'leg_no'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site']],
			'tck_report_sched' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'cadence', 'recipient'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site']],
			// Global template registry — tkey is the shipped+user template key.
			'tck_template' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['tkey']],

			// ══ operational records ══
			'tck_key' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'label'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site'],
				'uid_ref_nullable' => ['holder_uid'],
				// holder_member_id → tck_station_mbr (station PIN member,
				// internal) — nullable, env-dropped.
				'env_columns' => ['holder_member_id']],
			'tck_key_log' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['key_id', 'action', 'created_at'],
				'fk' => ['key_id' => 'tck_key', 'site_id' => 'tck_site'],
				'depends_on' => ['tck_key', 'tck_site'],
				'uid_ref_nullable' => ['actor_uid', 'holder_uid'],
				'env_columns' => ['member_id']],
			'tck_logbook' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'author_uid', 'created_at'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site'],
				'uid_ref' => ['author_uid']],
			'tck_logbook_ack' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['logbook_id', 'acker_uid'],
				'pk' => ['logbook_id', 'acker_uid'],
				'fk' => ['logbook_id' => 'tck_logbook'], 'depends_on' => ['tck_logbook'],
				'uid_ref' => ['acker_uid']],
			'tck_task' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'title', 'created_at'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site'],
				'uid_ref_nullable' => ['assignee_uid', 'created_by']],
			// Check-in events: the core evidence rows. site/checkpoint links
			// remap; round links are env-dropped because rounds cannot
			// transfer (see header note); station/photo/signature references
			// are instance-bound.
			'tck_event' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['client_event_id'],
				'fk' => ['site_id' => 'tck_site', 'checkpoint_id' => 'tck_checkpoint'],
				'depends_on' => ['tck_site', 'tck_checkpoint'],
				'uid_ref_nullable' => ['actor_uid', 'attester_uid'],
				'env_columns' => [
					'round_id', 'round_cp_id', 'station_id', 'station_member_id',
					'photo_fileid', 'signature_fileid',
				]],
			'tck_incident' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'client_incident_id', 'created_at'],
				'fk' => [
					'site_id' => 'tck_site',
					'checkpoint_id' => 'tck_checkpoint',
					'event_id' => 'tck_event',
				],
				'depends_on' => ['tck_site', 'tck_checkpoint', 'tck_event'],
				'uid_ref_nullable' => ['created_by'],
				'env_columns' => ['round_id', 'member_id', 'photo_fileid', 'audio_fileid']],
			'tck_corrective' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['incident_id', 'text', 'created_at'],
				'fk' => ['incident_id' => 'tck_incident'], 'depends_on' => ['tck_incident'],
				'uid_ref_nullable' => ['assignee_uid', 'verified_by']],
			'tck_alert' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'type', 'fired_at', 'client_event_id'],
				'fk' => ['site_id' => 'tck_site'], 'depends_on' => ['tck_site'],
				'uid_ref_nullable' => ['worker_uid', 'acked_by', 'resolved_by'],
				'env_columns' => ['round_id']],

			// ══ embedded-JSON-FK — no honest remap (see header note) ══
			// plan.cps_json = [{checkpoint_id,…}] and round.snapshot_json =
			// frozen {plan,cps,recurrence}; both NOT NULL (env cannot drop)
			// and neither is regenerable (not 'derived'). round_cp is the
			// round's child. A pack-level json_fk spec would unlock these.
			'tck_plan' => ['class' => 'internal'],
			'tck_round' => ['class' => 'internal'],
			'tck_round_cp' => ['class' => 'internal'],

			// ══ credentials / pairing / license — NEVER transferred ══
			// Checkpoint tag token hashes verify physical QR/NFC tags signed
			// by the instance NFC sign secret — useless and unsafe elsewhere.
			'tck_cp_token' => ['class' => 'internal'],
			// Kiosk station pairing (secret_hash, pairing codes) and member
			// PIN hashes are device-bound credentials.
			'tck_station' => ['class' => 'internal'],
			'tck_station_mbr' => ['class' => 'internal'],
			// Live-page bearer links (token_hash) — minted per instance.
			'tck_live_link' => ['class' => 'internal'],
			// Webhook subscriptions carry a NOT NULL signing secret that can
			// neither export nor NULL on insert — admin re-creates on prod.
			'tck_webhook' => ['class' => 'internal'],
			'tck_webhook_delivery' => ['class' => 'internal'],
			// License is bound to bound_instance_id + vendor signature.
			'tck_license_state' => ['class' => 'internal'],

			// ══ live state / derived / queue — never transferred ══
			// Live worker-in-zone and man-down watch state — recomputed by
			// the running companion, not a copy source.
			'tck_geofence_state' => ['class' => 'internal'],
			'tck_mandown_state' => ['class' => 'internal'],
			// GPS breadcrumbs expire by retention policy — ephemeral telemetry.
			'tck_position' => ['class' => 'internal'],
			// Notification dedupe markers — instance-live bookkeeping.
			'tck_notif' => ['class' => 'internal'],

			// ══ audit / evidence / delivery logs — NEVER transferred ══
			// The journal is hash-chained under the instance chain_secret;
			// anchors carry TSA proofs bound to that chain. Audit state is
			// instance history, not configuration.
			'tck_journal' => ['class' => 'log'],
			'tck_anchor' => ['class' => 'log'],
			'tck_alert_delivery' => ['class' => 'log'],
			// Report deliveries are history + file_id (instance Files id).
			'tck_report_dlv' => ['class' => 'log'],
		]);
	}
}
