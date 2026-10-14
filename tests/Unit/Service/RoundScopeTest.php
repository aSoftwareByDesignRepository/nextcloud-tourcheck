<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\RoundService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Global rounds list scoping (the 2026-11-04 listRounds BOLA fix):
 * unscoped calls must be fail-closed — unrestricted enumeration is an
 * admin/planner privilege only. These assertions pin the exact
 * findOpen() arguments per privilege tier.
 */
final class RoundScopeTest extends TestCase
{
	private RoundMapper&MockObject $rounds;
	private AccessControlService&MockObject $acl;
	private RoundService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->rounds = $this->createMock(RoundMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);
		$this->svc = new RoundService(
			$this->rounds,
			$this->createMock(RoundCheckpointMapper::class),
			$this->createMock(CheckEventMapper::class),
			$this->acl,
			$this->createMock(ChainService::class),
			$time,
			$this->createMock(IDBConnection::class),
		);
	}

	public function testMemberIsScopedToOwnSites(): void
	{
		$this->acl->method('visibleSiteIds')->with('w1')->willReturn([7, 9]);
		$this->acl->method('isAppAdmin')->willReturn(false);
		$this->acl->method('canPlan')->willReturn(false);
		$this->rounds->expects($this->once())
			->method('findOpen')
			->with([7, 9], false)
			->willReturn([]);
		$this->assertSame([], $this->svc->todayForUser('w1'));
	}

	public function testNoMembershipNoPrivilegeIsFailClosed(): void
	{
		$this->acl->method('visibleSiteIds')->with('nobody')->willReturn([]);
		$this->acl->method('isAppAdmin')->willReturn(false);
		$this->acl->method('canPlan')->willReturn(false);
		$this->rounds->expects($this->once())
			->method('findOpen')
			->with([], false)
			->willReturn([]);
		$this->svc->todayForUser('nobody');
	}

	public function testAdminGetsUnrestrictedScope(): void
	{
		$this->acl->method('visibleSiteIds')->willReturn([]);
		$this->acl->method('isAppAdmin')->with('boss')->willReturn(true);
		$this->rounds->expects($this->once())
			->method('findOpen')
			->with([], true)
			->willReturn([]);
		$this->svc->todayForUser('boss');
	}

	public function testPlannerGetsUnrestrictedScope(): void
	{
		$this->acl->method('visibleSiteIds')->willReturn([]);
		$this->acl->method('isAppAdmin')->willReturn(false);
		$this->acl->method('canPlan')->with('p1')->willReturn(true);
		$this->rounds->expects($this->once())
			->method('findOpen')
			->with([], true)
			->willReturn([]);
		$this->svc->todayForUser('p1');
	}

	/** Membership rows beat privilege claims — scoping wins over unrestricted. */
	public function testMemberWithPrivilegeStillScoped(): void
	{
		$this->acl->method('visibleSiteIds')->willReturn([4]);
		$this->acl->method('isAppAdmin')->willReturn(true);
		$this->rounds->expects($this->once())
			->method('findOpen')
			->with([4], false)
			->willReturn([]);
		$this->svc->todayForUser('dual');
	}
}
