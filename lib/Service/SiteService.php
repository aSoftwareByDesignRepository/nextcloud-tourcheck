<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Db\SiteMemberMapper;
use OCA\TourCheck\Db\StationMapper;
use OCA\TourCheck\Exception\ConflictException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Support\LockGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

/** Site CRUD with CAS + privileged-mutation journaling (kind=admin). */
class SiteService
{
	public function __construct(
		private readonly SiteMapper $sites,
		private readonly SiteMemberMapper $members,
		private readonly StationMapper $stations,
		private readonly AccessControlService $acl,
		private readonly ChainService $chain,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly ILockingProvider $locking,
	) {
	}

	/** Internal lookup without ACL — scheduler/jobs only, never controllers. */
	public function findById(int $siteId): ?Site
	{
		return $this->sites->find($siteId);
	}

	public function getVisible(int $siteId): Site
	{
		$site = $this->sites->find($siteId);
		if ($site === null || (int)$site->getActive() !== 1) {
			throw new NotFoundException();
		}
		$this->acl->assertCanViewSite($siteId);
		return $site;
	}

	/** @return list<Site> */
	public function listForCurrentUser(): array
	{
		$uid = $this->acl->assertAppAccess();
		$ids = $this->acl->visibleSiteIds($uid);
		if ($ids === []) {
			// unrestricted (admin/planner) — or none
			if (!$this->acl->isAppAdmin($uid) && !$this->acl->canPlan($uid)) {
				return [];
			}
			return array_values(array_filter(
				$this->sites->findAll(),
				static fn (Site $s) => (int)$s->getActive() === 1,
			));
		}
		$out = [];
		foreach ($ids as $id) {
			$s = $this->sites->find($id);
			if ($s !== null && (int)$s->getActive() === 1) {
				$out[] = $s;
			}
		}
		return $out;
	}

	public function create(string $name, ?string $address, ?string $tz, ?string $briefing = null): Site
	{
		$uid = $this->acl->assertAppAccess();
		if (!$this->acl->canPlan($uid)) {
			throw new NotFoundException();
		}
		$name = trim($name);
		if ($name === '' || strlen($name) > 190) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Site name is required.', ['name' => 'required']);
		}
		$tz = trim((string)$tz);
		if ($tz === '' || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
			$tz = 'Europe/Berlin';
		}
		$now = $this->timeFactory->getDateTime();
		$site = new Site();
		$site->setName($name);
		$site->setAddress($address !== null && trim($address) !== '' ? trim($address) : null);
		$site->setBriefing($briefing !== null && trim($briefing) !== '' ? mb_substr(trim($briefing), 0, 4000) : null);
		$site->setTz($tz);
		$site->setActive(1);
		$site->setRevision(1);
		$site->setCreatedAt($now);
		$site->setUpdatedAt($now);
		$site = $this->sites->insert($site);

