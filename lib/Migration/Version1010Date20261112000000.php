<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1010Date20261112000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// F1: "wichtig" flag — supervisor-facing marker feeding the morning
		// digest; orthogonal to severity (impact) on purpose.
		if ($schema->hasTable('tck_incident')) {
			$table = $schema->getTable('tck_incident');
			if (!$table->hasColumn('important')) {
				$table->addColumn('important', Types::SMALLINT, ['notnull' => false, 'default' => 0]);
			}
		}
		if ($schema->hasTable('tck_logbook')) {
			$table = $schema->getTable('tck_logbook');
			if (!$table->hasColumn('important')) {
				$table->addColumn('important', Types::SMALLINT, ['notnull' => false, 'default' => 0]);
			}
		}
		// F5: ad-hoc rounds — a one-off visit carries no plan row, so plan_id
		// relaxes to NULL (unique (plan_id, scheduled_start) stays correct:
		// NULLs never collide) and `adhoc` marks the origin.
		if ($schema->hasTable('tck_round')) {
			$table = $schema->getTable('tck_round');
			if (!$table->hasColumn('adhoc')) {
				$table->addColumn('adhoc', Types::SMALLINT, ['notnull' => false, 'default' => 0]);
			}
			if ($table->hasColumn('plan_id')) {
				$table->changeColumn('plan_id', ['notnull' => false]);
			}
		}

		return $schema;
	}
}
