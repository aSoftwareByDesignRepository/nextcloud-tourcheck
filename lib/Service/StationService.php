<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Station;
use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\StationMapper;
use OCA\TourCheck\Db\StationMember;
use OCA\TourCheck\Db\StationMemberMapper;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Support\LockGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\IDBConnection;

/**
 * Stations (kiosk devices). Auth: bearer token = sha256(station_secret).
 * Secrets are generated server-side, shown once at enrollment/pairing.
 * Members authenticate per-confirmation via PIN — never a persistent session
 * (UX-4: no retained identity between workers at the same kiosk).
 */
class StationService
{
	public function __construct(
		private readonly StationMapper $stations,
		private readonly StationMemberMapper $members,
		private readonly CheckpointMapper $checkpoints,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly ChainService $chain,
		private readonly SettingsService $settings,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly LicenseService $license,
		private readonly ILockingProvider $locking,
	) {
	}

	public static function hashSecret(string $secret): string
	{
		return hash('sha256', 'tck_station:' . $secret);
	}

	/** @return array{0: Station, 1: string} raw secret — shown exactly once */
	public function create(int $siteId, string $label, ?int $checkpointId = null): array
	{
		$this->sites->getVisible($siteId);
		$uid = $this->acl->assertCanManageSite($siteId);
		$label = trim($label);
		if ($label === '' || strlen($label) > 190) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Station label required.', ['label' => 'required']);
		}
		// A station record IS a device seat — enforce the license limit where
		// the seat is consumed, serialized so parallel creates can't overshoot.
		return LockGuard::run($this->locking, 'tck.station.enroll',
			fn () => $this->createEnrolled($siteId, $label, $checkpointId, $uid));
	}

	/** @return array{0: Station, 1: string} raw secret — shown exactly once */
	private function createEnrolled(int $siteId, string $label, ?int $checkpointId, string $uid): array
	{
		// Re-check under the enroll lock: create()'s getVisible ran BEFORE
		// lock acquisition — a concurrent archive() may have deactivated the
		// site (and its stations) in between. Without this recheck the insert
		// leaks an active station — and a license seat — onto a dead site.
		$site = $this->sites->findById($siteId);
		if ($site === null || (int)$site->getActive() !== 1) {
			throw new NotFoundException();
		}
		$this->license->requireStationLicense($this->stations->countActive() + 1);
		$secret = 'tcks_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
		$s = new Station();
		$s->setSiteId($siteId);
		$s->setLabel($label);
		$s->setCheckpointId($checkpointId !== null && $checkpointId > 0 ? $checkpointId : null);
		$s->setSecretHash(self::hashSecret($secret));
		$s->setActive(1);
		$s->setCreatedAt($this->timeFactory->getDateTime());
		$s = $this->stations->insert($s);
		$this->journalAdmin($siteId, $uid, 'station_created', 'station:' . $s->getId(), ['station' => (int)$s->getId(), 'site' => $siteId]);
		return [$s, $secret];
	}

	/** Active station device count — the license device limit. */
	public function countActive(): int
	{
		return $this->stations->countActive();
	}

	/** @return list<Station> */
	/** The checkpoint a kiosk confirm counts against — must belong to the station's site. */
	public function checkpointFor(Station $station): ?\OCA\TourCheck\Db\Checkpoint
	{
		$cpId = $station->getCheckpointId();
		if ($cpId === null || (int)$cpId <= 0) {
			return null;
		}
		$cp = $this->checkpoints->find((int)$cpId);
		if ($cp === null || (int)$cp->getSiteId() !== (int)$station->getSiteId() || (int)$cp->getActive() !== 1) {
			return null;
		}
		return $cp;
	}

	/** Bind/rebind the station's checkpoint. */
	public function bindCheckpoint(int $stationId, int $siteId, ?int $checkpointId): Station
	{
		$uid = $this->acl->assertCanManageSite($siteId);
		$station = $this->stations->find($stationId);
		if ($station === null || (int)$station->getSiteId() !== $siteId) {
			throw new NotFoundException();
		}
		if ($checkpointId !== null && $checkpointId > 0) {
			$cp = $this->checkpoints->find($checkpointId);
			if ($cp === null || (int)$cp->getSiteId() !== $siteId) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Checkpoint does not belong to this site.', ['checkpoint_id' => 'invalid']);
			}
			$station->setCheckpointId($checkpointId);
		} else {
			$station->setCheckpointId(null);
		}
		$this->stations->update($station);
		$this->journalAdmin($siteId, $uid, 'station_rebound', 'station:' . $stationId, ['station' => $stationId, 'checkpoint' => $checkpointId]);
		return $station;
	}

	public function listForSite(int $siteId): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->stations->findForSite($siteId);
	}

	/** Authenticate a station bearer token. Constant-time hash compare. */
	public function authenticate(string $bearer): Station
	{
		if (strlen($bearer) < 20 || strlen($bearer) > 128) {
			throw new NotFoundException();
		}
		$station = $this->stations->findBySecretHash(self::hashSecret($bearer));
		if ($station === null || (int)$station->getActive() !== 1 || $station->getRevokedAt() !== null) {
			throw new NotFoundException();
		}
		if ($station->getExpiresAt() !== null && $station->getExpiresAt() < $this->timeFactory->getDateTime()) {
			throw new NotFoundException();
		}
		$station->setLastSeenAt($this->timeFactory->getDateTime());
		$this->stations->update($station);
		return $station;
	}

	public function revoke(int $stationId): void
	{
		$s = $this->stations->find($stationId);
		if ($s === null) {
			throw new NotFoundException();
		}
		$uid = $this->acl->assertCanManageSite((int)$s->getSiteId());
		$s->setRevokedAt($this->timeFactory->getDateTime());
		$s->setActive(0);
		$this->stations->update($s);
		$this->journalAdmin((int)$s->getSiteId(), $uid, 'station_revoked', 'station:' . $stationId, ['station' => $stationId]);
	}

	/** @return array{0: string} new raw secret */
	public function rotateSecret(int $stationId): array
	{
		$s = $this->stations->find($stationId);
		if ($s === null || (int)$s->getActive() !== 1) {
			throw new NotFoundException();
		}
		$uid = $this->acl->assertCanManageSite((int)$s->getSiteId());
		$secret = 'tcks_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
		$s->setSecretHash(self::hashSecret($secret));
		$this->stations->update($s);
		$this->journalAdmin((int)$s->getSiteId(), $uid, 'station_secret_rotated', 'station:' . $stationId, ['station' => $stationId]);
		return [$secret];
	}

	/**
	 * One-time pairing code (10 min TTL) — companion enrols a station device.
	 * 64-bit entropy (16 hex chars): a 6-digit code (~20 bits) is
	 * online-bruteforceable within the TTL.
	 */
	public function issuePairingCode(int $stationId): string
	{
		$s = $this->stations->find($stationId);
		if ($s === null || (int)$s->getActive() !== 1) {
			throw new NotFoundException();
		}
		$uid = $this->acl->assertCanManageSite((int)$s->getSiteId());
		$code = bin2hex(random_bytes(8));
		$s->setPairingCodeHash(hash('sha256', $code));
		$s->setPairingExpires($this->timeFactory->getDateTime()->modify('+10 minutes'));
		$this->stations->update($s);
		$this->journalAdmin((int)$s->getSiteId(), $uid, 'station_pairing_issued', 'station:' . $stationId, ['station' => $stationId]);
		return $code;
	}

	/** Companion exchange: pairing code → raw station secret (single-use). */
	public function redeemPairingCode(string $code): array
	{
		$this->license->requireStationPlan();
		if (!preg_match('/^[0-9a-f]{16}$/', $code)) {
			throw new NotFoundException();
		}
		$s = $this->stations->findByPairingHash(hash('sha256', $code));
		if ($s === null || (int)$s->getActive() !== 1 || $s->getRevokedAt() !== null) {
			throw new NotFoundException();
		}
		if ($s->getPairingExpires() === null || $s->getPairingExpires() < $this->timeFactory->getDateTime()) {
			throw new NotFoundException();
		}
		$secret = 'tcks_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
		$s->setSecretHash(self::hashSecret($secret));
		$s->setPairingCodeHash(null);
		$s->setPairingExpires(null);
		$this->stations->update($s);
		return [$s, $secret];
	}

	// ── Members ──────────────────────────────────────────────────────────

	/** Configured PIN length — the kiosk PIN pad auto-advances on it. */
	public function pinLength(): int
	{
		return $this->settings->getPinLength();
	}

	/** @return list<StationMember> */
	/**
	 * Station-scoped member roster for kiosk/companion callers — the
	 * station token IS the authorization there. Web callers must use
	 * {@see listMembersForManager()} so the site ACL is enforced.
	 */
	public function listMembers(int $stationId): array
	{
		$s = $this->stations->find($stationId);
		if ($s === null) {
			throw new NotFoundException();
		}
		return $this->members->findForStation($stationId);
	}

	/** Web-API variant: roster visibility is a site-manage privilege. */
	public function listMembersForManager(int $stationId): array
	{
		$s = $this->stations->find($stationId);
		if ($s === null) {
			throw new NotFoundException();
		}
		$this->acl->assertCanManageSite((int)$s->getSiteId());
		return $this->members->findForStation($stationId);
	}

	public function upsertMember(int $stationId, ?int $memberId, string $displayName, ?string $pin): StationMember
	{
		$s = $this->stations->find($stationId);
		if ($s === null) {
			throw new NotFoundException();
		}
		$uid = $this->acl->assertCanManageSite((int)$s->getSiteId());
		$displayName = trim($displayName);
		if ($displayName === '' || strlen($displayName) > 190) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Member name required.', ['display_name' => 'required']);
		}
		if ($memberId !== null) {
			$m = $this->members->find($memberId);
			if ($m === null || (int)$m->getStationId() !== $stationId) {
				throw new NotFoundException();
			}
			$m->setDisplayName($displayName);
			if ($pin !== null && $pin !== '') {
				$m->setPinHash($this->hashPin($pin));
				$m->setFailCount(0);
				$m->setLockoutUntil(null);
			}
			$m = $this->members->update($m);
			$this->journalAdmin((int)$s->getSiteId(), $uid, 'station_member_updated', 'station_member:' . $memberId, ['member' => $memberId]);
			return $m;
		}
		if ($pin === null || !$this->pinValid($pin)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'PIN required (' . $this->settings->getPinLength() . ' digits).', ['pin' => 'invalid']);
		}
		$m = new StationMember();
		$m->setStationId($stationId);
		$m->setDisplayName($displayName);
		$m->setPinHash($this->hashPin($pin));
		$m->setActive(1);
		$m->setFailCount(0);
		$m->setCreatedAt($this->timeFactory->getDateTime());
		$m = $this->members->insert($m);
		$this->journalAdmin((int)$s->getSiteId(), $uid, 'station_member_added', 'station_member:' . $m->getId(), ['member' => (int)$m->getId()]);
		return $m;
	}

	public function deactivateMember(int $memberId): void
	{
		$m = $this->members->find($memberId);
		if ($m === null) {
			throw new NotFoundException();
		}
		$s = $this->stations->find((int)$m->getStationId());
		if ($s === null) {
			throw new NotFoundException();
		}
		$uid = $this->acl->assertCanManageSite((int)$s->getSiteId());
		$m->setActive(0);
		$this->members->update($m);
		$this->journalAdmin((int)$s->getSiteId(), $uid, 'station_member_deactivated', 'station_member:' . $memberId, ['member' => $memberId]);
	}

	public function resetMemberPin(int $memberId, string $pin): void
	{
		$m = $this->members->find($memberId);
		if ($m === null) {
			throw new NotFoundException();
		}
		$s = $this->stations->find((int)$m->getStationId());
		if ($s === null) {
			throw new NotFoundException();
		}
		$uid = $this->acl->assertCanManageSite((int)$s->getSiteId());
		if (!$this->pinValid($pin)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'PIN must be ' . $this->settings->getPinLength() . ' digits.', ['pin' => 'invalid']);
		}
		$m->setPinHash($this->hashPin($pin));
		$m->setFailCount(0);
		$m->setLockoutUntil(null);
		$this->members->update($m);
		$this->journalAdmin((int)$s->getSiteId(), $uid, 'station_member_pin_reset', 'station_member:' . $memberId, ['member' => $memberId]);
	}

	/**
	 * PIN verify for a kiosk confirmation — salted+peppered hash, lockout
	 * after N failures (persisted, survives station restart). Returns the
	 * member on success; uniform failure (locked/unknown/wrong look alike).
	 */
	public function verifyMemberPin(Station $station, int $memberId, string $pin): StationMember
	{
		$now = $this->timeFactory->getDateTime();
		// Station-level lockout is checked first and burns on EVERY failure —
		// including unknown member ids — so probing can never escape it (§4).
		if ($station->getLockoutUntil() !== null && $station->getLockoutUntil() > $now) {
			throw new DomainException('pin_locked', 'Locked. Try again later.', 429,
				(int)$station->getLockoutUntil()->format('U') - $now->getTimestamp());
		}
		$m = $this->members->find($memberId);
		if ($m === null || (int)$m->getStationId() !== (int)$station->getId() || (int)$m->getActive() !== 1) {
			$this->registerStationFailure($station, $now);
			throw new NotFoundException();
		}
		if ($m->getLockoutUntil() !== null && $m->getLockoutUntil() > $now) {
			throw new DomainException('pin_locked', 'Locked. Try again later.', 429,
				(int)$m->getLockoutUntil()->format('U') - $now->getTimestamp());
		}
		if (!$this->pinMatches($m, $pin)) {
			// Atomic bumps — parallel wrong PINs must not under-count.
			$count = $this->members->bumpFailCount((int)$m->getId());
			if ($count >= $this->settings->getPinLockoutAttempts() && $m->getLockoutUntil() === null) {
				// Only persist lockout_until — writing fail_count here would
				// clobber increments a parallel attempt already committed.
				$m->setLockoutUntil($now->modify('+' . $this->settings->getPinLockoutMinutes() . ' minutes'));
				$this->members->update($m);
			}
			$this->registerStationFailure($station, $now);
			throw new NotFoundException();
		}
		if ((int)$m->getFailCount() > 0 || $m->getLockoutUntil() !== null) {
			$m->setFailCount(0);
			$m->setLockoutUntil(null);
			$this->members->update($m);
		}
		if ((int)$station->getFailCount() > 0) {
			$station->setFailCount(0);
			$this->stations->update($station);
		}
		return $m;
	}

	private function registerStationFailure(Station $station, \DateTimeInterface $now): void
	{
		// Station threshold is 2× the member one — several members mistyping
		// across a shift shouldn't lock the kiosk, but probing must.
		$max = max(1, $this->settings->getPinLockoutAttempts()) * 2;
		$count = $this->stations->bumpFailCount((int)$station->getId());
		if ($count >= $max && $station->getLockoutUntil() === null) {
			$station->setLockoutUntil($now->modify('+' . $this->settings->getPinLockoutMinutes() . ' minutes'));
			$this->stations->update($station);
		}
	}

	private function pinValid(string $pin): bool
	{
		return preg_match('/^\d{' . $this->settings->getPinLength() . '}$/', $pin) === 1;
	}

	private function hashPin(string $pin): string
	{
		// Argon2id + server pepper — offline brute-force on a stolen row still
		// needs the pepper; 4-digit spaces stay crackable either way, hence the
		// 6-digit default (TH accepted-residual, spec §10).
		return password_hash($pin . ':' . $this->settings->getChainSecret(), PASSWORD_ARGON2ID);
	}

	private function pinMatches(StationMember $m, string $pin): bool
	{
		return password_verify($pin . ':' . $this->settings->getChainSecret(), (string)$m->getPinHash());
	}

	private function journalAdmin(int $siteId, string $uid, string $body, string $ref, array $fields): void
	{
		$this->chain->append($siteId, [
			'kind' => Constants::KIND_ADMIN,
			'actorUid' => $uid,
			'body' => $body,
			'payloadRef' => $ref,
			'payloadFields' => $fields,
		]);
	}
}
