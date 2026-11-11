<?php

declare(strict_types=1);

/**
 * TourCheck wiring for the shared transfer engine.
 *
 *  - requireTransferAdmin gates on the app's real door-admin role
 *    (AccessControlService::isAppAdmin — system admins + configured app
 *    admins + tourcheck_admin group).
 *  - validateValue/normalizeValue run the SAME rules the settings write
 *    path applies in ApiController::saveSettings (validator parity, NN-08
 *    — import grants no bypass).
 *  - writeMirrored writes the door policy through
 *    SettingsService::setAccess + setAppAdmins in ONE overlay call — never
 *    raw setAppValue (NN-02); TourCheck has no NC app-restriction mirror —
 *    the app-side door IS the authority.
 *  - createSafetySnapshot delegates to UpgradeBackupService for the
 *    mandatory pre-replace snapshot (FS-2).
 */

namespace OCA\TourCheck\ConfigTransfer;

use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\UpgradeBackupService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	private const DOOR_KEYS = [
		'access_mode',
		'allowed_groups',
		'allowed_users',
		'app_admins',
	];

	/** saveSettings numeric allowlist (bounded on read by SettingsService). */
	private const INT_KEYS = [
		'late_sync_days',
		'position_retention_hours',
		'journal_retention_years',
		'pin_length',
		'pin_lockout_attempts',
		'pin_lockout_minutes',
		'sla_hit_target_pct',
		'sla_punctuality_min',
	];

	private const INTERNAL_KEYS = [
		'last_cron_run_at',
		'schema_incomplete',
		'uninstall_repair_pass',
		'upgrade_backup_last_snapshot_id',
		'upgrade_backup_max_snapshots',
		'transfer_receipts',
		'transfer_job_state',
		'transfer_last_import_at',
	];

	public function __construct(
		private readonly AccessControlService $access,
		private readonly SettingsService $settings,
		private readonly UpgradeBackupService $backups,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		if ($uid === '' || !$this->access->isAppAdmin($uid)) {
			// Uniform 404 — the door must not disclose the app exists.
			throw new NotFoundException();
		}
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	/**
	 * Same rules as ApiController::saveSettings — what lands in appconfig is
	 * byte-identical to what the settings API would have stored.
	 */
	private function normalize(string $key, string $value): string
	{
		return match (true) {
			$key === 'access_mode' => in_array($value, ['open', 'restricted'], true)
				? $value
				: throw new \InvalidArgumentException('INVALID_ACCESS_MODE'),
			$key === 'default_timezone' => in_array($value, \DateTimeZone::listIdentifiers(), true)
				? $value
				: throw new \InvalidArgumentException('INVALID_TIMEZONE'),
			// saveSettings resolves the provider picker server-side — a
			// stored value is '' (schematic), the OSM preset, or a validated
			// custom https template (same isValidTileTemplate gate).
			$key === 'map_tile_url' => $value === '' || SettingsService::isValidTileTemplate($value)
				? trim($value)
				: throw new \InvalidArgumentException('INVALID_TILE_URL'),
			$key === 'map_tile_attribution' => mb_substr(trim(strip_tags($value)), 0, 300),
			$key === 'tsa_url' => $value === '' || str_starts_with($value, 'https://')
				? trim($value)
				: throw new \InvalidArgumentException('INVALID_TSA_URL'),
			// Absolute PEM path — env-class so it never transfers, but a
			// hand-edited file still must satisfy the settings rule.
			$key === 'tsa_ca_bundle' => $value === '' || str_starts_with($value, '/')
				? trim($value)
				: throw new \InvalidArgumentException('INVALID_TSA_CA'),
			in_array($key, self::INT_KEYS, true) => preg_match('/^-?\d+$/', $value) === 1 && (int)$value >= 0
				? (string)(int)$value
				: throw new \InvalidArgumentException('INVALID_INT'),
			// setBranding: name is trimmed, color stored verbatim (the read
			// side fail-closes on non-#rrggbb — a mistyped color degrades
			// to "unset", never breaks).
			$key === 'brand_name' => trim($value),
			$key === 'brand_color' => $value,
			in_array($key, self::DOOR_KEYS, true) => $this->normalizeIdList($value),
			default => strlen($value) <= 65536
				? $value
				: throw new \InvalidArgumentException('TOO_LONG'),
		};
	}

	private function normalizeIdList(string $value): string
	{
		$list = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_ID_LIST');
		}
		$clean = [];
		foreach ($list as $id) {
			if (is_string($id) && $id !== '') {
				$clean[] = $id;
			}
		}
		return json_encode(array_values(array_unique($clean)), JSON_THROW_ON_ERROR);
	}

	public function validateMirrored(array $keyValues): ?string
	{
		$decoded = $this->decodeDoor($keyValues);
		if ($decoded === null) {
			return 'INVALID_POLICY_JSON';
		}
		[$mode, $groups, $users, $admins] = $decoded;
		foreach ($groups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				return 'INVALID_ALLOWED_GROUP';
			}
		}
		foreach (array_merge($users, $admins) as $uid) {
			if (!$this->userManager->userExists($uid)) {
				return 'INVALID_ALLOWED_USER';
			}
		}
		// restricted + empty lists is legal (tourcheck_* role groups and
		// admins still grant access) — no extra invariant to enforce.
		return null;
	}

	public function writeMirrored(array $keyValues): void
	{
		$decoded = $this->decodeDoor($keyValues);
		if ($decoded === null) {
			throw new \InvalidArgumentException('INVALID_POLICY_JSON');
		}
		[$mode, $groups, $users, $admins] = $decoded;
		// The app's central door writers — the overlay guarantees a partial
		// transfer file never blanks the lists it does not mention.
		$this->settings->setAccess($mode, $groups, $users);
		$this->settings->setAppAdmins($admins);
	}

	/**
	 * Overlay the transferred door keys onto the current policy, so a file
	 * that carries only a subset never blanks the rest.
	 *
	 * @return array{0:string,1:list<string>,2:list<string>,3:list<string>}|null
	 */
	private function decodeDoor(array $kv): ?array
	{
		$mode = $this->settings->getAccessMode();
		$groups = $this->settings->getAllowedGroups();
		$users = $this->settings->getAllowedUsers();
		$admins = $this->settings->getAppAdmins();
		if (isset($kv['access_mode'])) {
			if (!in_array($kv['access_mode'], ['open', 'restricted'], true)) {
				return null;
			}
			$mode = $kv['access_mode'];
		}
		foreach (['allowed_groups', 'allowed_users', 'app_admins'] as $key) {
			if (!isset($kv[$key])) {
				continue;
			}
			$list = json_decode($kv[$key], true);
			if (!is_array($list) || !array_is_list($list)) {
				return null;
			}
			$clean = array_values(array_map('strval', $list));
			match ($key) {
				'allowed_groups' => $groups = $clean,
				'allowed_users' => $users = $clean,
				default => $admins = $clean,
			};
		}
		return [$mode, $groups, $users, $admins];
	}

	public function securityRelevantKeys(): array
	{
		return self::DOOR_KEYS;
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			// Fresh instance with no data — no snapshot, but rethrow real
			// failures on non-empty DBs so replace is never unprotected.
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match (true) {
			in_array($key, self::DOOR_KEYS, true) => 'access',
			in_array($key, ['default_timezone', 'map_tile_url', 'map_tile_attribution', 'sla_hit_target_pct', 'sla_punctuality_min'], true) => 'site-defaults',
			in_array($key, ['tsa_url', 'tsa_ca_bundle', 'late_sync_days', 'chain_secret', 'nfc_sign_secret'], true) => 'evidence',
			str_ends_with($key, '_retention_hours'), str_ends_with($key, '_retention_years') => 'retention',
			str_starts_with($key, 'pin_') => 'stations',
			str_starts_with($key, 'brand_') => 'branding',
			// Internal bookkeeping keys get no "set it on this instance" link.
			in_array($key, self::INTERNAL_KEYS, true) => null,
			default => 'access',
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		// TourCheck has no app audit table — the journal is the per-site
		// evidence chain, not an admin log. The contract's documented
		// fallback is the app logger; never silent.
		try {
			$this->logger->info('tourcheck: config transfer event', [
				'app' => 'tourcheck',
				'action' => 'config_transfer_' . $action,
				'actor' => $actor === '' ? 'cli' : $actor,
				'details' => $details,
			]);
		} catch (\Throwable) {
			// logging must never fail the transfer itself
		}
	}
}
