<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * TourCheck initial schema (tck_* ≤ 27 chars; explicit short PK/index names).
 * Booleans are integer 0/1 (Oracle-safe). All creates guarded (idempotent).
 */
class Version1000Date20261012000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('tck_site')) {
			$t = $schema->createTable('tck_site');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('address', 'string', ['notnull' => false, 'length' => 500]);
			$t->addColumn('client_contact', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('logo_fileid', 'bigint', ['notnull' => false]);
			$t->addColumn('tz', 'string', ['notnull' => true, 'length' => 64, 'default' => 'Europe/Berlin']);
			$t->addColumn('live_position', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_site_pk');
		}

		if (!$schema->hasTable('tck_checkpoint')) {
			$t = $schema->createTable('tck_checkpoint');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('instructions', 'string', ['notnull' => false, 'length' => 1000]);
			$t->addColumn('order_hint', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('profile_json', 'text', ['notnull' => false]);
			$t->addColumn('geo_lat', 'float', ['notnull' => false]);
			$t->addColumn('geo_lon', 'float', ['notnull' => false]);
			$t->addColumn('geo_radius', 'integer', ['notnull' => false]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_cp_pk');
			$t->addIndex(['site_id', 'active'], 'tck_cp_site_act_idx');
		}

		if (!$schema->hasTable('tck_cp_token')) {
			$t = $schema->createTable('tck_cp_token');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('checkpoint_id', 'bigint', ['notnull' => true]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 8]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'tck_cptok_pk');
			$t->addUniqueIndex(['token_hash'], 'tck_cptok_hash_uq');
			$t->addIndex(['checkpoint_id'], 'tck_cptok_cp_idx');
		}

		if (!$schema->hasTable('tck_plan')) {
			$t = $schema->createTable('tck_plan');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('cps_json', 'text', ['notnull' => true]);
			$t->addColumn('recurrence_json', 'text', ['notnull' => true]);
			$t->addColumn('assignee', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('tolerance_min', 'integer', ['notnull' => true, 'default' => 15]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_plan_pk');
			$t->addIndex(['site_id', 'active'], 'tck_plan_site_act_idx');
		}

		if (!$schema->hasTable('tck_round')) {
			$t = $schema->createTable('tck_round');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('plan_id', 'bigint', ['notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('snapshot_json', 'text', ['notnull' => true]);
			$t->addColumn('scheduled_start', 'datetime', ['notnull' => true]);
			$t->addColumn('window_end', 'datetime', ['notnull' => true]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'scheduled']);
			$t->addColumn('started_at', 'datetime', ['notnull' => false]);
			$t->addColumn('started_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('completed_at', 'datetime', ['notnull' => false]);
			$t->addColumn('completed_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('skip_reason', 'string', ['notnull' => false, 'length' => 500]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_round_pk');
			$t->addUniqueIndex(['plan_id', 'scheduled_start'], 'tck_round_gen_uq');
			$t->addIndex(['site_id', 'status'], 'tck_round_site_st_idx');
			$t->addIndex(['scheduled_start'], 'tck_round_sched_idx');
			$t->addIndex(['window_end', 'status'], 'tck_round_missed_idx');
		}

		if (!$schema->hasTable('tck_round_cp')) {
			$t = $schema->createTable('tck_round_cp');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('round_id', 'bigint', ['notnull' => true]);
			$t->addColumn('checkpoint_id', 'bigint', ['notnull' => true]);
			$t->addColumn('name_snap', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('sort', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('earliest', 'datetime', ['notnull' => false]);
			$t->addColumn('due', 'datetime', ['notnull' => false]);
			$t->addColumn('latest', 'datetime', ['notnull' => false]);
			$t->addColumn('items_json', 'text', ['notnull' => false]);
			$t->addColumn('profile_json', 'text', ['notnull' => false]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('event_id', 'bigint', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'tck_rcp_pk');
			$t->addIndex(['round_id', 'sort'], 'tck_rcp_round_idx');
		}

		if (!$schema->hasTable('tck_event')) {
			$t = $schema->createTable('tck_event');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('round_id', 'bigint', ['notnull' => false]);
			$t->addColumn('round_cp_id', 'bigint', ['notnull' => false]);
			$t->addColumn('checkpoint_id', 'bigint', ['notnull' => false]);
			$t->addColumn('client_event_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('actor_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('station_member_id', 'bigint', ['notnull' => false]);
			$t->addColumn('station_id', 'bigint', ['notnull' => false]);
			$t->addColumn('method', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('attester_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('attestation_reason', 'string', ['notnull' => false, 'length' => 1000]);
			$t->addColumn('server_received_at', 'datetime', ['notnull' => true]);
			$t->addColumn('client_ts', 'datetime', ['notnull' => false]);
			$t->addColumn('values_json', 'text', ['notnull' => false]);
			$t->addColumn('photo_fileid', 'bigint', ['notnull' => false]);
			$t->addColumn('note', 'string', ['notnull' => false, 'length' => 2000]);
			$t->addColumn('late_sync', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('flagged_early', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('orphan', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('tombstoned', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_event_pk');
			$t->addUniqueIndex(['client_event_id'], 'tck_ev_cid_uq');
			$t->addIndex(['site_id', 'created_at'], 'tck_ev_site_ts_idx');
			$t->addIndex(['round_id'], 'tck_ev_round_idx');
			$t->addIndex(['checkpoint_id'], 'tck_ev_cp_idx');
		}

		if (!$schema->hasTable('tck_station')) {
			$t = $schema->createTable('tck_station');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('checkpoint_id', 'bigint', ['notnull' => false]);
			$t->addColumn('secret_hash', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => false]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->addColumn('pairing_code_hash', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('pairing_expires', 'datetime', ['notnull' => false]);
			$t->addColumn('last_seen_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_station_pk');
			$t->addIndex(['site_id', 'active'], 'tck_st_site_act_idx');
		}

		if (!$schema->hasTable('tck_station_mbr')) {
			$t = $schema->createTable('tck_station_mbr');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('station_id', 'bigint', ['notnull' => true]);
			$t->addColumn('display_name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('pin_hash', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('fail_count', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('lockout_until', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_stmbr_pk');
			$t->addIndex(['station_id', 'active'], 'tck_stmbr_st_idx');
		}

		if (!$schema->hasTable('tck_incident')) {
			$t = $schema->createTable('tck_incident');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('round_id', 'bigint', ['notnull' => false]);
			$t->addColumn('checkpoint_id', 'bigint', ['notnull' => false]);
			$t->addColumn('event_id', 'bigint', ['notnull' => false]);
			$t->addColumn('client_incident_id', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('category', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('severity', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('title', 'string', ['notnull' => false, 'length' => 400]);
			$t->addColumn('note', 'text', ['notnull' => false]);
			$t->addColumn('photo_fileid', 'bigint', ['notnull' => false]);
			$t->addColumn('created_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('member_id', 'bigint', ['notnull' => false]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_inc_pk');
			$t->addUniqueIndex(['client_incident_id'], 'tck_inc_cid_uq');
			$t->addIndex(['site_id', 'status'], 'tck_inc_site_st_idx');
		}

		if (!$schema->hasTable('tck_corrective')) {
			$t = $schema->createTable('tck_corrective');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('incident_id', 'bigint', ['notnull' => true]);
			$t->addColumn('text', 'string', ['notnull' => true, 'length' => 1000]);
			$t->addColumn('assignee_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('due_at', 'date', ['notnull' => false]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('resolution', 'string', ['notnull' => false, 'length' => 1000]);
			$t->addColumn('verified_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('verified_at', 'datetime', ['notnull' => false]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_corr_pk');
			$t->addIndex(['incident_id'], 'tck_corr_inc_idx');
			$t->addIndex(['due_at', 'status'], 'tck_corr_due_idx');
		}

		if (!$schema->hasTable('tck_position')) {
			$t = $schema->createTable('tck_position');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('round_id', 'bigint', ['notnull' => true]);
			$t->addColumn('actor_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('lat', 'float', ['notnull' => true]);
			$t->addColumn('lon', 'float', ['notnull' => true]);
			$t->addColumn('accuracy_m', 'integer', ['notnull' => false]);
			$t->addColumn('server_received_at', 'datetime', ['notnull' => true]);
			$t->addColumn('client_ts', 'datetime', ['notnull' => false]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_pos_pk');
			$t->addIndex(['round_id'], 'tck_pos_round_idx');
			$t->addIndex(['expires_at'], 'tck_pos_exp_idx');
		}

		if (!$schema->hasTable('tck_journal')) {
			$t = $schema->createTable('tck_journal');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('seq', 'bigint', ['notnull' => true]);
			$t->addColumn('round_id', 'bigint', ['notnull' => false]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 24]);
			$t->addColumn('actor_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('member_id', 'bigint', ['notnull' => false]);
			$t->addColumn('body', 'string', ['notnull' => false, 'length' => 4000]);
			$t->addColumn('payload_ref', 'string', ['notnull' => false, 'length' => 400]);
			$t->addColumn('payload_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('prev_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('entry_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('server_ts', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_jrn_pk');
			$t->addUniqueIndex(['site_id', 'seq'], 'tck_jrn_seq_uq');
			$t->addIndex(['site_id', 'server_ts'], 'tck_jrn_site_ts_idx');
			$t->addIndex(['round_id'], 'tck_jrn_round_idx');
		}

		if (!$schema->hasTable('tck_anchor')) {
			$t = $schema->createTable('tck_anchor');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('seq', 'bigint', ['notnull' => true]);
			$t->addColumn('head_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('trigger_kind', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('tsa_token', 'blob', ['notnull' => false]);
			$t->addColumn('error', 'string', ['notnull' => false, 'length' => 400]);
			$t->addColumn('attempts', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('requested_at', 'datetime', ['notnull' => true]);
			$t->addColumn('anchored_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'tck_anc_pk');
			$t->addIndex(['site_id', 'status'], 'tck_anc_site_st_idx');
		}

		if (!$schema->hasTable('tck_report_sched')) {
			$t = $schema->createTable('tck_report_sched');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('cadence', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('recipient', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('share_policy_json', 'text', ['notnull' => false]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('last_period', 'string', ['notnull' => false, 'length' => 16]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_rsched_pk');
			$t->addUniqueIndex(['site_id', 'cadence', 'recipient'], 'tck_rsched_uq');
		}

		if (!$schema->hasTable('tck_report_dlv')) {
			$t = $schema->createTable('tck_report_dlv');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('sched_id', 'bigint', ['notnull' => false]);
			$t->addColumn('period', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('file_id', 'bigint', ['notnull' => false]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('error', 'string', ['notnull' => false, 'length' => 400]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_rdlv_pk');
			$t->addUniqueIndex(['sched_id', 'period'], 'tck_rdlv_uq');
			$t->addIndex(['site_id', 'created_at'], 'tck_rdlv_site_idx');
		}

		if (!$schema->hasTable('tck_site_member')) {
			$t = $schema->createTable('tck_site_member');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('role', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_smem_pk');
			$t->addUniqueIndex(['site_id', 'uid'], 'tck_smem_uq');
			$t->addIndex(['uid'], 'tck_smem_uid_idx');
		}

		if (!$schema->hasTable('tck_template')) {
			$t = $schema->createTable('tck_template');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('tkey', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('vertical', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('payload_json', 'text', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_tpl_pk');
			$t->addUniqueIndex(['tkey'], 'tck_tpl_key_uq');
		}

		if (!$schema->hasTable('tck_live_link')) {
			$t = $schema->createTable('tck_live_link');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('label', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => false]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_live_pk');
			$t->addUniqueIndex(['token_hash'], 'tck_live_hash_uq');
		}

		if (!$schema->hasTable('tck_notif')) {
			$t = $schema->createTable('tck_notif');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('dedupe_key', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('sent_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_notif_pk');
			$t->addUniqueIndex(['dedupe_key'], 'tck_notif_key_uq');
		}

		if (!$schema->hasTable('tck_license_state')) {
			$t = $schema->createTable('tck_license_state');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('customer_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('valid_until', 'date', ['notnull' => true]);
			$t->addColumn('mobile_seats', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('stations', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('bundle', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('key_applied_at', 'datetime', ['notnull' => true]);
			$t->addColumn('payload_b64', 'text', ['notnull' => true]);
			$t->addColumn('signature_b64', 'text', ['notnull' => true]);
			$t->addColumn('license_fingerprint', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('bound_instance_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id'], 'tck_lic_pk');
		}

		return $schema;
	}
}
