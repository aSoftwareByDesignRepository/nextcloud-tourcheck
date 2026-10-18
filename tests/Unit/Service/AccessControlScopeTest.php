<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Db\SiteMember;
use OCA\TourCheck\Db\SiteMemberMapper;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\SettingsService;
use OCP\Authentication\Token\IProvider;
use OCP\IGroupManager;
use OCP\IRequest;
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
	private IUserSession&MockObject $userSession;
	private IUserManager&MockObject $users;
	private IRequest&MockObject $request;
	private IProvider&MockObject $tokens;
	private AccessControlService $acl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->members = $this->createMock(SiteMemberMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->tokens = $this->createMock(IProvider::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getAppAdmins')->willReturn([]);
		$this->acl = new AccessControlService(
			$this->userSession,
			$this->users,
			$this->groups,
			$this->request,
			$this->tokens,
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

	/**
	 * Companion cookie-shadow regression: the RN cookie jar survives
	 * sign-out, so a stale session cookie can accompany a Basic credential
	 * for a *different* user. The explicit credential must win everywhere
	 * uid resolution happens — otherwise incidents/positions journal under
	 * the cookie user.
	 */
	private function basicHeader(string $login, string $secret): void
	{
		$this->request->method('getHeader')
			->willReturnCallback(static fn (string $h) =>
				strtolower($h) === 'authorization'
					? 'Basic ' . base64_encode("$login:$secret") : '');
	}

	public function testBasicAppPasswordResolvesTokenOwnerNotSession(): void
	{
		$this->basicHeader('worker', 'apptoken');
		$sessionUser = $this->createMock(\OCP\IUser::class);
		$sessionUser->method('getUID')->willReturn('stale-user');
		$this->userSession->method('getUser')->willReturn($sessionUser);
		$token = $this->createMock(\OCP\Authentication\Token\IToken::class);
		$token->method('getUID')->willReturn('worker');
		$token->method('getLoginName')->willReturn('worker');
		$this->tokens->method('getToken')->with('apptoken')->willReturn($token);
		self::assertSame('worker', $this->acl->currentUid());
	}

	public function testBasicAccountPasswordResolvesLoginNotSession(): void
	{
		$this->basicHeader('worker', 'accountpw');
		$sessionUser = $this->createMock(\OCP\IUser::class);
		$sessionUser->method('getUID')->willReturn('stale-user');
		$this->userSession->method('getUser')->willReturn($sessionUser);
		$this->tokens->method('getToken')->willThrowException(
			new \OCP\Authentication\Exceptions\InvalidTokenException());
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('worker');
		$this->users->method('checkPassword')->with('worker', 'accountpw')->willReturn($user);
		self::assertSame('worker', $this->acl->currentUid());
	}

	public function testUnverifiableBasicDeniesDespiteValidSession(): void
	{
		$this->basicHeader('worker', 'bogus');
		$sessionUser = $this->createMock(\OCP\IUser::class);
		$sessionUser->method('getUID')->willReturn('stale-user');
		$this->userSession->method('getUser')->willReturn($sessionUser);
		$this->tokens->method('getToken')->willThrowException(
			new \OCP\Authentication\Exceptions\InvalidTokenException());
		$this->users->method('checkPassword')->willReturn(false);
		self::assertNull($this->acl->currentUid());
	}

	public function testNoAuthorizationFallsBackToSession(): void
	{
		$sessionUser = $this->createMock(\OCP\IUser::class);
		$sessionUser->method('getUID')->willReturn('sess-user');
		$this->userSession->method('getUser')->willReturn($sessionUser);
		self::assertSame('sess-user', $this->acl->currentUid());
	}
}
