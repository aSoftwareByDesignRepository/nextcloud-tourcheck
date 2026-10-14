<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCP\IConfig;

/**
 * App-level settings incl. the access door (open/restricted) and delegated
 * app admins. Values are app config — never site state, never license state.
 */
class SettingsService
{
	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function getAccessMode(): string
	{
		$m = $this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_ACCESS_MODE, 'open');
		return $m === 'restricted' ? 'restricted' : 'open';
	}

	/** @return list<string> */
	public function getAllowedGroups(): array
	{
		return $this->jsonList(Constants::CONFIG_ALLOWED_GROUPS);
	}

	/** @return list<string> */
	public function getAllowedUsers(): array
	{
		return $this->jsonList(Constants::CONFIG_ALLOWED_USERS);
	}

	/** @return list<string> */
	public function getAppAdmins(): array
	{
		return $this->jsonList(Constants::CONFIG_APP_ADMINS);
	}

	public function setAccess(string $mode, array $groups, array $users): void
	{
		$this->config->setAppValue(Constants::APP_ID, Constants::CONFIG_ACCESS_MODE, $mode === 'restricted' ? 'restricted' : 'open');
		$this->config->setAppValue(Constants::APP_ID, Constants::CONFIG_ALLOWED_GROUPS, json_encode(array_values($groups)));
		$this->config->setAppValue(Constants::APP_ID, Constants::CONFIG_ALLOWED_USERS, json_encode(array_values($users)));
	}

	public function setAppAdmins(array $uids): void
	{
		$this->config->setAppValue(Constants::APP_ID, Constants::CONFIG_APP_ADMINS, json_encode(array_values($uids)));
	}

	public function getDefaultTimezone(): string
	{
		return $this->config->getAppValue(Constants::APP_ID, 'default_timezone', 'Europe/Berlin');
	}

	public function getTsaUrl(): string
	{
		return trim($this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_TSA_URL, ''));
	}

	public function setDefaultTimezone(string $tz): void
	{
		$this->config->setAppValue(Constants::APP_ID, 'default_timezone', $tz);
	}

	public function setTsaUrl(string $url): void
	{
		$this->config->setAppValue(Constants::APP_ID, Constants::CONFIG_TSA_URL, trim($url));
	}

	/** Optional PEM CA bundle pinning TSA chain-of-trust verification. */
	public function getTsaCaBundle(): string
	{
		return trim($this->config->getAppValue(Constants::APP_ID, 'tsa_ca_bundle', ''));
	}

	public function setTsaCaBundle(string $path): void
	{
		$this->config->setAppValue(Constants::APP_ID, 'tsa_ca_bundle', trim($path));
	}

	public function getLateSyncDays(): int
	{
		$n = (int)$this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_LATE_SYNC_DAYS, '7');
		return max(1, min(30, $n));
	}

	public function getPositionRetentionHours(): int
	{
		$n = (int)$this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_POSITION_RETENTION_H, '24');
		return max(1, min(168, $n));
	}

	public function getJournalRetentionYears(): int
	{
		$n = (int)$this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_JOURNAL_RETENTION_Y, '10');
		return max(1, min(30, $n));
	}

	public function getPinLength(): int
	{
		$n = (int)$this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_PIN_LENGTH, '6');
		return max(4, min(8, $n));
	}

	public function getPinLockoutAttempts(): int
	{
		$n = (int)$this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_PIN_LOCKOUT_N, '5');
		return max(3, min(10, $n));
	}

	public function getPinLockoutMinutes(): int
	{
		$n = (int)$this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_PIN_LOCKOUT_MIN, '15');
		return max(1, min(120, $n));
	}

	public function getBrandName(): string
	{
		return trim($this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_BRAND_NAME, ''));
	}

	public function getBrandColor(): string
	{
		$c = trim($this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_BRAND_COLOR, ''));
		return preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1 ? $c : '';
	}

	/** Generic numeric app setting (whitelisted by the caller). */
	public function setNumeric(string $key, int $value): void
	{
		$this->config->setAppValue(Constants::APP_ID, $key, (string)$value);
	}

	public function setBranding(string $name, string $color): void
	{
		$this->config->setAppValue(Constants::APP_ID, Constants::CONFIG_BRAND_NAME, trim($name));
		$this->config->setAppValue(Constants::APP_ID, Constants::CONFIG_BRAND_COLOR, $color);
	}

	public function markCronRun(\DateTimeInterface $at): void
	{
		$this->config->setAppValue(Constants::APP_ID, Constants::CONFIG_LAST_CRON_RUN, $at->format(\DateTimeInterface::ATOM));
	}

	public function getLastCronRun(): ?\DateTimeImmutable
	{
		$v = $this->config->getAppValue(Constants::APP_ID, Constants::CONFIG_LAST_CRON_RUN, '');
		if ($v === '') {
			return null;
		}
		$d = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $v);
		return $d === false ? null : $d;
	}

	/**
	 * Evidence chain secret — system config (config.php), never the database.
	 * A DB dump alone cannot re-chain (spec: evidence integrity).
	 */
	public function getChainSecret(): string
	{
		$secret = $this->config->getSystemValueString(Constants::APP_ID . '.' . Constants::CONFIG_CHAIN_SECRET, '');
		if ($secret === '') {
			$secret = sodium_bin2hex(random_bytes(32));
			$this->config->setSystemValue(Constants::APP_ID . '.' . Constants::CONFIG_CHAIN_SECRET, $secret);
		}
		return $secret;
	}

	/** Per-site chain seed: HMAC(secret, site) — site-bound genesis, single secret to back up. */
	public function siteChainSeed(int $siteId): string
	{
		return hash_hmac('sha256', 'site:' . $siteId, $this->getChainSecret());
	}

	/**
	 * NH-11 signed-NFC keypair seed — system config, same custody as the
	 * chain secret (a DB dump can neither mint nor verify tag signatures).
	 */
	public function getNfcSignSecret(): string
	{
		$secret = $this->config->getSystemValueString(Constants::APP_ID . '.nfc_sign_secret', '');
		if ($secret === '') {
			$secret = sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
			$this->config->setSystemValue(Constants::APP_ID . '.nfc_sign_secret', $secret);
		}
		return $secret;
	}

	/**
	 * Per-site Ed25519 keypair for signing claim-bundle manifests — derived
	 * (domain-separated) from the NFC sign secret, so it inherits the same
	 * config.php custody and never needs a second secret to back up.
	 * @return array{pk:string,sk:string} raw key material
	 */
	public function bundleSignKeypair(int $siteId): array
	{
		$seed = sodium_crypto_generichash(
			'tck-bundle-sign-v1:' . $siteId,
			sodium_hex2bin($this->getNfcSignSecret()),
			SODIUM_CRYPTO_SIGN_SEEDBYTES,
		);
		$kp = sodium_crypto_sign_seed_keypair($seed);
		sodium_memzero($seed);
		return ['pk' => sodium_crypto_sign_publickey($kp), 'sk' => sodium_crypto_sign_secretkey($kp)];
	}

	/** @return list<string> */
	private function jsonList(string $key): array
	{
		$raw = $this->config->getAppValue(Constants::APP_ID, $key, '[]');
		$v = json_decode($raw, true);
		if (!is_array($v)) {
			return [];
		}
		return array_values(array_filter(array_map('strval', $v), static fn ($s) => $s !== ''));
	}

	/** @return array<string,mixed> */
	public function snapshot(): array
	{
		return [
			'accessMode' => $this->getAccessMode(),
			'defaultTimezone' => $this->getDefaultTimezone(),
			'allowedGroups' => $this->getAllowedGroups(),
			'allowedUsers' => $this->getAllowedUsers(),
			'appAdmins' => $this->getAppAdmins(),
			'tsaUrl' => $this->getTsaUrl(),
			'tsaCaBundle' => $this->getTsaCaBundle(),
			'lateSyncDays' => $this->getLateSyncDays(),
			'positionRetentionHours' => $this->getPositionRetentionHours(),
			'journalRetentionYears' => $this->getJournalRetentionYears(),
			'pinLength' => $this->getPinLength(),
			'pinLockoutAttempts' => $this->getPinLockoutAttempts(),
			'pinLockoutMinutes' => $this->getPinLockoutMinutes(),
			'brandName' => $this->getBrandName(),
			'brandColor' => $this->getBrandColor(),
		];
	}
}
