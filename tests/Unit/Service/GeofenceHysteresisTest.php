<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\Geofence;
use OCA\TourCheck\Db\GeofenceMapper;
use OCA\TourCheck\Db\GeofenceState;
use OCA\TourCheck\Db\GeofenceStateMapper;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\AlertService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\GeofenceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Geofence hysteresis (F1): a single inside fix does NOT fire 'enter' —
 * the worker must dwell ≥ ENTER_DWELL_S; exit needs EXIT_DWELL_S or a
 * >EXIT_CLEAR_M overshoot. Fixes worse than MAX_EVAL_ACCURACY_M must never
 * move state (a degraded fix cannot flap an episode).
 */
final class GeofenceHysteresisTest extends TestCase
{
	private GeofenceMapper&MockObject $geofences;
	private GeofenceStateMapper&MockObject $states;
	private AccessControlService&MockObject $acl;
	private AlertService&MockObject $alerts;
	private EventService&MockObject $events;
	private IDBConnection&MockObject $db;
	private GeofenceService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->geofences = $this->createMock(GeofenceMapper::class);
		$this->states = $this->createMock(GeofenceStateMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->alerts = $this->createMock(AlertService::class);
		$this->events = $this->createMock(EventService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);
		$time->method('getTime')->willReturnCallback(fn () => $this->now->getTimestamp());
		$this->svc = new GeofenceService(
			$this->geofences, $this->states, $this->acl, $this->alerts,
			$this->events, $time, $this->db,
		);
	}

	private function circleFence(int $id = 7, string $kind = 'perimeter'): Geofence
	{
		$g = new Geofence();
		$g->setId($id);
		$g->setSiteId(3);
		$g->setKind($kind);
		$g->setShape('circle');
		$g->setCenterLat(52.5);
		$g->setCenterLon(13.4);
		$g->setRadiusM(100);
		return $g;
	}

	private function stateRow(?int $inside = 0, ?\DateTime $pending = null): GeofenceState
	{
		$st = new GeofenceState();
		$st->setInside($inside);
		$st->setPendingSince($pending);
		$st->setChangedAt(clone $this->now);
		return $st;
	}

	public function testDegradedAccuracyNeverEvaluates(): void
	{
		$this->geofences->expects($this->never())->method('findForSite');
		$out = $this->svc->evaluate('u1', 3, 9, 52.5, 13.4, GeofenceService::MAX_EVAL_ACCURACY_M + 1);
		$this->assertSame([], $out);
	}

	public function testFirstInsideFixOnlyStartsPending(): void
	{
		$this->geofences->method('findForSite')->willReturn([$this->circleFence()]);
		$this->states->method('findFor')->willReturn($this->stateRow(0, null));
		$this->states->expects($this->once())->method('update')
			->with($this->callback(static fn (GeofenceState $s) => $s->getPendingSince() !== null && (int)$s->getInside() === 0));
		$this->events->expects($this->never())->method('journalSignal');
		$this->assertSame([], $this->svc->evaluate('u1', 3, 9, 52.5, 13.4, 10));
	}

	public function testEnterFiresAfterDwell(): void
	{
		$this->geofences->method('findForSite')->willReturn([$this->circleFence()]);
		$pending = (clone $this->now)->modify('-' . (GeofenceService::ENTER_DWELL_S + 1) . ' seconds');
		$this->states->method('findFor')->willReturn($this->stateRow(0, $pending));
		$this->events->expects($this->once())->method('journalSignal')
			->with(3, 'geofence_enter', $this->anything());
		$this->assertSame(['geofence_enter'], $this->svc->evaluate('u1', 3, 9, 52.5, 13.4, 10));
	}

	public function testNoEnterBeforeDwell(): void
	{
		$this->geofences->method('findForSite')->willReturn([$this->circleFence()]);
		$pending = (clone $this->now)->modify('-' . (GeofenceService::ENTER_DWELL_S - 1) . ' seconds');
		$this->states->method('findFor')->willReturn($this->stateRow(0, $pending));
		$this->events->expects($this->never())->method('journalSignal');
		$this->assertSame([], $this->svc->evaluate('u1', 3, 9, 52.5, 13.4, 10));
	}

	public function testExitNeedsDwellUnlessClearlyOut(): void
	{
		$outLat = 52.5 + 120.0 / 111_320.0; // ~120 m out — inside the 100 m clear margin
		$this->geofences->method('findForSite')->willReturn([$this->circleFence()]);
		$pending = (clone $this->now)->modify('-' . (GeofenceService::EXIT_DWELL_S - 1) . ' seconds');
		$this->states->method('findFor')->willReturn($this->stateRow(1, $pending));
		$this->assertSame([], $this->svc->evaluate('u1', 3, 9, $outLat, 13.4, 10));
	}

	public function testExitFiresImmediatelyWhenClearlyOut(): void
	{
		$farOut = 52.5 + 300.0 / 111_320.0; // ~300 m — beyond radius + clear margin
		$this->geofences->method('findForSite')->willReturn([$this->circleFence()]);
		$pending = (clone $this->now)->modify('-5 seconds');
		$this->states->method('findFor')->willReturn($this->stateRow(1, $pending));
		// coverage gap path consults state counts + round query
		$this->states->method('countInsideExcluding')->willReturn(1);
		$this->events->expects($this->once())->method('journalSignal')
			->with(3, 'geofence_exit', $this->anything());
		$this->assertSame(['geofence_exit'], $this->svc->evaluate('u1', 3, 9, $farOut, 13.4, 10));
	}

	public function testSameSideClearsPending(): void
	{
		$this->geofences->method('findForSite')->willReturn([$this->circleFence()]);
		$st = $this->stateRow(0, (clone $this->now)->modify('-10 seconds'));
		$this->states->method('findFor')->willReturn($st);
		// Still OUTSIDE the fence: pending must be reset, no event.
		$outLat = 52.5 + 120.0 / 111_320.0;
		$this->states->expects($this->once())->method('update')
			->with($this->callback(static fn (GeofenceState $s) => $s->getPendingSince() === null));
		$this->assertSame([], $this->svc->evaluate('u1', 3, 9, $outLat, 13.4, 10));
	}

	public function testRestrictedZoneEnterFiresAlert(): void
	{
		$this->geofences->method('findForSite')->willReturn([$this->circleFence(8, 'restricted')]);
		$pending = (clone $this->now)->modify('-' . (GeofenceService::ENTER_DWELL_S + 5) . ' seconds');
		$this->states->method('findFor')->willReturn($this->stateRow(0, $pending));
		$this->alerts->expects($this->once())->method('fire')
			->with('zone_violation', 3, 'u1', 'high', $this->anything(), $this->stringStartsWith('zv_'), 9);
		$this->assertSame(['zone_violation'], $this->svc->evaluate('u1', 3, 9, 52.5, 13.4, 10));
	}

	public function testShapeValidation(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->create(3, 'perimeter', 'circle', null, 13.4, 100, null); // missing center
	}

	public function testShapeValidationRejectsBadKind(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->create(3, 'nope', 'circle', 52.5, 13.4, 100, null);
	}

	public function testShapeValidationRejectsDegeneratePolygon(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->create(3, 'perimeter', 'polygon', null, null, null, [[0.0, 0.0], [1.0, 1.0]]);
	}
}
