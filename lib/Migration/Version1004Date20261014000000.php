<?php

declare(strict_types=1);

namespace OCA\TourCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NH-06 kiosk signature: finger-signature image bound to the check event,
 * stored through the photo pipeline (EXIF strip + MIME sniff + size cap).
 */
class Version1004Date20261014000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('tck_event')) {
			$t = $schema->getTable('tck_event');
			if (!$t->hasColumn('signature_fileid')) {
				$t->addColumn('signature_fileid', 'bigint', ['notnull' => false]);
			}
		}

		return $schema;
	}
}
