<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\AnchorMapper;
use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Db\ReportDeliveryMapper;
use OCA\TourCheck\Db\ReportSchedMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundCheckpoint;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\ReportService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * SLA denominator (spec): on-time % divides by SCHEDULED checkpoints —
 * skipped rounds excluded, not by "done". Dividing by done would hide
 * missed work entirely (1 done of 10 scheduled → 100%).
 */
final class ReportSlaTest extends TestCase
{
	private RoundMapper&MockObject $rounds;
	private CheckEventMapper&MockObject $events;
	private RoundCheckpointMapper&MockObject $roundCps;
	private IncidentMapper&MockObject $incidents;
	private AnchorMapper&MockObject $anchors;
	private ReportService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->rounds = $this->createMock(RoundMapper::class);
		$this->events = $this->createMock(CheckEventMapper::class);
		$this->roundCps = $this->createMock(RoundCheckpointMapper::class);
		$this->incidents = $this->createMock(IncidentMapper::class);
		$this->anchors = $this->createMock(AnchorMapper::class);
		$journal = $this->createMock(JournalEntryMapper::class);
		$journal->method('countForSite')->willReturn(0);
		$this->incidents->method('findForSiteBetween')->willReturn([]);
		$this->anchors->method('findLatestAnchored')->willReturn(null);
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanViewSite')->willReturn('planner1');
		$sites = $this->createMock(SiteService::class);
		$site = new Site();
		$site->setId(7);
		$site->setName('Alpha');
		$site->setTz('Europe/Berlin');
		$sites->method('getVisible')->willReturn($site);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t, $p = []) => vsprintf($t, (array)$p));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-20T12:00:00Z'));

		$this->svc = new ReportService(
			$this->rounds, $this->events, $this->roundCps,
			$this->createMock(ReportSchedMapper::class), $this->createMock(ReportDeliveryMapper::class),
			$journal, $this->anchors, $this->incidents,
			$acl, $sites, $this->createMock(SettingsService::class),
			$this->createMock(IUserManager::class), $this->createMock(IRootFolder::class),
			$this->createMock(IAppData::class), $this->createMock(IMailer::class),
			$this->createMock(LoggerInterface::class), $time,
			$this->createMock(IDBConnection::class), $l10n,
			$this->createMock(ChainService::class),
		);
	}

	private function round(int $id, string $status): Round
	{
		$r = new Round();
		$r->setId($id);
		$r->setSiteId(7);
		$r->setStatus($status);
		$r->setScheduledStart(new \DateTime('2026-10-01T08:00:00Z'));
		$r->setWindowEnd(new \DateTime('2026-10-01T10:00:00Z'));
		return $r;
	}

	private function cp(int $id): RoundCheckpoint
	{
		$c = new RoundCheckpoint();
		$c->setId($id);
		$c->setNameSnap('CP' . $id);
		$c->setStatus('pending');
		return $c;
	}

	private function event(int $id, int $rcpId): CheckEvent
	{
		$e = new CheckEvent();
		$e->setId($id);
		$e->setRoundCpId($rcpId);
		$e->setMethod('scan_qr');
		$e->setClientTs(new \DateTime('2026-10-01T09:00:00Z'));
		$e->setServerReceivedAt(new \DateTime('2026-10-01T09:00:01Z'));
		$e->setFlaggedEarly(0);
		$e->setLateSync(0);
		$e->setOrphan(0);
		$e->setTombstoned(0);
		return $e;
	}

	public function testSlaDividesByScheduledNotDone(): void
	{
		// 2 scheduled checkpoints, 1 on-time event → 50 %, never 100 %.
		$this->rounds->method('findScheduledBetween')->willReturn([$this->round(3, 'completed')]);
		$this->roundCps->method('findForRound')->with(3)->willReturn([$this->cp(91), $this->cp(92)]);
		$this->events->method('findForSiteBetween')->willReturn([$this->event(1, 91)]);

		$data = $this->svc->aggregate(7, '2026-10');
		self::assertSame(2, $data['totals']['sla_denom']);
		self::assertSame(1, $data['totals']['on_time']);
		self::assertSame(50.0, $data['sla']['on_time_pct']);
	}

	public function testSkippedRoundExcludedFromDenominator(): void
	{
		$this->rounds->method('findScheduledBetween')->willReturn([
			$this->round(3, 'completed'),
			$this->round(4, 'skipped'),
		]);
		$this->roundCps->method('findForRound')->willReturnCallback(
			fn (int $rid) => $rid === 3 ? [$this->cp(91)] : [$this->cp(95), $this->cp(96)]
		);
		$this->events->method('findForSiteBetween')->willReturn([$this->event(1, 91)]);

		$data = $this->svc->aggregate(7, '2026-10');
		// Skipped round's 2 cps leave the denominator — holiday ≠ missed work.
		self::assertSame(1, $data['totals']['sla_denom']);
		self::assertSame(100.0, $data['sla']['on_time_pct']);
		self::assertSame(3, $data['totals']['cps']); // still reported, just not SLA-scored
	}

	public function testAttestedDoesNotInflateOnTime(): void
	{
		$attested = $this->event(2, 91);
		$attested->setMethod('attested');
		$this->rounds->method('findScheduledBetween')->willReturn([$this->round(3, 'completed')]);
		$this->roundCps->method('findForRound')->willReturn([$this->cp(91), $this->cp(92)]);
		$this->events->method('findForSiteBetween')->willReturn([$attested, $this->event(1, 92)]);

		$data = $this->svc->aggregate(7, '2026-10');
		self::assertSame(1, $data['totals']['attested']);
		self::assertSame(1, $data['totals']['on_time']);
		self::assertSame(50.0, $data['sla']['on_time_pct']); // attested ≠ on_time
	}
}
