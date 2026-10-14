<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\CheckpointToken;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\TokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * The idempotent-replay contract (NN-01 + GA-15): a retried client_event_id
 * returns the stored row; the SAME id with a DIFFERENT payload must journal
 * one replay_mismatch signal — never silently overwrite, never error out.
 * 'auto' method = server-derived from the token kind; the client claim only
 * feeds the method_lie signal.
 */
final class EventReplayTest extends TestCase
{
	private CheckEventMapper&MockObject $events;
	private TokenService&MockObject $tokens;
	private RoundService&MockObject $rounds;
	private ChainService&MockObject $chain;
	private AccessControlService&MockObject $acl;
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
		$this->acl = $this->createMock(AccessControlService::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getLateSyncDays')->willReturn(7);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);
		$this->db = $this->createMock(IDBConnection::class);

		$this->svc = new EventService(
			$this->events,
			$this->tokens,
			$this->rounds,
			$this->chain,
			$settings,
			$this->acl,
			$time,
			$this->db,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function storedEvent(array $mut = []): CheckEvent
	{
		$e = new CheckEvent();
		$e->setId(42);
		$e->setSiteId(7);
		$e->setRoundId(3);
		$e->setRoundCpId(9);
		$e->setCheckpointId(55);
		$e->setClientEventId('ce_1');
		$e->setActorUid('worker1');
		$e->setStationMemberId(null);
		$e->setStationId(null);
		$e->setMethod('scan_qr');
		$e->setServerReceivedAt(clone $this->now);
		$e->setClientTs(new \DateTime('2026-10-20T11:00:00+00:00'));
		$e->setValuesJson('{"a":1}');
		$e->setNote('ok');
		$e->setLateSync(0);
		$e->setFlaggedEarly(0);
		$e->setOrphan(0);
		$e->setTombstoned(0);
		$e->setCreatedAt(clone $this->now);
		foreach ($mut as $setter => $v) {
			$e->$setter($v);
		}
		return $e;
	}

	private function input(array $over = []): array
	{
		return array_merge([
			'token' => 'tok_dummy',
			'client_event_id' => 'ce_1',
			'client_ts' => '2026-10-20T11:00:00+00:00',
			'values' => ['a' => 1],
			'note' => 'ok',
			'round_id' => 3,
			'method' => 'auto',
			'method_claim' => 'scan_qr',
			'actor_uid' => 'worker1',
		], $over);
	}

	public function testIdenticalReplayIsClean(): void
	{
		$this->events->method('findByClientEventId')->willReturn($this->storedEvent());
		$this->acl->expects($this->once())->method('assertCanViewSite')->with(7)->willReturn('worker1');
		$this->chain->expects($this->never())->method('append');

		$r = $this->svc->ingest($this->input());
		self::assertTrue($r['replayed']);
		self::assertFalse($r['mismatch']);
		self::assertTrue($r['attached']);
		self::assertSame(42, (int)$r['event']->getId());
	}

	public function testReorderedValuesReplayCleanly(): void
	{
		// Key order is not evidence — canonical values compare.
		$this->events->method('findByClientEventId')
			->willReturn($this->storedEvent(['setValuesJson' => '{"a":1,"b":{"x":1,"y":2}}']));
		$r = $this->svc->ingest($this->input(['values' => ['b' => ['y' => 2, 'x' => 1], 'a' => 1]]));
		self::assertTrue($r['replayed']);
		self::assertFalse($r['mismatch']);
	}

	public function testDifferentPayloadJournalsReplayMismatch(): void
	{
		$this->events->method('findByClientEventId')->willReturn($this->storedEvent());
		$this->acl->method('assertCanViewSite')->willReturn('worker1');
		$this->chain->expects($this->once())->method('append')
			->with(7, self::callback(static fn (array $a) => $a['body'] === 'replay_mismatch' && $a['kind'] === 'admin'));

		$r = $this->svc->ingest($this->input(['note' => 'changed payload']));
		self::assertTrue($r['replayed']);
		self::assertTrue($r['mismatch']);
		self::assertSame('ok', $r['event']->getNote()); // stored row wins
	}

	public function testDivergingMethodClaimFlagsMismatch(): void
	{
		$this->events->method('findByClientEventId')->willReturn($this->storedEvent());
		$this->acl->method('assertCanViewSite')->willReturn('worker1');
		$this->chain->expects($this->once())->method('append');
		$r = $this->svc->ingest($this->input(['method_claim' => 'station']));
		self::assertTrue($r['mismatch']);
	}

	public function testAutoMethodNeverCountsAsMismatch(): void
	{
		// 'auto' is not a claim — comparing it verbatim would flag every replay.
		$this->events->method('findByClientEventId')->willReturn($this->storedEvent());
		$this->acl->method('assertCanViewSite')->willReturn('worker1');
		$r = $this->svc->ingest($this->input(['method_claim' => null]));
		self::assertFalse($r['mismatch']);
	}

	public function testForeignSiteReplayIsInvisible(): void
	{
		$this->events->method('findByClientEventId')->willReturn($this->storedEvent());
		$this->acl->method('assertCanViewSite')->willThrowException(new NotFoundException());
		$this->expectException(NotFoundException::class);
		$this->svc->ingest($this->input());
	}

	public function testOversizedValuesRejected(): void
	{
		$this->events->method('findByClientEventId')->willReturn(null);
		$cp = new Checkpoint();
		$cp->setId(55);
		$cp->setSiteId(7);
		$token = new CheckpointToken();
		$token->setKind('qr');
		$this->tokens->method('resolve')->willReturn([$cp, $token]);
		$this->acl->method('assertCanViewSite')->willReturn('worker1');
		$this->rounds->method('attach')->willReturn([null, null]);
		$this->expectException(ValidationException::class);
		$this->svc->ingest($this->input(['client_event_id' => 'ce_big', 'values' => ['blob' => str_repeat('x', 70000)]]));
	}
}
