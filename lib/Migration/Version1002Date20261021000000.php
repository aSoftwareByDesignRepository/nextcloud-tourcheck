<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NH-02 geo-stamp: optional per-checkpoint location stamp on the evidence
 * event. Columns stay NULL unless the checkpoint has geo configured —
 * minimization is enforced at ingest, not just by convention.
 */
class Version1002Date20261021000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('tck_event')) {
			$t = $schema->getTable('tck_event');
			if (!$t->hasColumn('geo_lat')) {
				$t->addColumn('geo_lat', 'float', ['notnull' => false]);
			}
			if (!$t->hasColumn('geo_lon')) {
				$t->addColumn('geo_lon', 'float', ['notnull' => false]);
			}
			if (!$t->hasColumn('geo_acc')) {
				$t->addColumn('geo_acc', 'integer', ['notnull' => false]);
			}
		}
		return $schema;
	}
}
