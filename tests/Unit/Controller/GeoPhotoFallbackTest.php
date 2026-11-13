<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Controller;

use OCA\TourCheck\Controller\CompanionController;
use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\TourPlanMapper;
use OCA\TourCheck\Integration\PoolVehicleService;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\AlertService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\CheckpointService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\IncidentService;
use OCA\TourCheck\Service\KeyService;
use OCA\TourCheck\Service\LicenseService;
use OCA\TourCheck\Service\LogbookService;
use OCA\TourCheck\Service\ManDownService;
use OCA\TourCheck\Service\PhotoService;
use OCA\TourCheck\Service\PositionService;
use OCA\TourCheck\Service\RateLimitService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\StationService;
use OCA\TourCheck\Service\TaskService;
use OCA\TourCheck\Service\TokenService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * AC-2 geo photo fallback (competitive-parity spec): with no GPS fix the
 * worker confirms a geo checkpoint by photo — ingested as METHOD_ATTEST
 * with the canonical reason so planners grade it weak evidence, never a
 * scan. Fail closed: the claim without a bound photo is the same
 * geo:required reject as a GPS confirm without a fix.
 */
final class GeoPhotoFallbackTest extends TestCase
{
	private EventService&MockObject $events;
	private CheckpointService&MockObject $checkpoints;
	private IRequest&MockObject $request;
	private CompanionController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->events = $this->createMock(EventService::class);
		$this->checkpoints = $this->createMock(CheckpointService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getRemoteAddress')->willReturn('10.9.9.9');
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('requireUid')->willReturn('worker1');
		$acl->method('assertCanViewSite')->willReturn('worker1');
		// PoolVehicleService is final — a real instance on a mocked
		// IAppManager (MC not enabled → null badge, never an error).
		$appManager = $this->createMock(\OCP\App\IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(false);
		$this->controller = new CompanionController(
			'tourcheck',
			$this->request,
			$acl,
			$this->events,
			$this->createMock(RoundService::class),
			$this->createMock(IncidentService::class),
			$this->createMock(PositionService::class),
			$this->createMock(PhotoService::class),
			$this->createMock(SiteService::class),
			$this->createMock(StationService::class),
			$this->createMock(TokenService::class),
			$this->createMock(LicenseService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(TourPlanMapper::class),
			new PoolVehicleService($appManager, $this->createMock(LoggerInterface::class)),
			$this->createMock(ChainService::class),
			$this->createMock(KeyService::class),
			$this->createMock(ManDownService::class),
			$this->createMock(AlertService::class),
			$this->createMock(TaskService::class),
			$this->createMock(LogbookService::class),
			$this->checkpoints,
		);
	}

	private function cp(string $kind): Checkpoint
	{
		$cp = new Checkpoint();
		$cp->setId(41);
		$cp->setSiteId(7);
		$cp->setActive(1);
		$cp->setCpKind($kind);
		if ($kind === 'geo') {
			$cp->setGeoLat(52.52);
			$cp->setGeoLon(13.405);
			$cp->setGeoRadius(100);
		}
		if ($kind === 'beacon') {
			$cp->setBeaconUuid('F7826DA6-4FA2-4E98-8024-BC5B71E0893E');
		}
		return $cp;
	}

	private function body(array $params): void
	{
		$this->request->method('getParams')->willReturn($params);
		$this->checkpoints->method('getAny')->willReturn($this->cp('geo'));
	}

	private function ingestOk(bool $replayed = false): CheckEvent
	{
		$event = new CheckEvent();
		$event->setId(900);
		return $event;
	}

	public function testGeoFallbackWithPhotoAttests(): void
	{
		$this->body([
			'checkpoint_id' => 41, 'client_event_id' => 'ce_fb_1',
			'geo_photo_fallback' => true, 'photo_fileid' => 555,
		]);
		$captured = null;
		$this->events->expects($this->once())->method('ingest')
			->willReturnCallback(function (array $in) use (&$captured) {
				$captured = $in;
				return ['event' => $this->ingestOk(), 'replayed' => false, 'mismatch' => false, 'attached' => true, 'progress' => null];
			});
		$res = $this->controller->confirmTagless();
		self::assertSame(Http::STATUS_CREATED, $res->getStatus());
		self::assertSame('attested', $captured['method']);
		self::assertSame('geo_nofix_photo_fallback', $captured['attestation_reason']);
		self::assertSame(555, $captured['photo_fileid']);
		self::assertNull($captured['geo']);
	}

	public function testGeoFallbackWithoutPhotoFailsClosed(): void
	{
		$this->body([
			'checkpoint_id' => 41, 'client_event_id' => 'ce_fb_2',
			'geo_photo_fallback' => true,
		]);
		$this->events->expects($this->never())->method('ingest');
		$res = $this->controller->confirmTagless();
		$data = $res->getData();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $res->getStatus());
		self::assertFalse($data['success']);
		self::assertSame('required', $data['fields']['geo'] ?? null);
	}

	public function testGeoFallbackWithZeroPhotoFailsClosed(): void
	{
		$this->body([
			'checkpoint_id' => 41, 'client_event_id' => 'ce_fb_3',
			'geo_photo_fallback' => true, 'photo_fileid' => 0,
		]);
		$this->events->expects($this->never())->method('ingest');
		$res = $this->controller->confirmTagless();
		$data = $res->getData();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $res->getStatus());
		self::assertSame('required', $data['fields']['geo'] ?? null);
	}

