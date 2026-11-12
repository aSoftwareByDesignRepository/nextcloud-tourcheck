<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\CheckpointService;
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
			$this->createMock(CheckpointService::class),
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

	/** Companion feed: the finished tail rides the SAME site scope — a
	 * worker must never receive completed rounds from foreign sites. */
	public function testWorkerFeedScopesFinishedTailToMemberSites(): void
	{
		$this->acl->method('visibleSiteIds')->with('w1')->willReturn([7, 9]);
		$this->acl->method('isAppAdmin')->willReturn(false);
		$this->acl->method('canPlan')->willReturn(false);
		$this->rounds->expects($this->once())
			->method('findOpen')
			->with([7, 9], false)
			->willReturn([]);
		$this->rounds->expects($this->once())
			->method('findRecentlyCompleted')
			->with([7, 9], false)
			->willReturn([]);
		$this->assertSame([], $this->svc->workerFeedForUser('w1'));
	}

	/** Companion feed merges open + finished tail re-sorted on
	 * scheduled_start — a finished morning round must sit before an
	 * afternoon scheduled round, not appended at the tail. */
	public function testWorkerFeedMergesChronologically(): void
	{
		$this->acl->method('visibleSiteIds')->willReturn([7]);
		$this->acl->method('isAppAdmin')->willReturn(false);
		$this->acl->method('canPlan')->willReturn(false);
		$done = new Round();
		$done->setId(1);
		$done->setScheduledStart(new \DateTime('2026-10-20T07:00:00Z'));
		$open = new Round();
		$open->setId(2);
		$open->setScheduledStart(new \DateTime('2026-10-20T15:00:00Z'));
		$this->rounds->method('findOpen')->willReturn([$open]);
		$this->rounds->method('findRecentlyCompleted')->willReturn([$done]);
		$feed = $this->svc->workerFeedForUser('w1');
		$this->assertSame([1, 2], array_map(static fn (Round $r) => $r->getId(), $feed));
	}

	/** Unrestricted tiers see the finished tail too — planner/admin
	 * feeds are not silently narrower than member feeds. */
	public function testWorkerFeedUnrestrictedCarriesTail(): void
	{
		$this->acl->method('visibleSiteIds')->willReturn([]);
		$this->acl->method('isAppAdmin')->with('boss')->willReturn(true);
		$done = new Round();
		$done->setId(5);
		$done->setScheduledStart(new \DateTime('2026-10-20T09:00:00Z'));
		$this->rounds->expects($this->once())
			->method('findOpen')
			->with([], true)
			->willReturn([]);
		$this->rounds->expects($this->once())
			->method('findRecentlyCompleted')
			->with([], true)
			->willReturn([$done]);
		$this->assertSame([$done], $this->svc->workerFeedForUser('boss'));
	}
}
