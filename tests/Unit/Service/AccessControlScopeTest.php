<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Db\SiteMember;
use OCA\TourCheck\Db\SiteMemberMapper;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * canManageAnySite gates the shared uid picker — user enumeration is a
 * manage privilege. Every privilege source must independently grant it;
 * a worker-only or member-less user must never get the picker.
 */
final class AccessControlScopeTest extends TestCase
{
	private IGroupManager&MockObject $groups;
	private SiteMemberMapper&MockObject $members;
	private AccessControlService $acl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->members = $this->createMock(SiteMemberMapper::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getAppAdmins')->willReturn([]);
		$this->acl = new AccessControlService(
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->groups,
			$this->createMock(SiteMapper::class),
			$this->members,
			$settings,
		);
	}

	private function groupMap(array $in): void
	{
		$this->groups->method('isInGroup')
			->willReturnCallback(static fn (string $uid, string $g) => in_array($g, $in[$uid] ?? [], true));
	}

	public function testAdminGroupGrantsManageAny(): void
	{
		$this->groupMap(['boss' => [Constants::GROUP_ADMIN]]);
		$this->members->expects($this->never())->method('findByUidAndRole');
		self::assertTrue($this->acl->canManageAnySite('boss'));
	}

	public function testPlannerGroupGrantsManageAny(): void
	{
		$this->groupMap(['p1' => [Constants::GROUP_PLANNER]]);
		self::assertTrue($this->acl->canManageAnySite('p1'));
	}

	public function testSiteAdminMembershipGrantsManageAny(): void
	{
		$this->groupMap([]);
		$m = new SiteMember();
		$this->members->method('findByUidAndRole')
			->willReturnCallback(static fn (string $uid, string $role) =>
				$uid === 'sm' && $role === Constants::ROLE_ADMIN ? [$m] : []);
		self::assertTrue($this->acl->canManageAnySite('sm'));
	}

	public function testSitePlannerMembershipGrantsManageAny(): void
	{
		$this->groupMap([]);
		$m = new SiteMember();
		$this->members->method('findByUidAndRole')
			->willReturnCallback(static fn (string $uid, string $role) =>
				$uid === 'sp' && $role === Constants::ROLE_PLANNER ? [$m] : []);
		self::assertTrue($this->acl->canManageAnySite('sp'));
	}

	public function testWorkerOnlyMembershipDenied(): void
	{
		$this->groupMap(['w1' => [Constants::GROUP_WORKER]]);
		$m = new SiteMember();
		$this->members->method('findByUidAndRole')
			->willReturnCallback(static fn (string $uid, string $role) =>
				$role === Constants::ROLE_WORKER ? [$m] : []);
		self::assertFalse($this->acl->canManageAnySite('w1'));
	}

	public function testMemberlessUserDenied(): void
	{
		$this->groupMap([]);
		$this->members->method('findByUidAndRole')->willReturn([]);
		self::assertFalse($this->acl->canManageAnySite('nobody'));
	}
}
