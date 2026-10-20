<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Competitive-parity schema (planning/features/tourcheck/competitive-parity/SPEC.md):
 * geofences + episode state, alerts + escalation legs, site tasks, logbook
 * + acks, client-portal site grants, webhooks + delivery log, worker
 * qualifications, per-worker position consent, GPS/BLE checkpoint columns.
 */
class Version1007Date20261106000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('tck_checkpoint')) {
			$t = $schema->getTable('tck_checkpoint');
			if (!$t->hasColumn('cp_kind')) {
				$t->addColumn('cp_kind', 'string', ['notnull' => true, 'length' => 8, 'default' => 'tag']);
			}
			if (!$t->hasColumn('beacon_uuid')) {
				$t->addColumn('beacon_uuid', 'string', ['notnull' => false, 'length' => 36]);
			}
			if (!$t->hasColumn('min_dwell_s')) {
				$t->addColumn('min_dwell_s', 'integer', ['notnull' => false]);
			}
			if (!$t->hasColumn('required_qual')) {
				$t->addColumn('required_qual', 'string', ['notnull' => false, 'length' => 64]);
			}
		}

		if ($schema->hasTable('tck_site')) {
			$t = $schema->getTable('tck_site');
			// NULL = man-down disabled for the site; else inactivity minutes.
			if (!$t->hasColumn('mandown_min')) {
				$t->addColumn('mandown_min', 'integer', ['notnull' => false]);
			}
			// Seconds of device-side pre-alarm before escalation fires.
			if (!$t->hasColumn('mandown_pre_s')) {
				$t->addColumn('mandown_pre_s', 'integer', ['notnull' => true, 'default' => 60]);
			}
		}

		if ($schema->hasTable('tck_report_dlv')) {
			$t = $schema->getTable('tck_report_dlv');
			// Portal sharing is opt-in per delivery — a report only becomes
			// visible to client accounts when it was generated for the
			// client audience AND a planner marks it.
			if (!$t->hasColumn('client_visible')) {
				$t->addColumn('client_visible', 'integer', ['notnull' => true, 'default' => 0]);
			}
		}

		if (!$schema->hasTable('tck_geofence')) {
			$t = $schema->createTable('tck_geofence');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('shape', 'string', ['notnull' => true, 'length' => 8]);
			$t->addColumn('center_lat', 'float', ['notnull' => false]);
			$t->addColumn('center_lon', 'float', ['notnull' => false]);
			$t->addColumn('radius_m', 'integer', ['notnull' => false]);
			$t->addColumn('polygon_json', 'text', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['site_id'], 'tck_gf_site_idx');
		}

		if (!$schema->hasTable('tck_geofence_state')) {
			$t = $schema->createTable('tck_geofence_state');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('worker_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('geofence_id', 'bigint', ['notnull' => true]);
			$t->addColumn('inside', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('pending_since', 'datetime', ['notnull' => false]);
			$t->addColumn('changed_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['worker_uid', 'geofence_id'], 'tck_gfs_uniq');
		}

		if (!$schema->hasTable('tck_alert')) {
			$t = $schema->createTable('tck_alert');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('type', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('worker_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('round_id', 'bigint', ['notnull' => false]);
			$t->addColumn('severity', 'string', ['notnull' => true, 'length' => 8]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'fired']);
			$t->addColumn('payload_json', 'text', ['notnull' => false]);
			$t->addColumn('escalated_leg', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('fired_at', 'datetime', ['notnull' => true]);
			$t->addColumn('acked_at', 'datetime', ['notnull' => false]);
			$t->addColumn('acked_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('resolved_at', 'datetime', ['notnull' => false]);
			$t->addColumn('resolved_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('resolution_note', 'text', ['notnull' => false]);
			$t->addColumn('client_event_id', 'string', ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['site_id', 'state'], 'tck_alert_site_state');
			$t->addIndex(['state', 'fired_at'], 'tck_alert_state_fired');
		}

		// Per-leg delivery claims: a unique (alert,leg) row is the claim ticket
		// — at most one worker ever dispatches a given leg, crash-retry-safe.
		if (!$schema->hasTable('tck_alert_delivery')) {
			$t = $schema->createTable('tck_alert_delivery');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('alert_id', 'bigint', ['notnull' => true]);
			$t->addColumn('leg_no', 'integer', ['notnull' => true]);
			$t->addColumn('channel', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('attempt', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('sent_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['alert_id', 'leg_no'], 'tck_ad_uniq');
			$t->addIndex(['status'], 'tck_ad_status');
		}

		if (!$schema->hasTable('tck_escalation_leg')) {
			$t = $schema->createTable('tck_escalation_leg');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('leg_no', 'integer', ['notnull' => true]);
			$t->addColumn('delay_min', 'integer', ['notnull' => true]);
			$t->addColumn('channel', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('target_json', 'text', ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['site_id', 'leg_no'], 'tck_esc_site_leg');
		}

		if (!$schema->hasTable('tck_task')) {
			$t = $schema->createTable('tck_task');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('assignee_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('title', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('body', 'text', ['notnull' => false]);
			$t->addColumn('due_at', 'datetime', ['notnull' => false]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('evidence_json', 'text', ['notnull' => false]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['assignee_uid', 'status'], 'tck_task_assignee');
			$t->addIndex(['site_id'], 'tck_task_site');
		}

		if (!$schema->hasTable('tck_logbook')) {
			$t = $schema->createTable('tck_logbook');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('author_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('pinned', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('body', 'text', ['notnull' => true]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['site_id', 'pinned', 'id'], 'tck_log_site_pin');
		}

		if (!$schema->hasTable('tck_logbook_ack')) {
			$t = $schema->createTable('tck_logbook_ack');
			$t->addColumn('logbook_id', 'bigint', ['notnull' => true]);
			$t->addColumn('acker_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('acked_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['logbook_id', 'acker_uid']);
		}

		if (!$schema->hasTable('tck_client_grant')) {
			$t = $schema->createTable('tck_client_grant');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('client_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('created_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['client_uid', 'site_id'], 'tck_cgrant_uniq');
			$t->addIndex(['site_id'], 'tck_cgrant_site');
		}

		if (!$schema->hasTable('tck_worker_qual')) {
			$t = $schema->createTable('tck_worker_qual');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('qual_code', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['uid', 'qual_code'], 'tck_wqual_uniq');
		}

		if (!$schema->hasTable('tck_position_consent')) {
			$t = $schema->createTable('tck_position_consent');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('granted_at', 'datetime', ['notnull' => true]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['uid', 'site_id'], 'tck_pconsent_uniq');
		}

		// Persisted man-down state per (round, worker) — the inactivity clock
		// survives app restarts; pre-alarm is a phase, not an alert.
		if (!$schema->hasTable('tck_mandown_state')) {
			$t = $schema->createTable('tck_mandown_state');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('round_id', 'bigint', ['notnull' => true]);
			$t->addColumn('worker_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('last_activity_at', 'datetime', ['notnull' => true]);
			$t->addColumn('phase', 'string', ['notnull' => true, 'length' => 16, 'default' => 'watching']);
			$t->addColumn('prealarm_at', 'datetime', ['notnull' => false]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['round_id', 'worker_uid'], 'tck_mds_uniq');
			$t->addIndex(['phase', 'last_activity_at'], 'tck_mds_sweep');
		}

		if (!$schema->hasTable('tck_webhook')) {
			$t = $schema->createTable('tck_webhook');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('url', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('secret', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('events_json', 'text', ['notnull' => true]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('tck_webhook_delivery')) {
			$t = $schema->createTable('tck_webhook_delivery');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('webhook_id', 'bigint', ['notnull' => true]);
			$t->addColumn('event_key', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('payload_json', 'text', ['notnull' => true]);
			// HMAC secret snapshot at emit time — in-flight retries keep the
			// secret they started with across a rotation (spec AC-3).
			$t->addColumn('secret', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('attempt', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('next_retry_at', 'datetime', ['notnull' => false]);
			$t->addColumn('response_code', 'integer', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['webhook_id', 'event_key'], 'tck_whd_uniq');
			$t->addIndex(['status', 'next_retry_at'], 'tck_whd_pending');
		}

		return $schema;
	}
}
