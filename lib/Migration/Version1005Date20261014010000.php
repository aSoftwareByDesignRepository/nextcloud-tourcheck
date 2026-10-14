<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NH-15c voice notes: audio evidence attachment on incidents (same
 * append-only appdata storage as photos, audio/* MIME allowlist).
 */
class Version1005Date20261014010000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('tck_incident')) {
			$t = $schema->getTable('tck_incident');
			if (!$t->hasColumn('audio_fileid')) {
				$t->addColumn('audio_fileid', 'bigint', ['notnull' => false]);
			}
		}

		return $schema;
	}
}