	public function testFallbackClaimOnBeaconRejected(): void
	{
		$this->request->method('getParams')->willReturn([
			'checkpoint_id' => 41, 'client_event_id' => 'ce_fb_4',
			'geo_photo_fallback' => true, 'photo_fileid' => 555,
		]);
		$this->checkpoints->method('getAny')->willReturn($this->cp('beacon'));
		$this->events->expects($this->never())->method('ingest');
		$res = $this->controller->confirmTagless();
		$data = $res->getData();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $res->getStatus());
		self::assertSame('invalid', $data['fields']['geo_photo_fallback'] ?? null);
	}

	public function testFallbackStringFalseIsNotTruthy(): void
	{
		// Form-encoded "false" must never arm the attest path — a GPS
		// confirm with no fix stays the plain geo:required reject.
		$this->body([
			'checkpoint_id' => 41, 'client_event_id' => 'ce_fb_5',
			'geo_photo_fallback' => 'false', 'photo_fileid' => 555,
		]);
		$captured = null;
		$this->events->method('ingest')->willReturnCallback(function (array $in) use (&$captured) {
			$captured = $in;
			return ['event' => $this->ingestOk(), 'replayed' => false, 'mismatch' => false, 'attached' => true, 'progress' => null];
		});
		$res = $this->controller->confirmTagless();
		self::assertSame(Http::STATUS_CREATED, $res->getStatus());
		self::assertSame('gps', $captured['method']);
		self::assertNull($captured['attestation_reason']);
	}

	public function testGpsPathUnchangedWithoutFlag(): void
	{
		$this->body([
			'checkpoint_id' => 41, 'client_event_id' => 'ce_fb_6',
			'geo' => ['lat' => 52.5, 'lon' => 13.4, 'accuracy_m' => 12],
		]);
		$captured = null;
		$this->events->method('ingest')->willReturnCallback(function (array $in) use (&$captured) {
			$captured = $in;
			return ['event' => $this->ingestOk(), 'replayed' => false, 'mismatch' => false, 'attached' => true, 'progress' => null];
		});
		$res = $this->controller->confirmTagless();
		self::assertSame(Http::STATUS_CREATED, $res->getStatus());
		self::assertSame('gps', $captured['method']);
		self::assertSame(['lat' => 52.5, 'lon' => 13.4, 'accuracy_m' => 12], $captured['geo']);
	}

	public function testBeaconPathUnchanged(): void
	{
		$this->request->method('getParams')->willReturn([
			'checkpoint_id' => 41, 'client_event_id' => 'ce_fb_7',
			'beacon_uuid' => 'F7826DA6-4FA2-4E98-8024-BC5B71E0893E',
		]);
		$this->checkpoints->method('getAny')->willReturn($this->cp('beacon'));
		$captured = null;
		$this->events->method('ingest')->willReturnCallback(function (array $in) use (&$captured) {
			$captured = $in;
			return ['event' => $this->ingestOk(), 'replayed' => false, 'mismatch' => false, 'attached' => true, 'progress' => null];
		});
		$res = $this->controller->confirmTagless();
		self::assertSame(Http::STATUS_CREATED, $res->getStatus());
		self::assertSame('ble', $captured['method']);
	}

	public function testReplayedFallbackReturns200(): void
	{
		$this->body([
			'checkpoint_id' => 41, 'client_event_id' => 'ce_fb_8',
			'geo_photo_fallback' => true, 'photo_fileid' => 555,
		]);
		$this->events->method('ingest')
			->willReturn(['event' => $this->ingestOk(true), 'replayed' => true, 'mismatch' => false, 'attached' => true, 'progress' => null]);
		$res = $this->controller->confirmTagless();
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertTrue($res->getData()['data']['replayed']);
	}
}
