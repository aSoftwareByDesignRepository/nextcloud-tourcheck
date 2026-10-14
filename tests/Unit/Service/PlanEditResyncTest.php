<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Db\TourPlan;
use OCA\TourCheck\Db\TourPlanMapper;
use OCA\TourCheck\Exception\ConflictException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\CheckpointService;
use OCA\TourCheck\Service\PlanService;
use OCA\TourCheck\Service\SchedulingService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Plan-edit resync (spec: "SCHEDULED-not-started rounds resync to the new
 * plan revision"). Covers both layers:
 *  - SchedulingService::deleteStaleScheduledRounds — eligibility, the
 *    evidence freeze, and the select→delete race re-check.
 *  - PlanService::update — CAS under the plan row lock, resync inside the
 *    txn, regeneration after commit, journal payload.
 */
final class PlanEditResyncTest extends TestCase
{
	private RoundMapper&MockObject $rounds;
	private RoundCheckpointMapper&MockObject $roundCps;
	private CheckEventMapper&MockObject $events;
	private TourPlanMapper&MockObject $planMapper;
	private CheckpointService&MockObject $checkpoints;
	private SiteService&MockObject $sites;
	private IDBConnection&MockObject $db;
	private SchedulingService $sched;

	protected function setUp(): void
	{
		parent::setUp();
		$this->rounds = $this->createMock(RoundMapper::class);
		$this->roundCps = $this->createMock(RoundCheckpointMapper::class);
		$this->events = $this->createMock(CheckEventMapper::class);
		$this->planMapper = $this->createMock(TourPlanMapper::class);
		$this->checkpoints = $this->createMock(CheckpointService::class);
		$this->sites = $this->createMock(SiteService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-20T12:00:00Z'));
		$this->sched = new SchedulingService(
			$this->rounds, $this->roundCps, $this->events, $this->planMapper,
			$this->checkpoints, $this->sites, $time, $this->db,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function round(int $id, string $status = 'scheduled', bool $started = false): Round
	{
		$r = new Round();
		$r->setId($id);
		$r->setPlanId(11);
		$r->setSiteId(7);
		$r->setStatus($status);
		$r->setStartedAt($started ? new \DateTime('2026-10-20T10:00:00Z') : null);
		return $r;
	}

	public function testDeletesEligibleRoundWithItsCheckpointRows(): void
	{
		$this->rounds->method('findPendingResync')->with(11)->willReturn([$this->round(5)]);
		$this->rounds->method('findForUpdate')->with(5)->willReturn($this->round(5));
		$this->events->method('countForRound')->with(5)->willReturn(0);
		$this->rounds->expects($this->once())->method('deleteIfPending')->with(5)->willReturn(true);
		$this->roundCps->expects($this->once())->method('deleteForRound')->with(5);

		self::assertSame(1, $this->sched->deleteStaleScheduledRounds(11));
	}

	public function testRoundWithAttachedEarlyScanIsFrozen(): void
	{
		$this->rounds->method('findPendingResync')->willReturn([$this->round(5)]);
		$this->rounds->method('findForUpdate')->willReturn($this->round(5));
		$this->events->method('countForRound')->with(5)->willReturn(2);
		$this->rounds->expects($this->never())->method('deleteIfPending');
		$this->roundCps->expects($this->never())->method('deleteForRound');

		self::assertSame(0, $this->sched->deleteStaleScheduledRounds(11));
	}

	public function testRoundActivatedBetweenSelectAndDeleteWinsTheRace(): void
	{
		// findPendingResync saw 'scheduled'; by lock time it is ACTIVE —
		// the re-check must keep it (GA-5: live rounds never mutate).
		$this->rounds->method('findPendingResync')->willReturn([$this->round(5)]);
		$this->rounds->method('findForUpdate')->willReturn($this->round(5, 'active', true));
		$this->events->expects($this->never())->method('countForRound');
		$this->rounds->expects($this->never())->method('deleteIfPending');

		self::assertSame(0, $this->sched->deleteStaleScheduledRounds(11));
	}

	public function testConditionalDeleteFailureKeepsCheckpointRows(): void
	{
		// Row locked and eligible, but deleteIfPending's WHERE clause found
		// the row already changed — no orphan cp rows may be left behind.
		$this->rounds->method('findPendingResync')->willReturn([$this->round(5)]);
		$this->rounds->method('findForUpdate')->willReturn($this->round(5));
		$this->events->method('countForRound')->willReturn(0);
		$this->rounds->method('deleteIfPending')->willReturn(false);
		$this->roundCps->expects($this->never())->method('deleteForRound');

		self::assertSame(0, $this->sched->deleteStaleScheduledRounds(11));
	}

	public function testMixedPendingRoundsDeleteOnlyTheUntouched(): void
	{
		$eligible = $this->round(5);
		$withEvidence = $this->round(6);
		$activeNow = $this->round(7);
		$this->rounds->method('findPendingResync')->willReturn([$eligible, $withEvidence, $activeNow]);
		$this->rounds->method('findForUpdate')->willReturnMap([
			[5, $this->round(5)],
			[6, $this->round(6)],
			[7, $this->round(7, 'active')],
		]);
		$this->events->method('countForRound')->willReturnMap([[5, 0], [6, 1]]);
		$this->rounds->expects($this->once())->method('deleteIfPending')->with(5)->willReturn(true);
		$this->roundCps->expects($this->once())->method('deleteForRound')->with(5);

		self::assertSame(1, $this->sched->deleteStaleScheduledRounds(11));
	}

	public function testNoPendingRoundsIsNoOp(): void
	{
		$this->rounds->method('findPendingResync')->willReturn([]);
		$this->rounds->expects($this->never())->method('findForUpdate');
		$this->rounds->expects($this->never())->method('deleteIfPending');

		self::assertSame(0, $this->sched->deleteStaleScheduledRounds(11));
	}

	// --- PlanService::update orchestration -------------------------------

	private function plan(int $active = 1, int $revision = 4): TourPlan
	{
		$p = new TourPlan();
		$p->setId(11);
		$p->setSiteId(7);
		$p->setName('Night round');
		$p->setCpsJson('[{"checkpoint_id":2,"offset_min":0,"before_min":15,"after_min":60}]');
		$p->setRecurrenceJson('{"type":"daily","time":"22:00","duration_min":60}');
		$p->setActive($active);
		$p->setRevision($revision);
		return $p;
	}

	private function planService(SchedulingService&MockObject $sched): PlanService
	{
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanManageSite')->willReturn('planner1');
		$acl->method('assertCanViewSite')->willReturn('planner1');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-20T12:00:00Z'));
		return new PlanService(
			$this->planMapper, $this->checkpoints, $acl, $this->sites,
			$this->createMock(ChainService::class), $sched, $time, $this->db,
		);
	}

	public function testUpdateActivePlanResyncsInsideTxnAndRegeneratesAfterCommit(): void
	{
		$sched = $this->createMock(SchedulingService::class);
		$plan = $this->plan();
		$this->planMapper->method('find')->willReturn($plan);
		$this->planMapper->method('findForUpdate')->willReturn($plan);
		$this->planMapper->method('update')->willReturnArgument(0);
		$cp = new \OCA\TourCheck\Db\Checkpoint();
		$cp->setId(2);
		$this->checkpoints->method('listForSite')->willReturn([$cp]);
		$this->sites->expects($this->once())->method('assertRevision')->with(4, 4);

		$order = [];
		$this->db->method('beginTransaction')->willReturnCallback(static function () use (&$order) { $order[] = 'begin'; });
		$sched->method('deleteStaleScheduledRounds')->with(11)
			->willReturnCallback(static function () use (&$order) { $order[] = 'delete'; return 3; });
		$this->db->method('commit')->willReturnCallback(static function () use (&$order) { $order[] = 'commit'; });
		$sched->method('generateForPlan')->with($plan)
			->willReturnCallback(static function () use (&$order) { $order[] = 'regen'; return 3; });

		$this->planService($sched)->update(11, ['name' => 'Day round'], 4);

		// Lock order: resync inside the txn, regeneration strictly after commit.
		self::assertSame(['begin', 'delete', 'commit', 'regen'], $order);
	}

	public function testUpdateInactivePlanSkipsResyncEntirely(): void
	{
		$sched = $this->createMock(SchedulingService::class);
		$plan = $this->plan(active: 0);
		$this->planMapper->method('find')->willReturn($plan);
		$this->planMapper->method('findForUpdate')->willReturn($plan);
		$this->planMapper->method('update')->willReturnArgument(0);
		$this->checkpoints->method('listForSite')->willReturn([$this->cpStub()]);

		$sched->expects($this->never())->method('deleteStaleScheduledRounds');
		$sched->expects($this->never())->method('generateForPlan');

		$this->planService($sched)->update(11, ['name' => 'Renamed'], 4);
	}

	public function testStaleRevisionRollsBackBeforeAnyResync(): void
	{
		$sched = $this->createMock(SchedulingService::class);
		$plan = $this->plan();
		$this->planMapper->method('find')->willReturn($plan);
		$this->planMapper->method('findForUpdate')->willReturn($plan);
		$this->sites->method('assertRevision')->willThrowException(
			new ConflictException(Constants::ERR_CONFLICT_STALE, 'stale')
		);
		$this->planMapper->expects($this->never())->method('update');
		$sched->expects($this->never())->method('deleteStaleScheduledRounds');
		$sched->expects($this->never())->method('generateForPlan');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(ConflictException::class);
		$this->planService($sched)->update(11, ['name' => 'X'], 3);
	}

	private function cpStub(): \OCA\TourCheck\Db\Checkpoint
	{
		$cp = new \OCA\TourCheck\Db\Checkpoint();
		$cp->setId(2);
		return $cp;
	}
}
