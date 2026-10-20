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
 * (arbeitszeitcheck 1.9.4 / GH #45 lesson).
 */
class EnsureTourCheckSchema implements IRepairStep
{
	private const APP_ID = 'tourcheck';
	private const CONFIG_CHAIN_SECRET = 'chain_secret';

	private const TABLES = [
		'tck_site', 'tck_checkpoint', 'tck_cp_token', 'tck_plan', 'tck_round',
		'tck_round_cp', 'tck_event', 'tck_station', 'tck_station_mbr',
		'tck_incident', 'tck_corrective', 'tck_position', 'tck_journal',
		'tck_anchor', 'tck_report_sched', 'tck_report_dlv', 'tck_site_member',
		'tck_template', 'tck_live_link', 'tck_notif', 'tck_license_state',
		'tck_key', 'tck_key_log',
		'tck_geofence', 'tck_geofence_state', 'tck_alert', 'tck_alert_delivery',
		'tck_escalation_leg', 'tck_task', 'tck_logbook', 'tck_logbook_ack',
		'tck_client_grant', 'tck_worker_qual', 'tck_position_consent',
		'tck_mandown_state', 'tck_webhook', 'tck_webhook_delivery',
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
