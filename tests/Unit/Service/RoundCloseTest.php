<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundCheckpoint;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\RoundService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Round close/skip: one transaction, round row locked FIRST (round→chain
 * lock order), remaining checkpoints skipped, status recomputed through the
 * single writer, transitions + control action journaled.
 */
final class RoundCloseTest extends TestCase
{
	private RoundMapper&MockObject $rounds;
	private RoundCheckpointMapper&MockObject $roundCps;
	private CheckEventMapper&MockObject $events;
	private AccessControlService&MockObject $acl;
	private ChainService&MockObject $chain;
	private IDBConnection&MockObject $db;
	private RoundService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->rounds = $this->createMock(RoundMapper::class);
		$this->roundCps = $this->createMock(RoundCheckpointMapper::class);
		$this->events = $this->createMock(CheckEventMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->chain = $this->createMock(ChainService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);
		$this->svc = new RoundService(
			$this->rounds, $this->roundCps, $this->events, $this->acl, $this->chain, $time, $this->db,
		);
	}

	private function round(string $status): Round
	{
		$r = new Round();
		$r->setId(3);
		$r->setSiteId(7);
		$r->setStatus($status);
		$r->setScheduledStart((clone $this->now)->modify('-2 hours'));
		$r->setWindowEnd((clone $this->now)->modify('+2 hours'));
		return $r;
	}

	private function pendingCp(int $id): RoundCheckpoint
	{
		$c = new RoundCheckpoint();
		$c->setId($id);
		$c->setRoundId(3);
		$c->setStatus('pending');
		return $c;
	}

	public function testMissingRoundIs404WithoutTransaction(): void
	{
		$this->rounds->method('find')->willReturn(null);
		$this->db->expects($this->never())->method('beginTransaction');
		$this->expectException(NotFoundException::class);
		$this->svc->closeEarly(999);
	}