		// Creator becomes site admin (site_member row).
		$this->db->beginTransaction();
		try {
			$m = new \OCA\TourCheck\Db\SiteMember();
			$m->setSiteId((int)$site->getId());
			$m->setUid($uid);
			$m->setRole(Constants::ROLE_ADMIN);
			$m->setCreatedAt($now);
			$this->members->insert($m);
			$this->chain->append((int)$site->getId(), [
				'kind' => Constants::KIND_ADMIN,
				'actorUid' => $uid,
				'body' => 'site_created',
				'payloadRef' => 'site:' . $site->getId(),
				'payloadFields' => ['site' => (int)$site->getId(), 'name' => $name],
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $site;
	}

	public function update(int $siteId, array $fields, ?int $baseRevision): Site
	{
		$this->getVisible($siteId);
		$uid = $this->acl->assertCanManageSite($siteId);
		// Row lock before the CAS check — assertRevision without it is a
		// lost-update race (two writers read revision N, both merge, the
		// second silently clobbers the first).
		$this->db->beginTransaction();
		try {
			$site = $this->sites->findForUpdate($siteId);
			if ($site === null || (int)$site->getActive() !== 1) {
				throw new NotFoundException();
			}
			$this->assertRevision($site->getRevision(), $baseRevision);

			if (array_key_exists('name', $fields)) {
				$name = trim((string)$fields['name']);
				if ($name === '' || strlen($name) > 190) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Site name is required.', ['name' => 'required']);
				}
				$site->setName($name);
			}
			if (array_key_exists('address', $fields)) {
				$site->setAddress(trim((string)$fields['address']) ?: null);
			}
			if (array_key_exists('client_contact', $fields)) {
				$site->setClientContact(trim((string)$fields['client_contact']) ?: null);
			}
			// NH-14: Objekt-Hinweise — alarm codes, hazards, dogs, emergency
			// contacts. Shown to the worker once per round start (journaled).
			if (array_key_exists('briefing', $fields)) {
				$site->setBriefing(trim((string)$fields['briefing']) !== '' ? mb_substr(trim((string)$fields['briefing']), 0, 4000) : null);
			}
			if (array_key_exists('tz', $fields)) {
				$tz = trim((string)$fields['tz']);
				if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown timezone.', ['tz' => 'invalid']);
				}
				$site->setTz($tz);
			}
			if (array_key_exists('live_position', $fields)) {
				$site->setLivePosition((int)$fields['live_position'] === 1 ? 1 : 0);
			}
			$site->setRevision((int)$site->getRevision() + 1);
			$site->setUpdatedAt($this->timeFactory->getDateTime());
			$site = $this->sites->update($site);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->chain->append($siteId, [
			'kind' => Constants::KIND_ADMIN,
			'actorUid' => $uid,
			'body' => 'site_updated',
			'payloadRef' => 'site:' . $siteId,
			'payloadFields' => ['site' => $siteId, 'revision' => (int)$site->getRevision()],
		]);
		return $site;
	}

	public function archive(int $siteId): void
	{
		$this->getVisible($siteId);
		$uid = $this->acl->assertCanManageSite($siteId);
		// Lock the row: a concurrent update() holds it too — without the
		// lock archive could write back a stale active=1 read.
		// Serialize against enrollment on tck.station.enroll: archive must
		// free the site's station device seats — a station on an archived
		// site can never be revoked (assertCanManageSite 404s), so its
		// license seat would leak permanently.
		$revoked = LockGuard::run($this->locking, 'tck.station.enroll', function () use ($siteId) {
			$revoked = [];
			$this->db->beginTransaction();
			try {
				$site = $this->sites->findForUpdate($siteId);
				if ($site === null || (int)$site->getActive() !== 1) {
					throw new NotFoundException();
				}
				$site->setActive(0);
				$site->setRevision((int)$site->getRevision() + 1);
				$site->setUpdatedAt($this->timeFactory->getDateTime());
				$site = $this->sites->update($site);
				$now = $this->timeFactory->getDateTime();
				foreach ($this->stations->findForSite($siteId) as $st) {
					if ((int)$st->getActive() === 1) {
						$st->setActive(0);
						$st->setRevokedAt($now);
						$this->stations->update($st);
						$revoked[] = (int)$st->getId();
					}
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return $revoked;
		});
		$this->chain->append($siteId, [
			'kind' => Constants::KIND_ADMIN,
			'actorUid' => $uid,
			'body' => 'site_archived',
			'payloadRef' => 'site:' . $siteId,
			'payloadFields' => ['site' => $siteId],
		]);
		foreach ($revoked as $stationId) {
			$this->chain->append($siteId, [
				'kind' => Constants::KIND_ADMIN,
				'actorUid' => $uid,
				'body' => 'station_revoked',
				'payloadRef' => 'station:' . $stationId,
				'payloadFields' => ['station' => $stationId, 'site' => $siteId, 'via' => 'site_archived'],
			]);
		}
	}

	/** Fail-closed CAS: missing/unknown base revision → 409, never merge. */
	public function assertRevision(mixed $current, ?int $base): void
	{
		if ($base === null || (int)$current !== $base) {
			throw new ConflictException(Constants::ERR_CONFLICT_STALE, 'The record changed. Reload and retry.');
		}
	}
}
