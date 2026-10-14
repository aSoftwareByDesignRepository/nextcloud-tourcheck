<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Config\InstanceId;
use OCA\TourCheck\Db\LicenseState;
use OCA\TourCheck\Db\LicenseStateMapper;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\License\LicenseFingerprint;
use OCA\TourCheck\License\Tck2Codec;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCA\TourCheck\Support\LockGuard;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * TCK2 org license. The WEB app is never gated — licensing gates the paid
 * companion surface only (mobile seats + station devices), per the product
 * model. applyLicenseKey is atomic under a named lock.
 */
class LicenseService
{
	private const APPLY_LOCK = 'tourcheck/license_apply';
	private string $lastApplyError = '';

	public function __construct(
		private readonly LicenseStateMapper $licenseStateMapper,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly InstanceId $instanceId,
		private readonly ILockingProvider $locking,
	) {
	}

	public function getLastApplyErrorCode(): string
	{
		return $this->lastApplyError;
	}

	public function applyLicenseKey(string $wireKey): bool
	{
		$this->lastApplyError = '';
		$error = Tck2Codec::classifyApplyError($wireKey);
		if ($error !== '') {
			$this->lastApplyError = $error;
			$this->logger->warning('TCK2 license apply rejected', ['code' => $error]);
			return false;
		}
		$parsed = Tck2Codec::parseAndVerify($wireKey);
		if ($parsed === null) {
			$this->lastApplyError = Tck2Codec::ERROR_INVALID_FORMAT;
			return false;
		}
		$payload = $parsed['payload'];
		$fingerprint = LicenseFingerprint::fromWireParts($parsed['payloadB64'], $parsed['signatureB64']);
		$now = $this->timeFactory->getDateTime();

		$state = new LicenseState();
		$state->setCustomerId((string)$payload['customerId']);
		$state->setValidUntil(new \DateTime((string)$payload['validUntil']));
		$state->setMobileSeats((int)$payload['mobileSeats']);
		$state->setStations((int)$payload['terminalDevices']);
		$state->setBundle(!empty($payload['bundle']) ? 1 : 0);
		$state->setKeyAppliedAt($now);
		$state->setPayloadB64($parsed['payloadB64']);
		$state->setSignatureB64($parsed['signatureB64']);
		$state->setLicenseFingerprint($fingerprint);
		$state->setBoundInstanceId($this->instanceId->get());

		try {
			LockGuard::run($this->locking, self::APPLY_LOCK, fn () => $this->licenseStateMapper->upsert($state));
		} catch (LockedException) {
			$this->lastApplyError = 'license_busy';
			$this->logger->warning('TCK2 license apply busy');
			return false;
		}
		$this->logger->info('TCK2 license applied', ['customerId' => $payload['customerId']]);
		return true;
	}

	public function clearLicense(): void
	{
		try {
			LockGuard::run($this->locking, self::APPLY_LOCK, function (): void {
				$this->licenseStateMapper->deleteAll();
				$this->logger->info('TCK2 license cleared');
			});
		} catch (LockedException) {
			throw new DomainException('license_busy', 'License update in progress.', 409);
		}
	}

	private function isLicenseValid(LicenseState $state): bool
	{
		$until = $state->getValidUntil();
		if ($until === null) {
			return false;
		}
		$today = new \DateTime('today');
		return $until >= $today;
	}

	/** Tamper check: stored payload bytes must re-hash to the stored fingerprint. */
	private function isStateCryptographicallyValid(LicenseState $state): bool
	{
		$payloadB64 = (string)$state->getPayloadB64();
		$sigB64 = (string)$state->getSignatureB64();
		if ($payloadB64 === '' || $sigB64 === '') {
			return false;
		}
		$expected = LicenseFingerprint::fromWireParts($payloadB64, $sigB64);
		if (!hash_equals($expected, (string)$state->getLicenseFingerprint())) {
			return false;
		}
		// Re-verify the signature over the stored payload — a forged row with a
		// recomputed fingerprint still fails Ed25519.
		$payloadBytes = \OCA\TourCheck\Config\VendorPublicKey::base64urlDecode($payloadB64);
		$sig = \OCA\TourCheck\Config\VendorPublicKey::base64urlDecode($sigB64);
		if ($payloadBytes === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
			return false;
		}
		return sodium_crypto_sign_verify_detached($sig, $payloadBytes, \OCA\TourCheck\Config\VendorPublicKey::bytes());
	}

