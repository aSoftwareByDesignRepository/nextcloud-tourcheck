<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\CheckpointToken;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\PhotoService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\TokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * NH-02 geo-stamp contract + photo reference binding:
 *  - geo is stored ONLY when the checkpoint opts in (minimization);
 *  - geo claims on non-geo checkpoints are rejected, not silently dropped;
 *  - geo participates in payload binding + replay-mismatch compare;
 *  - photo_fileid must reference a photo owned by the event's site.
 */
final class GeoStampTest extends TestCase
{
	private CheckEventMapper&MockObject $events;
	private TokenService&MockObject $tokens;
	private RoundService&MockObject $rounds;
	private ChainService&MockObject $chain;
	private PhotoService&MockObject $photos;
	private EventService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->events = $this->createMock(CheckEventMapper::class);
		$this->tokens = $this->createMock(TokenService::class);
		$this->rounds = $this->createMock(RoundService::class);
		$this->chain = $this->createMock(ChainService::class);
		$this->photos = $this->createMock(PhotoService::class);
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanViewSite')->willReturn('worker1');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getLateSyncDays')->willReturn(7);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-21T12:00:00Z'));
		$this->svc = new EventService(
			$this->events,
			$this->tokens,
			$this->rounds,
			$this->chain,
			$settings,
			$acl,
			$time,
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
			null,
			$this->photos,
		);
	}

	private function checkpoint(bool $geo): Checkpoint
	{
		$cp = new Checkpoint();
		$cp->setId(55);
		$cp->setSiteId(7);
		if ($geo) {
			$cp->setGeoLat(52.52);
			$cp->setGeoLon(13.405);
			$cp->setGeoRadius(100);
		}
		return $cp;
	}

	private function base(bool $geoCp): array
	{
		$cp = $this->checkpoint($geoCp);
		$token = new CheckpointToken();
		$token->setKind('qr');
		$this->events->method('findByClientEventId')->willReturn(null);
		$this->tokens->method('resolve')->willReturn([$cp, $token]);
		$this->rounds->method('attach')->willReturn([null, null]);
		return [
			'token' => 'tok',
			'client_event_id' => 'ce_geo_1',
			'method' => 'auto',
			'method_claim' => 'scan_qr',
			'actor_uid' => 'worker1',
		];
	}

	public function testGeoStoredWhenCheckpointOptsIn(): void
	{
		$in = $this->base(true);
		$this->events->expects($this->once())->method('insert')
			->willReturnCallback(static fn (CheckEvent $e) => $e);
		$r = $this->svc->ingest($in + ['geo' => ['lat' => 52.5, 'lon' => 13.4, 'accuracy_m' => 12]]);
		self::assertSame(52.5, $r['event']->getGeoLat());
		self::assertSame(13.4, $r['event']->getGeoLon());
		self::assertSame(12, $r['event']->getGeoAcc());
	}

	public function testGeoRejectedWhenCheckpointNotConfigured(): void
	{
		$in = $this->base(false);
		try {
			$this->svc->ingest($in + ['geo' => ['lat' => 52.5, 'lon' => 13.4]]);
			self::fail('expected rejection');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('geo', $e->fields);
		}
	}

	public function testGeoOutOfRangeRejected(): void
	{
		$in = $this->base(true);
		$this->expectException(ValidationException::class);
		$this->svc->ingest($in + ['geo' => ['lat' => 91.0, 'lon' => 0.0]]);
	}

	public function testNoGeoStillCleanWhenConfigured(): void
	{
		// Permission denied on the device must never block the confirm —
		// geo is a stamp, not a gate.
		$in = $this->base(true);
		$this->events->expects($this->once())->method('insert')
			->willReturnCallback(static fn (CheckEvent $e) => $e);
		$r = $this->svc->ingest($in);
		self::assertNull($r['event']->getGeoLat());
	}

	public function testGeoIsBoundIntoPayloadHash(): void
	{
		$in = $this->base(true);
		$captured = null;
		$this->events->method('insert')
			->willReturnCallback(static function (CheckEvent $e) use (&$captured) { $captured = $e; return $e; });
		$this->svc->ingest($in + ['geo' => ['lat' => 52.5, 'lon' => 13.4]]);
		$withGeo = ChainService::payloadHash(EventService::eventPayloadFields($captured));
		$captured->setGeoLat(52.6);
		$without = ChainService::payloadHash(EventService::eventPayloadFields($captured));
		self::assertNotSame($withGeo, $without, 'mutating geo must change the payload hash');
	}

	public function testReplayWithoutStoredGeoFlagsMismatch(): void
	{
		$stored = new CheckEvent();
		$stored->setId(42);
		$stored->setSiteId(7);
		$stored->setCheckpointId(55);
		$stored->setClientEventId('ce_geo_1');
		$stored->setActorUid('worker1');
		$stored->setMethod('scan_qr');
		$stored->setServerReceivedAt(new \DateTime('2026-10-21T12:00:00Z'));
		$stored->setGeoLat(52.5);
		$stored->setGeoLon(13.4);
		$stored->setCreatedAt(new \DateTime());
		$events2 = $this->createMock(CheckEventMapper::class);
		$events2->method('findByClientEventId')->willReturn($stored);
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanViewSite')->willReturn('worker1');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getLateSyncDays')->willReturn(7);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());
		$chain = $this->createMock(ChainService::class);
		$svc = new EventService($events2, $this->tokens, $this->rounds, $chain, $settings, $acl, $time,
			$this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));

		// Same event id, replayed WITHOUT the stored geo → divergence signal.
		$chain->expects($this->once())->method('append');
		$r = $svc->ingest([
			'token' => 'tok', 'client_event_id' => 'ce_geo_1',
			'method' => 'auto', 'method_claim' => 'scan_qr', 'actor_uid' => 'worker1',
		]);
		self::assertTrue($r['mismatch']);

		// Identical replay including geo → clean.
		$chain2 = $this->createMock(ChainService::class);
		$svc2 = new EventService($events2, $this->tokens, $this->rounds, $chain2, $settings, $acl, $time,
			$this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));
		$chain2->expects($this->never())->method('append');
		$r2 = $svc2->ingest([
			'token' => 'tok', 'client_event_id' => 'ce_geo_1',
			'method' => 'auto', 'method_claim' => 'scan_qr', 'actor_uid' => 'worker1',
			'geo' => ['lat' => 52.5, 'lon' => 13.4],
		]);
		self::assertFalse($r2['mismatch']);
	}

	public function testForeignSitePhotoRejected(): void
	{
		$in = $this->base(true);
		$this->photos->method('exists')->with(7, 999)->willReturn(false);
		try {
			$this->svc->ingest($in + ['photo_fileid' => 999]);
			self::fail('expected rejection');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('photo_fileid', $e->fields);
		}
	}

	public function testOwnedPhotoAccepted(): void
	{
		$in = $this->base(true);
		$this->photos->method('exists')->with(7, 555)->willReturn(true);
		$this->events->expects($this->once())->method('insert')
			->willReturnCallback(static fn (CheckEvent $e) => $e);
		$r = $this->svc->ingest($in + ['photo_fileid' => 555]);
		self::assertSame(555, (int)$r['event']->getPhotoFileid());
	}
}
