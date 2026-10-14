<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Alert;
use OCA\TourCheck\Db\ManDownState;
use OCA\TourCheck\Db\ManDownStateMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\AlertService;
use OCA\TourCheck\Service\ManDownService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Man-down (F2): heartbeats only count from the round's own worker (a
 * supervisor must not silence another worker's clock), only on ACTIVE
 * rounds, only when the site opted in. An 'alarmed' phase must NOT be
 * reset by a late heartbeat. The server sweep is the dead-phone safety
 * net; it closes watches on dead rounds instead of firing.
 */
final class ManDownServiceTest extends TestCase
{
	private ManDownStateMapper&MockObject $states;
	private RoundService&MockObject $rounds;
	private SiteService&MockObject $sites;
	private AccessControlService&MockObject $acl;
	private AlertService&MockObject $alerts;
	private ManDownService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->states = $this->createMock(ManDownStateMapper::class);
		$this->rounds = $this->createMock(RoundService::class);
		$this->sites = $this->createMock(SiteService::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->alerts = $this->createMock(AlertService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);
		$time->method('getTime')->willReturnCallback(fn () => $this->now->getTimestamp());
		$this->svc = new ManDownService(
			$this->states, $this->alerts, $this->rounds, $this->sites,
			$this->acl, $time, $this->createMock(IDBConnection::class),
		);
	}

	private function round(string $status = 'active', string $starter = 'w1', int $siteId = 3): Round
	{
		$r = new Round();
		$r->setId(9);
		$r->setSiteId($siteId);
		$r->setStatus($status);
		$r->setStartedBy($starter);
		return $r;
	}

	private function site(?int $mandownMin = 10, int $preS = 60): Site
	{
		$s = new Site();
		$s->setId(3);
		$s->setMandownMin($mandownMin);
		$s->setMandownPreS($preS);
		return $s;
	}

	public function testHeartbeatOnInactiveRoundIs404(): void
	{
		$this->rounds->method('findById')->willReturn($this->round('completed'));
		$this->expectException(NotFoundException::class);
		$this->svc->heartbeat(9, 'w1');
	}

	public function testHeartbeatByOutsiderIs404(): void
	{
		$this->rounds->method('findById')->willReturn($this->round());
		$this->acl->method('siteRole')->willReturn(null); // not a member at all
		$this->expectException(NotFoundException::class);
		$this->svc->heartbeat(9, 'intruder');
	}

	public function testHeartbeatNoopWhenSiteOptedOut(): void
	{
		$this->rounds->method('findById')->willReturn($this->round());
		$this->sites->method('findById')->willReturn($this->site(null)); // disabled
		$this->states->expects($this->never())->method('insert');
		$this->states->expects($this->never())->method('update');
		$this->svc->heartbeat(9, 'w1');
	}

	public function testHeartbeatCreatesWatchingState(): void
	{
		$this->rounds->method('findById')->willReturn($this->round());
		$this->sites->method('findById')->willReturn($this->site());
		$this->states->method('findForWorker')->willReturn(null);
		$this->states->expects($this->once())->method('insert')
			->with($this->callback(static fn (ManDownState $s) => $s->getPhase() === 'watching'));
		$this->svc->heartbeat(9, 'w1');
	}

	public function testLateHeartbeatDoesNotResetAlarmedPhase(): void
	{
		$this->rounds->method('findById')->willReturn($this->round());
		$this->sites->method('findById')->willReturn($this->site());
		$st = new ManDownState();
		$st->setPhase('alarmed');
		$this->states->method('findForWorker')->willReturn($st);
		$this->states->expects($this->never())->method('update');
		$this->states->expects($this->never())->method('insert');
		$this->svc->heartbeat(9, 'w1');
	}

	public function testTriggerAlarmIsIdempotentWhileAlarmed(): void
	{
		$this->rounds->method('findById')->willReturn($this->round());
		$st = new ManDownState();
		$st->setPhase('alarmed');
		$this->states->method('findForWorker')->willReturn($st);
		$this->alerts->expects($this->never())->method('fire');
		$this->assertSame(['already' => true], $this->svc->triggerAlarm(9, 'w1'));
	}

	public function testTriggerAlarmFiresCriticalAlert(): void
	{
		$this->rounds->method('findById')->willReturn($this->round());
		$this->states->method('findForWorker')->willReturn(null);
		$alert = new Alert();
		$alert->setId(77);
		$this->alerts->expects($this->once())->method('fire')
			->with(Constants::ALERT_MANDOWN, 3, 'w1', 'critical', $this->anything(), $this->stringStartsWith('md:9:w1:'), 9)
			->willReturn($alert);
		$out = $this->svc->triggerAlarm(9, 'w1');
		$this->assertSame(77, $out['alertId']);
		$this->assertFalse($out['already']);
	}

	public function testTriggerAlarmByOutsiderIs404(): void
	{
		$this->rounds->method('findById')->willReturn($this->round());
		$this->acl->method('siteRole')->willReturn(null);
		$this->expectException(NotFoundException::class);
		$this->svc->triggerAlarm(9, 'intruder');
	}

	public function testSweepFiresOnStaleWatchingState(): void
	{
		$st = new ManDownState();
		$st->setRoundId(9);
		$st->setWorkerUid('w1');
		$st->setSiteId(3);
		$st->setPhase('watching');
		$st->setLastActivityAt((clone $this->now)->modify('-11 minutes')); // beyond 10 min
		$this->states->method('findStale')->willReturn([$st]);
		$this->sites->method('findById')->willReturn($this->site());
		$this->rounds->method('findById')->willReturn($this->round());
		$alert = new Alert();
		$alert->setId(1);
		$this->alerts->expects($this->once())->method('fire')
			->with(Constants::ALERT_MANDOWN, 3, 'w1', 'critical', ['source' => 'server_inactivity'], $this->anything(), 9)
			->willReturn($alert);
		$this->assertSame(1, $this->svc->sweep());
	}

	public function testSweepSkipsInWindowState(): void
	{
		$st = new ManDownState();
		$st->setRoundId(9);
		$st->setWorkerUid('w1');
		$st->setSiteId(3);
		$st->setPhase('watching');
		$st->setLastActivityAt((clone $this->now)->modify('-2 minutes')); // inside 10 min
		$this->states->method('findStale')->willReturn([$st]);
		$this->sites->method('findById')->willReturn($this->site());
		$this->alerts->expects($this->never())->method('fire');
		$this->assertSame(0, $this->svc->sweep());
	}

	public function testSweepClosesWatchWhenRoundEnded(): void
	{
		$st = new ManDownState();
		$st->setRoundId(9);
		$st->setWorkerUid('w1');
		$st->setSiteId(3);
		$st->setPhase('watching');
		$st->setLastActivityAt((clone $this->now)->modify('-60 minutes'));
		$this->states->method('findStale')->willReturn([$st]);
		$this->sites->method('findById')->willReturn($this->site());
		$this->rounds->method('findById')->willReturn($this->round('completed'));
		$this->states->expects($this->once())->method('update')
			->with($this->callback(static fn (ManDownState $s) => $s->getPhase() === 'closed'));
		$this->alerts->expects($this->never())->method('fire');
		$this->assertSame(0, $this->svc->sweep());
	}
}
