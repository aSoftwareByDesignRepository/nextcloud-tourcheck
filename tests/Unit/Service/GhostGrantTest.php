<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Db\SiteMemberMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * GA-25: ghost-uid/gid field values must be rejected as bad input (422-class
 * ValidationException with field attribution) — never as a 404 (reserved for
 * URL-identifier uniform misses) and never silently accepted.
 */
class GhostGrantTest extends TestCase
{
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private AccessControlService $acl;

	protected function setUp(): void
	{
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->acl = new AccessControlService(
			$this->createMock(IUserSession::class),
			$this->userManager,
			$this->groupManager,
			$this->createMock(SiteMapper::class),
			$this->createMock(SiteMemberMapper::class),
			$this->createMock(SettingsService::class),
		);
	}

	public function testGhostUidIsFieldErrorNotNotFound(): void
	{
		$this->userManager->method('userExists')->with('deleted-user')->willReturn(false);
		try {
			$this->acl->assertValidUid('deleted-user');
			self::fail('ghost uid must be rejected');
		} catch (ValidationException $e) {
			self::assertSame(422, $e->httpStatus);
			self::assertSame('unknown', $e->fields['uid']);
		}
	}

	public function testGhostUidNeverThrowsNotFound(): void
	{
		$this->userManager->method('userExists')->willReturn(false);
		try {
			$this->acl->assertValidUid('ghost');
		} catch (ValidationException $e) {
			self::assertNotInstanceOf(NotFoundException::class, $e);
			return;
		} catch (NotFoundException) {
			self::fail('a ghost field value is bad input (422), not a not-found url id (404)');
		}
		self::fail('ghost uid must be rejected');
	}

	public function testExistingUidPasses(): void
	{
		$this->userManager->method('userExists')->with('real-worker')->willReturn(true);
		$this->acl->assertValidUid('real-worker');
		$this->addToAssertionCount(1);
	}

	public function testEmptyAndOverlongUidRejected(): void
	{
		$this->userManager->expects(self::never())->method('userExists');
		foreach (['', str_repeat('x', 65)] as $uid) {
			try {
				$this->acl->assertValidUid($uid);
				self::fail("uid must be rejected: " . strlen($uid));
			} catch (ValidationException $e) {
				self::assertSame('unknown', $e->fields['uid']);
			}
		}
	}

	public function testGhostGidIsFieldError(): void
	{
		$this->groupManager->method('groupExists')->with('deleted-group')->willReturn(false);
		try {
			$this->acl->assertValidGid('deleted-group');
			self::fail('ghost gid must be rejected');
		} catch (ValidationException $e) {
			self::assertSame(422, $e->httpStatus);
			self::assertSame('unknown', $e->fields['gid']);
		}
	}

	public function testExistingGidPasses(): void
	{
		$this->groupManager->method('groupExists')->with('tourcheck_worker')->willReturn(true);
		$this->acl->assertValidGid('tourcheck_worker');
		$this->addToAssertionCount(1);
	}
}
