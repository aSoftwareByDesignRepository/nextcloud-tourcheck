<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\KeyLog;
use OCA\TourCheck\Db\KeyLogMapper;
use OCA\TourCheck\Db\StationMemberMapper;
use OCA\TourCheck\Db\TckKey;
use OCA\TourCheck\Db\TckKeyMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * NH-10 Schlüsselmanagement: registered keys per site + an append-only
 * take/return ledger. Concurrency contract: holder transitions are
 * single-statement CAS updates (WHERE status='in'|'out') — two concurrent
 * takes cannot both win, and a return that races a take fails closed.
 * Every movement is ALSO chained into the site journal so key custody is
 * part of the evidence chain.
 */
class KeyService
{
	public function __construct(
		private readonly TckKeyMapper $keys,
		private readonly KeyLogMapper $log,
		private readonly StationMemberMapper $members,
		private readonly \OCA\TourCheck\Db\StationMapper $stations,
		private readonly SiteService $sites,
		private readonly AccessControlService $acl,
		private readonly ChainService $chain,
		private readonly IUserManager $userManager,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
	) {
	}

	/** @return list<TckKey> */
	public function listForSite(int $siteId): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->keys->findForSite($siteId);
	}

	/** @return list<KeyLog> */
	public function logForSite(int $siteId, int $limit = 100, int $offset = 0): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->log->findForSite($siteId, $limit, $offset);
	}

	public function create(int $siteId, string $label): TckKey
	{
		$this->acl->assertCanManageSite($siteId);
		$label = trim($label);
		if ($label === '' || mb_strlen($label) > 190) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Key label is required.', ['label' => 'required']);
		}
		$now = $this->timeFactory->getDateTime();
		$k = new TckKey();
		$k->setSiteId($siteId);
		$k->setLabel($label);
		$k->setStatus('in');
		$k->setRevision(1);
		$k->setCreatedAt($now);
		$k->setUpdatedAt($now);
		$k = $this->keys->insert($k);
		$this->journalKey($siteId, 'key_created', (int)$k->getId(), $this->acl->currentUid(), null);
		return $k;
	}

	/**
	 * Take a key out. $holderUid XOR $holderMemberId identifies custody —
	 * a worker (NC uid) or a station member (kiosk PIN identity). The
	 * status flip is atomic; a key already out → 422 'busy', never an
	 * overwrite of the existing holder.
	 */
	public function take(int $keyId, ?string $holderUid, ?int $holderMemberId, ?string $note = null): TckKey
	{
		$key = $this->keys->find($keyId);
		if ($key === null) {
			throw new NotFoundException();
		}
		$siteId = (int)$key->getSiteId();
		$actor = $this->acl->assertCanViewSite($siteId);

		if ($holderUid !== null) {
			// Ghost-uid guard: existence AND site membership (api-security §2).
			if (!$this->userManager->userExists($holderUid)
				|| $this->acl->siteRole($holderUid, $siteId) === null) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Holder is not a member of this site.', ['holder' => 'invalid']);
			}
			$holderMemberId = null;
		} elseif ($holderMemberId !== null) {
			$m = $this->members->find($holderMemberId);
			// Members belong to a station, and the station is site-bound —
			// verify the chain member→station→site or a kiosk identity of
			// site A could hold keys of site B.
			$memberSite = $m !== null ? $this->stations->find((int)$m->getStationId()) : null;
			if ($m === null || (int)$m->getActive() !== 1
				|| $memberSite === null || (int)$memberSite->getSiteId() !== $siteId) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Holder is not a member of this site.', ['holder' => 'invalid']);
			}
			$holderUid = null;
		} else {
			// Self-take: the acting worker is the holder.
			$holderUid = $actor;
		}

		$note = $this->note($note);
		$this->db->beginTransaction();
		try {
			if (!$this->keys->atomicTake($keyId, $holderUid, $holderMemberId, $this->timeFactory->getDateTime())) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'This key is already taken.', ['key' => 'busy']);
			}
			$this->insertLog($keyId, $siteId, 'take', $actor, $holderUid, $holderMemberId, $note);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->journalKey($siteId, 'key_take', $keyId, $holderUid, $holderMemberId);
		return $this->keys->find($keyId);
	}

	/**
	 * Return a key. The holder may return their own; a site manager may
	 * return anyone's (a forgotten key cannot strand the register).
	 */
	public function return(int $keyId, ?string $note = null, ?int $asMemberId = null): TckKey
	{
		$key = $this->keys->find($keyId);
		if ($key === null) {
			throw new NotFoundException();
		}
		$siteId = (int)$key->getSiteId();
		$actor = $this->acl->assertCanViewSite($siteId);

		$isHolder = $asMemberId !== null
			? (int)($key->getHolderMemberId() ?? 0) === $asMemberId
			: (string)($key->getHolderUid() ?? '') === $actor;
		if (!$isHolder) {
			try {
				$this->acl->assertCanManageSite($siteId);
			} catch (\Throwable) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Only the holder or a site manager can return this key.', ['key' => 'not_holder']);
			}
		}

		$note = $this->note($note);
		$this->db->beginTransaction();
		try {
			if (!$this->keys->atomicReturn($keyId, $this->timeFactory->getDateTime())) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'This key is not taken.', ['key' => 'busy']);
			}
			$this->insertLog($keyId, $siteId, 'return', $actor, (string)($key->getHolderUid() ?? ''), $key->getHolderMemberId(), $note);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->journalKey($siteId, 'key_return', $keyId, (string)($key->getHolderUid() ?? ''), $key->getHolderMemberId());
		return $this->keys->find($keyId);
	}

	/**
	 * Station-scoped list — the kiosk token already proves site scope;
	 * no NC actor exists in station mode.
	 * @return list<TckKey>
	 */
	public function listForStation(\OCA\TourCheck\Db\Station $station): array
	{
		return $this->keys->findForSite((int)$station->getSiteId());
	}

	/** Station take: custody is the PIN-verified member, site-pinned. */
	public function takeForStation(\OCA\TourCheck\Db\Station $station, int $keyId, int $memberId, ?string $note = null): TckKey
	{
		$key = $this->stationKey($station, $keyId);
		$note = $this->note($note);
		$this->db->beginTransaction();
		try {
			if (!$this->keys->atomicTake($keyId, null, $memberId, $this->timeFactory->getDateTime())) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'This key is already taken.', ['key' => 'busy']);
			}
			$this->insertLog($keyId, (int)$station->getSiteId(), 'take', null, null, $memberId, $note);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->journalKey((int)$station->getSiteId(), 'key_take', $keyId, null, $memberId);
		return $this->keys->find($keyId);
	}

	/** Station return: only the holding member returns at the kiosk. */
	public function returnForStation(\OCA\TourCheck\Db\Station $station, int $keyId, int $memberId, ?string $note = null): TckKey
	{
		$key = $this->stationKey($station, $keyId);
		if ((int)($key->getHolderMemberId() ?? 0) !== $memberId) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Only the holder can return this key.', ['key' => 'not_holder']);
		}
		$note = $this->note($note);
		$this->db->beginTransaction();
		try {
			if (!$this->keys->atomicReturn($keyId, $this->timeFactory->getDateTime())) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'This key is not taken.', ['key' => 'busy']);
			}
			$this->insertLog($keyId, (int)$station->getSiteId(), 'return', null, null, $memberId, $note);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->journalKey((int)$station->getSiteId(), 'key_return', $keyId, null, $memberId);
		return $this->keys->find($keyId);
	}

	private function stationKey(\OCA\TourCheck\Db\Station $station, int $keyId): TckKey
	{
		$key = $this->keys->find($keyId);
		// Foreign-site key id → same uniform NOT_FOUND as a missing one.
		if ($key === null || (int)$key->getSiteId() !== (int)$station->getSiteId()) {
			throw new NotFoundException();
		}
		return $key;
	}

	private function insertLog(int $keyId, int $siteId, string $action, ?string $actorUid, ?string $holderUid, ?int $holderMemberId, ?string $note): void
	{
		$l = new KeyLog();
		$l->setKeyId($keyId);
		$l->setSiteId($siteId);
		$l->setAction($action);
		$l->setActorUid($actorUid !== '' ? $actorUid : null);
		// The ledger records the CUSTODIAN (who holds/held the key), not just
		// the clicker — at a kiosk those differ (member identity, no uid).
		$l->setMemberId($holderMemberId);
		$l->setHolderUid($holderUid !== '' && $holderUid !== null ? $holderUid : null);
		$l->setNote($note);
		$l->setCreatedAt($this->timeFactory->getDateTime());
		$this->log->insert($l);
	}

	private function journalKey(int $siteId, string $body, int $keyId, ?string $holderUid, ?int $holderMemberId): void
	{
		try {
			$this->chain->append($siteId, [
				'kind' => Constants::KIND_ADMIN,
				'actorUid' => $this->acl->currentUid(),
				'body' => $body,
				'payloadRef' => 'key:' . $keyId,
				'payloadFields' => [
					'key' => $keyId,
					'holder_uid' => $holderUid,
					'holder_member' => $holderMemberId,
				],
			]);
		} catch (\Throwable) {
			// Journaling must not roll back the committed movement — the
			// ledger row already stands; the chain catches up on next entry.
		}
	}

	private function note(?string $note): ?string
	{
		$n = trim((string)$note);
		return $n === '' ? null : mb_substr($n, 0, 500);
	}
}
