<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\CheckpointToken;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundCheckpoint;
use OCA\TourCheck\Integration\ArbeitszeitStampPushService;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\TokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F16 wiring: the round's FIRST attached evidence proposes a pending
 * ArbeitszeitCheck stamp (worker confirms there). Second scans, orphan
 * events, and replayed client_event_ids must never re-propose — the AZC
 * side additionally dedupes on the 'round:{id}:start' sourceRef.
 */
final class FirstScanStampPushTest extends TestCase
{
	private CheckEventMapper&MockObject $events;
	private RoundService&MockObject $rounds;
	private ArbeitszeitStampPushService&MockObject $push;
	private EventService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->events = $this->createMock(CheckEventMapper::class);
		$tokens = $this->createMock(TokenService::class);
		$this->rounds = $this->createMock(RoundService::class);
		$chain = $this->createMock(ChainService::class);
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanViewSite')->willReturn('worker1');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getLateSyncDays')->willReturn(7);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'update', 'set', 'where', 'andWhere'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('executeStatement')->willReturn(1);
		$db->method('getQueryBuilder')->willReturn($qb);

		$cp = new Checkpoint();
		$cp->setId(55);
		$cp->setSiteId(7);
		$token = new CheckpointToken();
		$token->setKind('qr');
		$tokens->method('resolve')->willReturn([$cp, $token]);

		$this->push = $this->createMock(ArbeitszeitStampPushService::class);

		$this->svc = new EventService(
			$this->events,
			$tokens,
			$this->rounds,
			$chain,
			$settings,
			$acl,
			$time,
			$db,
			$this->createMock(LoggerInterface::class),
			null, null, null, null, null, null, null,
			$this->push,
		);
	}

	/** Wire a clean non-replay ingest against round 3 / checkpoint 55. */
	private function wireAttachedScan(): void
	{
		$this->events->method('findByClientEventId')->willReturn(null);
		$this->events->method('insert')->willReturnCallback(static function (CheckEvent $e) {
			$e->setId(42);
			return $e;
		});
		$round = new Round();
		$round->setId(3);
		$round->setSiteId(7);
		$round->setStatus('active');
		$round->setWindowEnd(new \DateTime('2026-10-20T13:00:00Z'));
		$rcp = new RoundCheckpoint();
		$rcp->setId(9);
		$rcp->setRoundId(3);
		$rcp->setCheckpointId(55);
		$rcp->setStatus('pending');
		$this->rounds->method('attach')->willReturn([$round, $rcp]);
		$this->rounds->method('lockRound')->willReturn($round);
		$this->rounds->method('checkpointsOf')->willReturn([$rcp]);
	}

	private function input(): array
	{
		return [
			'token' => 'tok_qr',
			'client_event_id' => 'ce_f16_1',
			'method' => 'scan_qr',
			'actor_uid' => 'worker1',
		];
	}

	public function testFirstAttachedEventProposesStamp(): void
	{
		$this->wireAttachedScan();
		$this->events->method('countForRound')->with(3)->willReturn(1);
		$this->push->expects($this->once())->method('proposeFirstScan')
			->with(self::callback(static fn (CheckEvent $e) => (int)$e->getId() === 42
				&& $e->getActorUid() === 'worker1'
				&& (int)$e->getRoundId() === 3));
		$r = $this->svc->ingest($this->input());
		self::assertFalse($r['replayed']);
	}

	public function testSecondEventDoesNotRepropose(): void
	{
		$this->wireAttachedScan();
		$this->events->method('countForRound')->with(3)->willReturn(2);
		$this->push->expects($this->never())->method('proposeFirstScan');
		$this->svc->ingest($this->input());
	}

	public function testOrphanEventDoesNotPropose(): void
	{
		$this->events->method('findByClientEventId')->willReturn(null);
		$this->events->method('insert')->willReturnCallback(static function (CheckEvent $e) {
			$e->setId(42);
			return $e;
		});
		$this->rounds->method('attach')->willReturn([null, null]);
		$this->push->expects($this->never())->method('proposeFirstScan');
		$this->svc->ingest($this->input());
	}

	public function testReplayDoesNotPropose(): void
	{
		$stored = new CheckEvent();
		$stored->setId(42);
		$stored->setSiteId(7);
		$stored->setRoundId(3);
		$stored->setCheckpointId(55);
		$stored->setClientEventId('ce_f16_1');
		$stored->setActorUid('worker1');
		$stored->setMethod('scan_qr');
		$stored->setServerReceivedAt(clone $this->now);
		$this->events->method('findByClientEventId')->willReturn($stored);
		$this->push->expects($this->never())->method('proposeFirstScan');
		$this->svc->ingest($this->input());
	}
}
