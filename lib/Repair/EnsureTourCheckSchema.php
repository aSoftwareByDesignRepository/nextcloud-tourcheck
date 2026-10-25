<?php

declare(strict_types=1);

namespace OCA\TourCheck\Repair;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Install-time schema safety net: if a migration ever reports success
 * without its tables materializing, the repair step fails loudly here
 * instead of letting the app boot half-installed.
 *
 * App id and config keys are inlined: repair steps run during the in-request
 * app update while the previously installed `Constants` class may still be
 * loaded, so referencing it can fatal with "Undefined constant"
 * (arbeitszeitcheck 1.9.4 / GH #45 lesson). The expected-table set delegates
 * to {@see SchemaTableCatalog} — a class introduced with it, so no stale
 * in-memory copy can exist.
 */
class EnsureTourCheckSchema implements IRepairStep
{
	private const APP_ID = 'tourcheck';
	private const CONFIG_CHAIN_SECRET = 'chain_secret';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure TourCheck schema and chain secret';
	}

	public function run(IOutput $output): void
	{
		$missing = [];
		foreach (SchemaTableCatalog::EXPECTED_TABLES as $table) {
			if (!$this->db->tableExists($table)) {
				$missing[] = $table;
			}
		}
		if ($missing !== []) {
			// Mark schema incomplete → the app stays disabled rather than
			// booting against half a schema (arbeitszeitcheck 1.4.x lesson).
			$this->config->setAppValue(self::APP_ID, 'schema_incomplete', '1');
			throw new \RuntimeException('TourCheck schema incomplete, missing: ' . implode(', ', $missing));
		}
		$this->config->deleteAppValue(self::APP_ID, 'schema_incomplete');
		// Chain secret in config.php — generated lazily but ensure it exists
		// so first-use never races a writer.
		if ($this->config->getSystemValueString(self::APP_ID . '.' . self::CONFIG_CHAIN_SECRET, '') === '') {
			$this->config->setSystemValue(self::APP_ID . '.' . self::CONFIG_CHAIN_SECRET, sodium_bin2hex(random_bytes(32)));
			$output->info('TourCheck chain secret generated');
		}
	}
}