	public function testClosedRoundRejectsInsideTransactionAndRollsBack(): void
	{
		$this->rounds->method('find')->willReturn($this->round('completed'));
		$this->acl->method('assertCanViewSite')->willReturn('sup1');
		$this->rounds->method('findForUpdate')->willReturn($this->round('completed'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->roundCps->expects($this->never())->method('update');
		try {
			$this->svc->closeEarly(3);
			self::fail('expected DomainException');
		} catch (DomainException $e) {
			self::assertSame('ROUND_CLOSED', $e->errorCode);
			self::assertSame(409, $e->httpStatus);
		}
	}

	public function testCloseEarlySkipsPendingAndJournals(): void
	{
		$round = $this->round('active');
		$this->rounds->method('find')->willReturn($round);
		$this->acl->method('assertCanViewSite')->willReturn('sup1');
		// Lock order: findForUpdate inside tx. Second call (recompute) returns
		// the same entity — status transitions through the single writer.
		$this->rounds->method('findForUpdate')->willReturn($round);
		$this->roundCps->method('findForRound')->willReturn([$this->pendingCp(9), $this->pendingCp(10)]);
		$this->roundCps->expects($this->exactly(2))->method('update')
			->with(self::callback(static fn (RoundCheckpoint $c) => $c->getStatus() === 'skipped'));

		$bodies = [];
		$this->chain->method('append')->willReturnCallback(static function (int $siteId, array $a) use (&$bodies) {
			$bodies[] = $a['body'];
			return new \OCA\TourCheck\Db\JournalEntry();
		});
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->svc->closeEarly(3);
		// Every checkpoint skipped → 'partial', not 'completed': a zero-work
		// "completion" would inflate the SLA attainment count.
		self::assertSame('partial', $round->getStatus());
		self::assertSame('sup1', $round->getCompletedBy());
		self::assertContains('round closed early: 2 checkpoint(s) skipped', $bodies);
		self::assertContains('round_status:active→partial', $bodies);
	}

	public function testCloseEarlyWithDoneCpsIsPartial(): void
	{
		$round = $this->round('active');
		$this->rounds->method('find')->willReturn($round);
		$this->acl->method('assertCanViewSite')->willReturn('sup1');
		$this->rounds->method('findForUpdate')->willReturn($round);
		$done = $this->pendingCp(9);
		$done->setStatus('done');
		$this->roundCps->method('findForRound')->willReturn([$done, $this->pendingCp(10)]);

		$this->svc->closeEarly(3);
		// 1 done + 1 skipped → partial (some real work, some skipped).
		self::assertSame('partial', $round->getStatus());
	}

	public function testFullyDoneRoundStillCompletes(): void
	{
		$round = $this->round('active');
		$this->rounds->method('find')->willReturn($round);
		$this->acl->method('assertCanViewSite')->willReturn('sup1');
		$this->rounds->method('findForUpdate')->willReturn($round);
		$done = $this->pendingCp(9);
		$done->setStatus('done');
		$this->roundCps->method('findForRound')->willReturn([$done]);

		$this->svc->closeEarly(3);
		self::assertSame('completed', $round->getStatus());
	}

	public function testCloseEarlyRejectsScheduledRound(): void
	{
		// A not-yet-started round must not be closed into a fabricated
		// 'partial' — planned rounds are cancelled via skip() (supervisor).
		$this->rounds->method('find')->willReturn($this->round('scheduled'));
		$this->acl->method('assertCanViewSite')->willReturn('w1');
		$this->rounds->method('findForUpdate')->willReturn($this->round('scheduled'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->roundCps->expects($this->never())->method('update');
		try {
			$this->svc->closeEarly(3);
			self::fail('expected DomainException');
		} catch (DomainException $e) {
			self::assertSame('ROUND_CLOSED', $e->errorCode);
			self::assertSame(409, $e->httpStatus);
		}
	}

	/** @return list<list<string>> */
	public static function terminalStatuses(): array
	{
		return [['completed'], ['partial'], ['missed'], ['skipped']];
	}

	/** @dataProvider terminalStatuses */
	public function testSkipRejectsTerminalRound(string $status): void
	{
		// Skipping a terminal round rewrites the accountability record:
		// completed/partial → attainment becomes "planned non-run",
		// missed → no-show whitewashed, re-skip → reason silently replaced.
		$this->rounds->method('find')->willReturn($this->round($status));
		$this->acl->method('assertCanViewSite')->willReturn('sup1');
		$this->acl->method('assertCanSuperviseSite')->willReturn('sup1');
		$this->rounds->method('findForUpdate')->willReturn($this->round($status));
		$this->events->expects($this->never())->method('countForRound');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		try {
			$this->svc->skip(3, 'holiday');
			self::fail('expected DomainException');
		} catch (DomainException $e) {
			self::assertSame('ROUND_CLOSED', $e->errorCode);
			self::assertSame(409, $e->httpStatus);
		}
	}

	public function testSkipRejectsActiveRoundWithRecordedWork(): void
	{
		// An active round carrying events has real work — skipping it would
		// hide that; it must go through closeEarly (→ partial) instead.
		$this->rounds->method('find')->willReturn($this->round('active'));
		$this->acl->method('assertCanViewSite')->willReturn('sup1');
		$this->acl->method('assertCanSuperviseSite')->willReturn('sup1');
		$this->rounds->method('findForUpdate')->willReturn($this->round('active'));
		$this->events->method('countForRound')->willReturn(2);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		try {
			$this->svc->skip(3, 'holiday');
			self::fail('expected DomainException');
		} catch (DomainException $e) {
			self::assertSame('ROUND_CLOSED', $e->errorCode);
		}
	}

	public function testSkipRoutesThroughRecomputeAndJournals(): void
	{
		$round = $this->round('scheduled');
		$this->rounds->method('find')->willReturn($round);
		$this->acl->method('assertCanViewSite')->willReturn('sup1');
		$this->acl->method('assertCanSuperviseSite')->willReturn('sup1');
		$this->rounds->method('findForUpdate')->willReturn($round);

		$bodies = [];
		$this->chain->method('append')->willReturnCallback(static function (int $siteId, array $a) use (&$bodies) {
			$bodies[] = $a['body'];
			return new \OCA\TourCheck\Db\JournalEntry();
		});
		$this->db->expects($this->once())->method('commit');

		$result = $this->svc->skip(3, 'holiday');
		// Status went through recomputeStatus (skip_reason drives ROUND_SKIPPED).
		self::assertSame('skipped', $round->getStatus());
		self::assertSame('holiday', $round->getSkipReason());
		self::assertContains('round_skipped:holiday', $bodies);
		self::assertContains('round_status:scheduled→skipped', $bodies);
	}
}
