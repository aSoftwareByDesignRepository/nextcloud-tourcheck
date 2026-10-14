<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\JournalEntry;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Payload binding: verify() must not only replay the entry-hash chain but
 * recompute payload_hash against the referenced write-once row — an UPDATE
 * on oc_tck_event that leaves the journal untouched must read as TAMPERED.
 */
final class ChainVerifyPayloadTest extends TestCase
{
	private const SEED = 'aa'; // hex, 32× repeated in setUp

	private JournalEntryMapper&MockObject $journal;
	private CheckEventMapper&MockObject $events;
	private SettingsService&MockObject $settings;
	private ChainService $chain;
	private string $seed;

	protected function setUp(): void
	{
		parent::setUp();
		$this->seed = str_repeat('aa', 32);
		$this->journal = $this->createMock(JournalEntryMapper::class);
		$this->events = $this->createMock(CheckEventMapper::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('siteChainSeed')->willReturn($this->seed);
		$this->chain = new ChainService(
			$this->journal,
			$this->events,
			$this->settings,
			$this->createMock(ILockingProvider::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function event(array $mut = []): CheckEvent
	{
		$e = new CheckEvent();
		$e->setId(42);
		$e->setSiteId(7);
		$e->setRoundId(3);
		$e->setRoundCpId(9);
		$e->setCheckpointId(55);
		$e->setActorUid('worker1');
		$e->setStationMemberId(null);
		$e->setMethod('scan_qr');
		$e->setServerReceivedAt(new \DateTime('2026-10-20T08:00:00Z'));
		$e->setClientTs(new \DateTime('2026-10-20T07:59:58Z'));
		$e->setValuesJson('{"door":"closed"}');
		$e->setNote('all clear');
		$e->setPhotoFileid(null);
		$e->setLateSync(0);
		$e->setFlaggedEarly(0);
		$e->setOrphan(0);
		$e->setTombstoned(0);
		$e->setCreatedAt(new \DateTime('2026-10-20T08:00:00Z'));
		foreach ($mut as $setter => $v) {
			$e->$setter($v);
		}
		return $e;
	}

	private function entryFor(CheckEvent $ev): JournalEntry
	{
		$ts = new \DateTime('2026-10-20T08:00:01Z');
		$fields = [
			'seq' => 1, 'siteId' => 7, 'roundId' => 3, 'kind' => 'check',
			'actorUid' => 'worker1', 'memberId' => 0, 'body' => 'scan_qr',
			'payloadRef' => 'check_event:42',
			'payloadHash' => ChainService::payloadHash(EventService::eventPayloadFields($ev)),
			'serverTs' => ChainService::ts($ts),
		];
		$j = new JournalEntry();
		$j->setId(1);
		$j->setSiteId(7);
		$j->setSeq(1);
		$j->setRoundId(3);
		$j->setKind('check');
		$j->setActorUid('worker1');
		$j->setMemberId(null);
		$j->setBody('scan_qr');
		$j->setPayloadRef('check_event:42');
		$j->setPayloadHash($fields['payloadHash']);
		$j->setPrevHash($this->seed);
		$j->setEntryHash(ChainService::entryHashFor($this->seed, ChainService::canonicalEntry($fields)));
		$j->setServerTs($ts);
		return $j;
	}

	private function verifyWith(?CheckEvent $stored): array
	{
		$entry = $this->entryFor($this->event());
		$this->journal->method('findAllForSite')->willReturn([$entry]);
		$this->events->method('find')->with(42)->willReturn($stored);
		return $this->chain->verify(7);
	}

	public function testIntactPayloadVerifies(): void
	{
		$r = $this->verifyWith($this->event());
		self::assertTrue($r['ok']);
		self::assertSame('VERIFIED', $r['verdict']);
		self::assertSame(1, $r['payload_checked']);
		self::assertSame(0, $r['tombstoned']);
	}

	public function testMutatedRowIsTampered(): void
	{
		// Method rewritten in-place — journal row untouched, payload diverges.
		$r = $this->verifyWith($this->event(['setMethod' => 'attested']));
		self::assertFalse($r['ok']);
		self::assertSame('payload_mismatch', $r['reason']);
		self::assertSame('TAMPERED', $r['verdict']);
		self::assertSame(1, $r['break_at_seq']);
	}

	public function testMutatedNoteIsTampered(): void
	{
		$r = $this->verifyWith($this->event(['setNote' => 'forged note']));
		self::assertFalse($r['ok']);
		self::assertSame('payload_mismatch', $r['reason']);
	}

	public function testMissingRowIsTampered(): void
	{
		$r = $this->verifyWith(null);
		self::assertFalse($r['ok']);
		self::assertSame('payload_missing', $r['reason']);
	}

	public function testTombstonedRowVerifiesAsTombstoned(): void
	{
		// Erasure nulls the content columns — intentionally, verifiably gone.
		$r = $this->verifyWith($this->event(['setTombstoned' => 1, 'setNote' => null, 'setValuesJson' => null]));
		self::assertTrue($r['ok']);
		self::assertSame('TOMBSTONED', $r['verdict']);
		self::assertSame(1, $r['tombstoned']);
	}

	private function secondEntry(JournalEntry $first, ?string $prevOverride = null): JournalEntry
	{
		$ts = new \DateTime('2026-10-20T08:00:02Z');
		$fields = [
			'seq' => 2, 'siteId' => 7, 'roundId' => 0, 'kind' => 'admin',
			'actorUid' => 'sup1', 'memberId' => 0, 'body' => 'station_created',
			'payloadRef' => 'station:11',
			'payloadHash' => ChainService::payloadHash(['station' => 11]),
			'serverTs' => ChainService::ts($ts),
		];
		$prev = $prevOverride ?? (string)$first->getEntryHash();
		$j = new JournalEntry();
		$j->setId(2);
		$j->setSiteId(7);
		$j->setSeq(2);
		$j->setKind('admin');
		$j->setActorUid('sup1');
		$j->setBody('station_created');
		$j->setPayloadRef('station:11');
		$j->setPayloadHash($fields['payloadHash']);
		$j->setPrevHash($prev);
		// entry_hash commits to the STORED prev — tampering prev_hash must
		// break the link check, not the hash itself.
		$j->setEntryHash(ChainService::entryHashFor($prev, ChainService::canonicalEntry($fields)));
		$j->setServerTs($ts);
		return $j;
	}

	public function testCorruptedPrevLinkIsTampered(): void
	{
		$e1 = $this->entryFor($this->event());
		// A row rewrite that preserves entry_hash but swaps prev_hash (e.g.
		// someone relinking after deleting the true predecessor).
		$e2 = $this->secondEntry($e1, str_repeat('bb', 32));
		$this->journal->method('findAllForSite')->willReturn([$e1, $e2]);
		$this->events->method('find')->willReturn($this->event());

		$r = $this->chain->verify(7);
		self::assertFalse($r['ok']);
		self::assertSame('prev_mismatch', $r['reason']);
		self::assertSame(2, $r['break_at_seq']);
	}

	public function testSeqGapIsTampered(): void
	{
		$e1 = $this->entryFor($this->event());
		$e2 = $this->secondEntry($e1);
		// Row deleted mid-chain — seq 2 directly after seq 1's hash but the
		// entry claims seq 3.
		$e2->setSeq(3);
		$this->journal->method('findAllForSite')->willReturn([$e1, $e2]);
		$this->events->method('find')->willReturn($this->event());

		$r = $this->chain->verify(7);
		self::assertFalse($r['ok']);
		self::assertSame('seq_gap', $r['reason']);
	}
}
