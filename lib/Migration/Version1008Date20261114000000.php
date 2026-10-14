<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Persist the PDF audience on each report delivery so portal sharing can be
 * gated on "was this generated redacted" rather than derived state.
 */
class Version1008Date20261114000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('tck_report_dlv')) {
			$t = $schema->getTable('tck_report_dlv');
			if (!$t->hasColumn('audience')) {
				$t->addColumn('audience', 'string', ['notnull' => true, 'default' => 'internal', 'length' => 16]);
			}
		}

		return $schema;
	}
}
