<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\SiteMember;
use OCA\TourCheck\Db\SiteMemberMapper;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;

/** Site roster — uid existence AND app access both enforced (ghost-UID guard). */
class SiteMemberService
{
	public function __construct(
		private readonly SiteMemberMapper $members,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly ChainService $chain,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/** @return list<SiteMember> */
	public function listForSite(int $siteId): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->members->findBySite($siteId);
	}

	public function setRole(int $siteId, string $uid, string $role): SiteMember
	{
		$this->sites->getVisible($siteId);
		$actor = $this->acl->assertCanManageSite($siteId);
		if (!in_array($role, [Constants::ROLE_ADMIN, Constants::ROLE_PLANNER, Constants::ROLE_SUPERVISOR, Constants::ROLE_WORKER], true)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown role.', ['role' => 'invalid']);
		}
		// Ghost-UID defense: existence AND the uid must be able to enter the app.
		$this->acl->assertValidUid($uid);
		if (!$this->acl->hasAppAccess($uid)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'User has no TourCheck access.', ['uid' => 'no_access']);
		}

		$now = $this->timeFactory->getDateTime();
		$existing = $this->members->roleFor($siteId, $uid);
		if ($existing !== null) {
			foreach ($this->members->findBySite($siteId) as $m) {
				if ($m->getUid() === $uid) {
					$m->setRole($role);
					$m = $this->members->update($m);
					$this->journalRoleChange($siteId, $actor, $uid, $role);
					return $m;
				}
			}
		}
		$m = new SiteMember();
		$m->setSiteId($siteId);
		$m->setUid($uid);
		$m->setRole($role);
		$m->setCreatedAt($now);
		$m = $this->members->insert($m);
		$this->journalRoleChange($siteId, $actor, $uid, $role);
		return $m;
	}

	public function remove(int $siteId, string $uid): void
	{
		$this->sites->getVisible($siteId);
		$actor = $this->acl->assertCanManageSite($siteId);
		if ($this->members->roleFor($siteId, $uid) === null) {
			throw new \OCA\TourCheck\Exception\NotFoundException();
		}
		$this->members->deleteFor($siteId, $uid);
		$this->chain->append($siteId, [
			'kind' => Constants::KIND_ADMIN,
			'actorUid' => $actor,
			'body' => 'member_removed:' . $uid,
			'payloadRef' => 'site_member:' . $siteId . ':' . $uid,
			'payloadFields' => ['site' => $siteId, 'uid' => $uid],
		]);
	}

	private function journalRoleChange(int $siteId, string $actor, string $uid, string $role): void
	{
		$this->chain->append($siteId, [
			'kind' => Constants::KIND_ADMIN,
			'actorUid' => $actor,
			'body' => 'member_role:' . $role,
			'payloadRef' => 'site_member:' . $siteId . ':' . $uid,
			'payloadFields' => ['site' => $siteId, 'uid' => $uid, 'role' => $role],
		]);
	}
}
