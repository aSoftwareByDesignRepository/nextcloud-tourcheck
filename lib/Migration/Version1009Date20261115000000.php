<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1009Date20261115000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// F18: stores the MaintenanceCheck WO number after handoff — the
		// durable "already filed" state that makes the button idempotent.
		if ($schema->hasTable('tck_incident')) {
			$table = $schema->getTable('tck_incident');
			if (!$table->hasColumn('wo_ref')) {
				$table->addColumn('wo_ref', Types::STRING, ['length' => 40, 'notnull' => false]);
			}
		}

		return $schema;
	}
}