	private function isBoundToThisInstance(LicenseState $state): bool
	{
		return hash_equals((string)$state->getBoundInstanceId(), $this->instanceId->get());
	}

	private function getCurrentStateIfFullyValid(): ?LicenseState
	{
		$state = $this->licenseStateMapper->findCurrent();
		if ($state === null) {
			return null;
		}
		if (!$this->isLicenseValid($state) || !$this->isStateCryptographicallyValid($state) || !$this->isBoundToThisInstance($state)) {
			return null;
		}
		return $state;
	}

	/** @return array{format:string,payloadB64:string,signatureB64:string}|null envelope for @check/onboarding */
	public function buildEnvelope(): ?array
	{
		$state = $this->getCurrentStateIfFullyValid();
		if ($state === null) {
			return null;
		}
		return [
			'format' => Tck2Codec::FORMAT,
			'payloadB64' => $state->getPayloadB64(),
			'signatureB64' => $state->getSignatureB64(),
		];
	}

	public function isMobilePlanActive(): bool
	{
		$state = $this->getCurrentStateIfFullyValid();
		return $state !== null && (int)$state->getMobileSeats() > 0;
	}

	public function isStationPlanActive(): bool
	{
		$state = $this->getCurrentStateIfFullyValid();
		return $state !== null && (int)$state->getStations() > 0;
	}

	public function getMobileSeatLimit(): int
	{
		$state = $this->getCurrentStateIfFullyValid();
		return $state === null ? 0 : max(0, (int)$state->getMobileSeats());
	}

	public function getStationLimit(): int
	{
		$state = $this->getCurrentStateIfFullyValid();
		return $state === null ? 0 : max(0, (int)$state->getStations());
	}

	public function hasStoredLicense(): bool
	{
		return $this->licenseStateMapper->findCurrent() !== null;
	}

	/** @return array<string,mixed>|null admin-facing summary (never blocks web access) */
	public function getLicenseSummary(): ?array
	{
		$state = $this->licenseStateMapper->findCurrent();
		if ($state === null) {
			return null;
		}
		$dateValid = $this->isLicenseValid($state);
		$cryptoValid = $this->isStateCryptographicallyValid($state);
		$instanceValid = $this->isBoundToThisInstance($state);
		return [
			'customerId' => $state->getCustomerId(),
			'validUntil' => $state->getValidUntil()?->format('Y-m-d'),
			'mobileSeats' => (int)$state->getMobileSeats(),
			'stations' => (int)$state->getStations(),
			'bundle' => (int)$state->getBundle() === 1,
			'active' => $dateValid && $cryptoValid && $instanceValid,
			'dateValid' => $dateValid,
			'cryptographicallyValid' => $cryptoValid,
			'instanceValid' => $instanceValid,
			'keyAppliedAt' => $state->getKeyAppliedAt()?->format('c'),
			'boundInstanceId' => $state->getBoundInstanceId(),
		];
	}

	/** Companion gate: Family A (worker) needs a mobile plan. */
	public function requireMobileLicense(): void
	{
		if (!$this->isMobilePlanActive()) {
			throw new DomainException('no_seat', 'A mobile seat is required for the companion app.', 402);
		}
	}

	/**
	 * Companion gate: operating an enrolled station requires an active station
	 * plan. The device-count limit is enforced only at enrollment
	 * (StationService::create) — an over-limit count must never brick
	 * already-enrolled devices (self-DoS via spare station records).
	 */
	public function requireStationPlan(): void
	{
		if (!$this->isStationPlanActive()) {
			throw new DomainException('license_required', 'A station license is required.', 402);
		}
	}

	/** Enrollment gate: the next station record consumes one device seat. */
	public function requireStationLicense(int $nextStationCount): void
	{
		$this->requireStationPlan();
		if ($nextStationCount > $this->getStationLimit()) {
			throw new DomainException('no_device', 'Station device limit reached.', 402);
		}
	}
}
