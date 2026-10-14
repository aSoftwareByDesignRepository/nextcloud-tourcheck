<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\CheckpointToken;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundCheckpoint;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\CheckpointService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\IncidentService;
use OCA\TourCheck\Service\NotificationService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\TokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * NH-05: number items with min/max bounds — an out-of-band submitted value
 * mints exactly one auto-incident (deduped via thr-{client_event_id}) plus
 * one deduped supervisor notification. In-band values mint nothing, and a
 * replayed event never reaches the check (early return upstream).
 */
final class ThresholdIncidentTest extends TestCase
{
	private CheckEventMapper&MockObject $events;
	private IncidentService&MockObject $incidents;
	private NotificationService&MockObject $notifications;
	private EventService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->events = $this->createMock(CheckEventMapper::class);
		$this->incidents = $this->createMock(IncidentService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getLateSyncDays')->willReturn(7);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);

		$tokens = $this->createMock(TokenService::class);
		$cp = new Checkpoint();
		$cp->setId(55);
		$cp->setSiteId(7);
		$token = new CheckpointToken();
		$token->setKind('qr');
		$tokens->method('resolve')->willReturn([$cp, $token]);

		$rounds = $this->createMock(RoundService::class);
		$round = new Round();
		$round->setId(3);
		$round->setSiteId(7);
		$round->setStatus('active');
		$round->setScheduledStart((clone $this->now)->modify('-1 hour'));
		$round->setWindowEnd((clone $this->now)->modify('+1 hour'));
		$rcp = new RoundCheckpoint();
		$rcp->setId(9);
		$rcp->setRoundId(3);
		$rcp->setCheckpointId(55);
		$rcp->setStatus('pending');
		$rcp->setProfileJson(json_encode(['items' => [[
			'key' => 'temp', 'label' => 'Fridge', 'kind' => 'number',
			'required' => true, 'min' => 2.0, 'max' => 8.0, 'unit' => '°C',
		]]]));
		$rounds->method('attach')->willReturn([$round, $rcp]);
		$rounds->method('lockRound')->willReturn($round);
		$rounds->method('checkpointsOf')->willReturn([$rcp]);
		$rounds->method('findById')->willReturn($round);

		$this->events->method('insert')->willReturnCallback(static function (CheckEvent $e): CheckEvent {
			$e->setId(42);
			return $e;
		});

		$sites = $this->createMock(SiteService::class);
		$site = new Site();
		$site->setName('Werk 1');
		$sites->method('findById')->willReturn($site);

		// updateRoundCp runs through a raw QB — stub it fluent.
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select','from','update','set','where','andWhere','orderBy','addOrderBy','delete','insert','values'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('executeStatement')->willReturn(1);
		$db->method('getQueryBuilder')->willReturn($qb);

		$this->svc = new EventService(
			$this->events,
			$tokens,
			$rounds,
			$this->createMock(ChainService::class),
			$settings,
			$this->createMock(AccessControlService::class),
			$time,
			$db,
			$this->createMock(LoggerInterface::class),
			null,
			null,
			$this->incidents,
			$this->notifications,
			$sites,
		);
	}

	private function input(array $values): array
	{
		return [
			'token' => 'tok_x',
			'client_event_id' => 'ce_thr',
			'client_ts' => '2026-10-20T11:30:00+00:00',
			'values' => $values,
			'round_id' => 3,
			'method' => 'auto',
			'actor_uid' => 'worker1',
		];
	}

	public function testOutOfBandValueMintsIncidentAndNotify(): void
	{
		$this->events->method('findByClientEventId')->willReturn(null);
		$this->incidents->expects($this->once())->method('createSystem')
			->with(7, self::callback(static fn (array $d): bool =>
				$d['category'] === 'threshold'
				&& $d['client_incident_id'] === 'thr-ce_thr'
				&& $d['event_id'] === 42
				&& $d['round_id'] === 3
				&& $d['checkpoint_id'] === 55
				&& str_contains((string)$d['note'], 'temp=12')
			))
			->willReturn(new Incident());
		$this->notifications->expects($this->once())->method('notifySite')
			->with(7, 'threshold:42', self::anything(), self::anything(), self::isInstanceOf(Site::class))
			->willReturn(true);

		$r = $this->svc->ingest($this->input(['temp' => 12]));
		self::assertTrue($r['attached']);
	}

	public function testUnderMinValueMintsIncident(): void
	{
		$this->events->method('findByClientEventId')->willReturn(null);
		$this->incidents->expects($this->once())->method('createSystem')
			->with(7, self::callback(static fn (array $d): bool =>
				$d['category'] === 'threshold' && str_contains((string)$d['note'], 'temp=0.5')
			))
			->willReturn(new Incident());
		$this->notifications->method('notifySite')->willReturn(true);

		// 0.5 < min 2.0 — the under-band leg must fire, not just over-max.
		$this->svc->ingest($this->input(['temp' => 0.5]));
	}

	public function testInBandValueMintsNothing(): void
	{
		$this->events->method('findByClientEventId')->willReturn(null);
		$this->incidents->expects($this->never())->method('createSystem');
		$this->notifications->expects($this->never())->method('notifySite');

		$this->svc->ingest($this->input(['temp' => 5.5]));
	}

	public function testReplayedEventNeverReachesThresholdCheck(): void
	{
		$stored = new CheckEvent();
		$stored->setId(42);
		$stored->setSiteId(7);
		$stored->setRoundId(3);
		$stored->setRoundCpId(9);
		$stored->setCheckpointId(55);
		$stored->setClientEventId('ce_thr');
		$stored->setActorUid('worker1');
		$stored->setMethod('scan_qr');
		$stored->setServerReceivedAt(clone $this->now);
		$stored->setValuesJson('{"temp":12}');
		$stored->setLateSync(0);
		$stored->setFlaggedEarly(0);
		$stored->setOrphan(0);
		$stored->setTombstoned(0);
		$stored->setCreatedAt(clone $this->now);
		$this->events->method('findByClientEventId')->willReturn($stored);
		$this->incidents->expects($this->never())->method('createSystem');

		$r = $this->svc->ingest($this->input(['temp' => 12]));
		self::assertTrue($r['replayed']);
	}
}
