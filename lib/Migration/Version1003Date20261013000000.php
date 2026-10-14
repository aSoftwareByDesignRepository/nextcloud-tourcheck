<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NH-07 dead-man check-in lite (plan checkin_interval), NH-10 key register
 * (tck_key + tck_key_log), NH-14 site briefing (Objekt-Hinweise).
 */
class Version1003Date20261013000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('tck_plan')) {
			$t = $schema->getTable('tck_plan');
			if (!$t->hasColumn('checkin_interval_min')) {
				// Minutes without activity before the dead-man escalation
				// fires — NULL/0 disables. Written out literally (not via a
				// looped table var) so the schema-drift auditor sees it.
				$t->addColumn('checkin_interval_min', 'integer', ['notnull' => false]);
			}
		}

		if ($schema->hasTable('tck_site')) {
			$t = $schema->getTable('tck_site');
			if (!$t->hasColumn('briefing')) {
				// Objekt-Hinweise: alarm codes, hazards, dogs, emergency
				// contacts — shown once per round start, journaled.
				$t->addColumn('briefing', 'text', ['notnull' => false]);
			}
		}

		if (!$schema->hasTable('tck_key')) {
			$t = $schema->createTable('tck_key');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 190]);
			// out|in — a key out means holder_uid/holder_member_id identifies
			// the custodian; exactly one holder, enforced by transition rules.
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 8, 'default' => 'in']);
			$t->addColumn('holder_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('holder_member_id', 'bigint', ['notnull' => false]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_key_pk');
			$t->addIndex(['site_id', 'status'], 'tck_key_site_st_idx');
		}

		if (!$schema->hasTable('tck_key_log')) {
			// Append-only take/return ledger — the movement history IS the
			// evidence; entries are also chained via kind=admin journal rows.
			$t = $schema->createTable('tck_key_log');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('key_id', 'bigint', ['notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('action', 'string', ['notnull' => true, 'length' => 8]); // take|return
			$t->addColumn('actor_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('member_id', 'bigint', ['notnull' => false]);
			$t->addColumn('note', 'string', ['notnull' => false, 'length' => 500]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'tck_keylog_pk');
			$t->addIndex(['key_id', 'created_at'], 'tck_keylog_key_idx');
			$t->addIndex(['site_id', 'created_at'], 'tck_keylog_site_idx');
		}

		return $schema;
	}
}
