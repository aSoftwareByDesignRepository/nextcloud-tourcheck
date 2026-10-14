<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NH-10 follow-up: the key ledger must record the CUSTODIAN's uid too —
 * a manager can take/return a key on behalf of another user, so actor_uid
 * alone loses who actually held the key.
 */
class Version1006Date20261015000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('tck_key_log')) {
			$t = $schema->getTable('tck_key_log');
			if (!$t->hasColumn('holder_uid')) {
				$t->addColumn('holder_uid', 'string', ['notnull' => false, 'length' => 64]);
			}
		}

		return $schema;
	}
}
