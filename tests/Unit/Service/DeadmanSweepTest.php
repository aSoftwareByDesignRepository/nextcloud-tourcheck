<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Db\TourPlan;
use OCA\TourCheck\Db\TourPlanMapper;
use OCA\TourCheck\Service\MissedService;
use OCA\TourCheck\Service\NotificationService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * NH-07 dead-man sweep: an ACTIVE round whose last activity is older than
 * the plan's check-in interval mints one deduped strike notification per
 * interval boundary. In-window silence stays silent, strike keys escalate
 * (strike 2 is a NEW dedupe key, not a repeat), and position heartbeats
 * count as activity too.
 */
final class DeadmanSweepTest extends TestCase
{
	private RoundService&MockObject $rounds;
	private NotificationService&MockObject $notifications;
	private SiteService&MockObject $sites;
	private TourPlanMapper&MockObject $plans;
	private MissedService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->rounds = $this->createMock(RoundService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->sites = $this->createMock(SiteService::class);
		$this->plans = $this->createMock(TourPlanMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);
		$this->db = $this->createMock(IDBConnection::class);
		$this->svc = new MissedService(
			$this->rounds,
			$this->notifications,
			$this->sites,
			$time,
			$this->db,
			$this->plans,
		);
	}

	private IDBConnection&MockObject $db;

	/** @param array<int, ?string> $maxTimes tck_event, tck_position */
	private function dbWithActivity(array $maxTimes): void
	{
		$i = 0;
		$this->db->method('getQueryBuilder')->willReturnCallback(function () use (&$i, $maxTimes) {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
			$qb->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
			foreach (['select', 'from', 'where', 'andWhere'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$stmt = $this->createMock(\OCP\DB\IResult::class);
			$val = $maxTimes[$i] ?? null;
			$i++;
			$stmt->method('fetchOne')->willReturn($val);
			$qb->method('executeQuery')->willReturn($stmt);
			return $qb;
		});
	}

	private function round(): Round
	{
		$r = new Round();
		$r->setId(3);
		$r->setSiteId(7);
		$r->setPlanId(11);
		$r->setStatus('active');
		$r->setScheduledStart((clone $this->now)->modify('-2 hours'));
		$r->setWindowEnd((clone $this->now)->modify('+2 hours'));
		return $r;
	}

	private function plan(int $intervalMin): TourPlan
	{
		$p = new TourPlan();
		$p->setId(11);
		$p->setCheckinIntervalMin($intervalMin);
		return $p;
	}

	public function testSilentRoundPastIntervalNotifies(): void
	{
		$r = $this->round();
		$r->setStartedAt((clone $this->now)->modify('-40 minutes'));
		$this->rounds->method('findOverdue')->willReturn([]);
		$this->rounds->method('findActiveWithCheckin')->willReturn([$r]);
		$this->plans->method('find')->willReturn($this->plan(30));
		$this->dbWithActivity([null, null]);
		$site = new Site();
		$site->setId(7);
		$this->sites->method('findById')->willReturn($site);
		$this->notifications->expects($this->once())
			->method('notifySite')
			->with(7, 'deadman:round:3:1', 'deadman_alert', $this->anything(), $site)
			->willReturn(true);
		$this->svc->sweep();
	}

	public function testInWindowRoundStaysSilent(): void
	{
		$r = $this->round();
		$r->setStartedAt((clone $this->now)->modify('-10 minutes'));
		$this->rounds->method('findOverdue')->willReturn([]);
		$this->rounds->method('findActiveWithCheckin')->willReturn([$r]);
		$this->plans->method('find')->willReturn($this->plan(30));
		$this->dbWithActivity([null, null]);
		$this->notifications->expects($this->never())->method('notifySite');
		$this->svc->sweep();
	}

	public function testPositionHeartbeatResetsTheClock(): void
	{
		$r = $this->round();
		$r->setStartedAt((clone $this->now)->modify('-90 minutes'));
		$this->rounds->method('findOverdue')->willReturn([]);
		$this->rounds->method('findActiveWithCheckin')->willReturn([$r]);
		$this->plans->method('find')->willReturn($this->plan(30));
		// Event table empty, position pinged 10 min ago → in-window, silent.
		$this->dbWithActivity([null, (clone $this->now)->modify('-10 minutes')->format('Y-m-d H:i:s')]);
		$this->notifications->expects($this->never())->method('notifySite');
		$this->svc->sweep();
	}

	public function testStrikeEscalationUsesLevelDedupe(): void
	{
		$r = $this->round();
		$r->setStartedAt((clone $this->now)->modify('-75 minutes'));
		$this->rounds->method('findOverdue')->willReturn([]);
		$this->rounds->method('findActiveWithCheckin')->willReturn([$r]);
		$this->plans->method('find')->willReturn($this->plan(30));
		$this->dbWithActivity([null, null]);
		$site = new Site();
		$this->sites->method('findById')->willReturn($site);
		// 75 min at 30 min interval → strike 2 — a distinct dedupe key.
		$this->notifications->expects($this->once())
			->method('notifySite')
			->with(7, 'deadman:round:3:2', 'deadman_alert', $this->anything(), $site)
			->willReturn(true);
		$this->svc->sweep();
	}

	public function testPlanWithoutIntervalIsSkipped(): void
	{
		$this->rounds->method('findOverdue')->willReturn([]);
		$this->rounds->method('findActiveWithCheckin')->willReturn([$this->round()]);
		$this->plans->method('find')->willReturn($this->plan(0));
		$this->notifications->expects($this->never())->method('notifySite');
		$this->svc->sweep();
	}
}
