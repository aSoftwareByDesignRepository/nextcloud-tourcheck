<?php

declare(strict_types=1);

namespace OCA\TourCheck\Repair;

use OCA\TourCheck\Constants;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Install-time schema safety net: if a migration ever reports success
 * without its tables materializing, the repair step fails loudly here
 * instead of letting the app boot half-installed.
 */
class EnsureTourCheckSchema implements IRepairStep
{
	private const TABLES = [
		'tck_site', 'tck_checkpoint', 'tck_cp_token', 'tck_plan', 'tck_round',
		'tck_round_cp', 'tck_event', 'tck_station', 'tck_station_mbr',
		'tck_incident', 'tck_corrective', 'tck_position', 'tck_journal',
		'tck_anchor', 'tck_report_sched', 'tck_report_dlv', 'tck_site_member',
		'tck_template', 'tck_live_link', 'tck_notif', 'tck_license_state',
		'tck_key', 'tck_key_log',
	];

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
		foreach (self::TABLES as $table) {
			if (!$this->db->tableExists($table)) {
				$missing[] = $table;
			}
		}
		if ($missing !== []) {
			// Mark schema incomplete → the app stays disabled rather than
			// booting against half a schema (arbeitszeitcheck 1.4.x lesson).
			$this->config->setAppValue(Constants::APP_ID, 'schema_incomplete', '1');
			throw new \RuntimeException('TourCheck schema incomplete, missing: ' . implode(', ', $missing));
		}
		$this->config->deleteAppValue(Constants::APP_ID, 'schema_incomplete');
		// Chain secret in config.php — generated lazily but ensure it exists
		// so first-use never races a writer.
		if ($this->config->getSystemValueString(Constants::APP_ID . '.' . Constants::CONFIG_CHAIN_SECRET, '') === '') {
			$this->config->setSystemValue(Constants::APP_ID . '.' . Constants::CONFIG_CHAIN_SECRET, sodium_bin2hex(random_bytes(32)));
			$output->info('TourCheck chain secret generated');
		}
	}
}
