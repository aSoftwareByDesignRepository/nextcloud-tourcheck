<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Station-level PIN lockout (spec §4): members lock individually, the
 * station locks too so member-id probing can never escape the counter.
 */
class Version1001Date20261020000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('tck_station')) {
			$t = $schema->getTable('tck_station');
			if (!$t->hasColumn('fail_count')) {
				$t->addColumn('fail_count', 'integer', ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('lockout_until')) {
				$t->addColumn('lockout_until', 'datetime', ['notnull' => false]);
			}
		}
		return $schema;
	}
}
