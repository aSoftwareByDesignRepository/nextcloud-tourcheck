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
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
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
 * GA-13/MH-18: a failed required checklist item cannot silently confirm —
 * it needs a note on the event or the worker's explicit incident report
 * (auto_incident → deduped 'checklist' incident + supervisor notification).
 * Optional items and passing answers never trigger the gate.
 */
final class RequiredChecklistFailureTest extends TestCase
{
	private const PROFILE = '{"items":['
		. '{"key":"door","label":"Door locked","required":true,"kind":"bool"},'
		. '{"key":"lights","label":"Lights off","required":false,"kind":"bool"},'
		. '{"key":"alarm","label":"Alarm note","required":true,"kind":"text"}'
		. ']}';

	private CheckEventMapper&MockObject $events;
	private TokenService&MockObject $tokens;
	private RoundService&MockObject $rounds;
	private ChainService&MockObject $chain;
	private IncidentService&MockObject $incidents;
	private NotificationService&MockObject $notifications;
	private IDBConnection&MockObject $db;
	private EventService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->events = $this->createMock(CheckEventMapper::class);
		$this->tokens = $this->createMock(TokenService::class);
		$this->rounds = $this->createMock(RoundService::class);
		$this->chain = $this->createMock(ChainService::class);
		$this->incidents = $this->createMock(IncidentService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$sites = $this->createMock(SiteService::class);
		$site = new Site();
		$site->setId(7);
		$site->setName('Depot');
		$sites->method('findById')->willReturn($site);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getLateSyncDays')->willReturn(7);
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanViewSite')->willReturn('worker1');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);
		$this->db = $this->createMock(IDBConnection::class);
		// updateRoundCp runs through a raw QB — stub it fluent.
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'update', 'set', 'where', 'andWhere', 'orderBy', 'delete', 'insert', 'values'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('executeStatement')->willReturn(1);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->svc = new EventService(
			$this->events,
			$this->tokens,
			$this->rounds,
			$this->chain,
			$settings,
			$acl,
			$time,
			$this->db,
			$this->createMock(LoggerInterface::class),
			null,
			null,
			$this->incidents,
			$this->notifications,
			$sites,
		);
	}

	private function input(array $over = []): array
	{
		return array_merge([
			'token' => 'tok_qr',
			'client_event_id' => 'ce_fail_1',
			'values' => ['door' => false, 'alarm' => 'ok'],
			'method' => 'scan_qr',
			'actor_uid' => 'worker1',
		], $over);
	}

	/** Wire the ingest path up to (but not through) the event insert. */
	private function wireAttach(): void
	{
		$this->events->method('findByClientEventId')->willReturn(null);
		$cp = new Checkpoint();
		$cp->setId(55);
		$cp->setSiteId(7);
		$token = new CheckpointToken();
		$token->setKind('qr');
		$this->tokens->method('resolve')->willReturn([$cp, $token]);
		$round = new Round();
		$round->setId(3);
		$round->setSiteId(7);
		$round->setStatus('active');
		$round->setWindowEnd(new \DateTime('2026-10-20T13:00:00Z'));
		$rcp = new RoundCheckpoint();
		$rcp->setId(9);
		$rcp->setRoundId(3);
		$rcp->setCheckpointId(55);
		$rcp->setProfileJson(self::PROFILE);
		$rcp->setStatus('pending');
		$this->rounds->method('attach')->willReturn([$round, $rcp]);
		$this->rounds->method('lockRound')->willReturn($round);
	}

	public function testFailedRequiredWithoutNoteOrIncidentIsRejected(): void
	{
		$this->wireAttach();
		$this->events->expects($this->never())->method('insert');
		$this->incidents->expects($this->never())->method('createSystem');
		$this->db->expects($this->never())->method('beginTransaction');

		try {
			$this->svc->ingest($this->input());
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('failed_required:door', $e->fields['values'] ?? null);
		}
	}

	public function testFailedRequiredWithNoteConfirmsWithoutIncident(): void
	{
		$this->wireAttach();
		$this->events->method('insert')->willReturnCallback(static function (CheckEvent $e) {
			$e->setId(42);
			return $e;
		});
		$this->incidents->expects($this->never())->method('createSystem');
		$this->db->method('beginTransaction');
		$this->db->method('commit');

		$r = $this->svc->ingest($this->input(['note' => 'Door was already open, notified caretaker']));
		self::assertFalse($r['replayed']);
		self::assertSame('Door was already open, notified caretaker', $r['event']->getNote());
	}

	public function testFailedRequiredWithAutoIncidentMintsDedupedIncidentAndNotifies(): void
	{
		$this->wireAttach();
		$this->events->method('insert')->willReturnCallback(static function (CheckEvent $e) {
			$e->setId(42);
			return $e;
		});
		$this->db->method('beginTransaction');
		$this->db->method('commit');

		$this->incidents->expects($this->once())->method('createSystem')
			->with(7, self::callback(static function (array $d) {
				return ($d['client_incident_id'] ?? '') === 'req-ce_fail_1'
					&& ($d['category'] ?? '') === 'checklist'
					&& ($d['severity'] ?? '') === 'medium'
					&& ($d['round_id'] ?? null) === 3
					&& ($d['checkpoint_id'] ?? null) === 55
					&& ($d['event_id'] ?? null) === 42;
			}))
			->willReturn(new Incident());
		$this->notifications->expects($this->once())->method('notifySite')
			->with(7, 'reqfail:42', 'checklist_failure');

		$r = $this->svc->ingest($this->input(['auto_incident' => true]));
		self::assertFalse($r['replayed']);
	}

	public function testOptionalFailedItemDoesNotTripTheGate(): void
	{
		// 'lights' is optional — failing it needs neither note nor incident.
		$this->wireAttach();
		$this->events->method('insert')->willReturnCallback(static function (CheckEvent $e) {
			$e->setId(42);
			return $e;
		});
		$this->incidents->expects($this->never())->method('createSystem');
		$this->db->method('beginTransaction');
		$this->db->method('commit');

		$this->svc->ingest($this->input(['values' => ['door' => true, 'lights' => false, 'alarm' => 'ok']]));
	}

	public function testPassingRequiredItemsConfirmNormally(): void
	{
		$this->wireAttach();
		$this->events->method('insert')->willReturnCallback(static function (CheckEvent $e) {
			$e->setId(42);
			return $e;
		});
		$this->incidents->expects($this->never())->method('createSystem');
		$this->db->method('beginTransaction');
		$this->db->method('commit');

		$this->svc->ingest($this->input(['values' => ['door' => true, 'alarm' => 'all set']]));
	}

	public function testFailedTextItemHasNoFailStateOnlyPresence(): void
	{
		// 'alarm' is required text — present-but-empty-looking content is a
		// presence question for validateValues, not a GA-13 failure.
		$this->wireAttach();
		$this->events->method('insert')->willReturnCallback(static function (CheckEvent $e) {
			$e->setId(42);
			return $e;
		});
		$this->incidents->expects($this->never())->method('createSystem');
		$this->db->method('beginTransaction');
		$this->db->method('commit');

		$this->svc->ingest($this->input(['values' => ['door' => true, 'alarm' => '-']]));
	}
}
