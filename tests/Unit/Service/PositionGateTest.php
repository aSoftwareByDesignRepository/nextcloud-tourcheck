<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\PositionConsent;
use OCA\TourCheck\Db\PositionConsentMapper;
use OCA\TourCheck\Db\PositionEventMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\GeofenceService;
use OCA\TourCheck\Service\PositionService;
use OCA\TourCheck\Service\RateLimitService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * NH-09 privacy gates — positions are the most sensitive data in the
 * product (works-council territory). Every gate must hold: coordinate
 * bounds, round existence (uniform 404), site ACL, the site's live
 * opt-in flag, active-round-only, and the per-actor rate limit. If any
 * gate is lost, workers are tracked covertly — mutation tests prove the
 * suite sees each one.
 */
final class PositionGateTest extends TestCase
{
	private PositionEventMapper&MockObject $positions;
	private PositionConsentMapper&MockObject $consents;
	private GeofenceService&MockObject $geofences;
	private RoundService&MockObject $rounds;
	private SiteService&MockObject $sites;
	private AccessControlService&MockObject $acl;
	private RateLimitService&MockObject $rateLimit;
	private PositionService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->positions = $this->createMock(PositionEventMapper::class);
		$this->consents = $this->createMock(PositionConsentMapper::class);
		$this->geofences = $this->createMock(GeofenceService::class);
		$this->rounds = $this->createMock(RoundService::class);
		$this->sites = $this->createMock(SiteService::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->rateLimit = $this->createMock(RateLimitService::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getPositionRetentionHours')->willReturn(24);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-20T12:00:00Z'));
		$time->method('getTime')->willReturn((new \DateTime('2026-10-20T12:00:00Z'))->getTimestamp());

		// Default: a granted, unrevoked, in-window consent row — consent-gate
		// tests override this mock per case.
		$consent = new PositionConsent();
		$consent->setGrantedAt(new \DateTime('2026-10-20T11:00:00Z'));
		$this->consents->method('findFor')->willReturn($consent);

		$this->svc = new PositionService(
			$this->positions,
			$this->consents,
			$this->geofences,
			$this->rounds,
			$this->sites,
			$this->acl,
			$this->rateLimit,
			$settings,
			$time,
			$this->createMock(IDBConnection::class),
		);
	}

	private function round(int $siteId = 7, string $status = 'active'): Round
	{
		$r = new Round();
		$r->setId(42);
		$r->setSiteId($siteId);
		$r->setStatus($status);
		return $r;
	}

	private function site(int $live = 1): Site
	{
		$s = new Site();
		$s->setId(7);
		$s->setLivePosition($live);
		return $s;
	}

	private function allowHappyPath(): void
	{
		$this->rounds->method('findById')->willReturn($this->round());
		$this->sites->method('findById')->willReturn($this->site());
		$this->acl->method('siteRole')->willReturn(Constants::ROLE_WORKER);
	}

	public function testOutOfRangeLatitudeRejected(): void
	{
		$this->positions->expects($this->never())->method('insert');
		$this->expectException(ValidationException::class);
		$this->svc->ingest(42, 'w1', 90.0001, 10.0, null, null);
	}

	public function testOutOfRangeLongitudeRejected(): void
	{
		$this->positions->expects($this->never())->method('insert');
		$this->expectException(ValidationException::class);
		$this->svc->ingest(42, 'w1', 10.0, -180.0001, null, null);
	}

	public function testBoundaryCoordinatesAccepted(): void
	{
		$this->allowHappyPath();
		$this->positions->expects($this->once())->method('insert');
		$this->svc->ingest(42, 'w1', 90.0, -180.0, null, null);
	}

	public function testUnknownRoundUniformNotFound(): void
	{
		$this->rounds->method('findById')->willReturn(null);
		$this->positions->expects($this->never())->method('insert');
		$this->expectException(NotFoundException::class);
		$this->svc->ingest(999, 'w1', 10.0, 10.0, null, null);
	}

	public function testForeignSiteRejectedByAcl(): void
	{
		$this->rounds->method('findById')->willReturn($this->round(siteId: 8));
		$this->acl->expects($this->once())->method('assertCanViewSite')->with(8)
			->willThrowException(new NotFoundException());
		$this->positions->expects($this->never())->method('insert');
		$this->expectException(NotFoundException::class);
		$this->svc->ingest(42, 'w1', 10.0, 10.0, null, null);
	}

	public function testSiteWithoutOptInRejected(): void
	{
		$this->rounds->method('findById')->willReturn($this->round());
		$this->sites->method('findById')->willReturn($this->site(live: 0));
		$this->positions->expects($this->never())->method('insert');
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessageMatches('/disabled/');
		$this->svc->ingest(42, 'w1', 10.0, 10.0, null, null);
	}

	public function testMissingSiteRejected(): void
	{
		$this->rounds->method('findById')->willReturn($this->round());
		$this->sites->method('findById')->willReturn(null);
		$this->positions->expects($this->never())->method('insert');
		$this->expectException(ValidationException::class);
		$this->svc->ingest(42, 'w1', 10.0, 10.0, null, null);
	}

	public function testInactiveRoundRejected(): void
	{
		$this->rounds->method('findById')->willReturn($this->round(status: 'scheduled'));
		$this->sites->method('findById')->willReturn($this->site());
		$this->positions->expects($this->never())->method('insert');
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessageMatches('/active round/');
		$this->svc->ingest(42, 'w1', 10.0, 10.0, null, null);
	}

	public function testRateLimitAppliedPerRoundAndActor(): void
	{
		$this->allowHappyPath();
		$this->rateLimit->expects($this->once())->method('check')
			->with('pos', '42|w1', 1, 30)
			->willThrowException(new Exception('rate limited'));
		$this->positions->expects($this->never())->method('insert');
		$this->expectException(Exception::class);
		$this->svc->ingest(42, 'w1', 10.0, 10.0, null, null);
	}

	public function testHappyPathPersistsFields(): void
	{
		$this->allowHappyPath();
		$this->positions->expects($this->once())->method('insert')
			->with($this->callback(static function ($p): bool {
				return $p->getRoundId() === 42
					&& $p->getActorUid() === 'w1'
					&& abs($p->getLat() - 52.5) < 0.0001
					&& abs($p->getLon() - 13.4) < 0.0001
					&& $p->getAccuracyM() === 15
					&& $p->getExpiresAt() !== null
					&& $p->getClientTs() !== null;
			}));
		$this->svc->ingest(42, 'w1', 52.5, 13.4, 15, '2026-10-20T11:59:00+00:00');
	}

	public function testNegativeAccuracyClampedToZero(): void
	{
		$this->allowHappyPath();
		$this->positions->expects($this->once())->method('insert')
			->with($this->callback(static fn ($p): bool => $p->getAccuracyM() === 0));
		$this->svc->ingest(42, 'w1', 10.0, 10.0, -5, null);
	}

	public function testMalformedClientTimestampDroppedNotFatal(): void
	{
		$this->allowHappyPath();
		$this->positions->expects($this->once())->method('insert')
			->with($this->callback(static fn ($p): bool => $p->getClientTs() === null));
		$this->svc->ingest(42, 'w1', 10.0, 10.0, null, 'not-a-date');
	}

	public function testZuluClientTimestampAccepted(): void
	{
		// JS Date.toISOString() emits '…Z' — the companion's actual wire format.
		$this->allowHappyPath();
		$this->positions->expects($this->once())->method('insert')
			->with($this->callback(static fn ($p): bool => $p->getClientTs() !== null
				&& $p->getClientTs()->format('U') === '1760961540'));
		$this->svc->ingest(42, 'w1', 10.0, 10.0, null, '2025-10-20T11:59:00.000Z');
	}

	public function testRelativeClientTimestampRejected(): void
	{
		// 'next Thursday' parses under \DateTime but is not evidence — reject.
		$this->allowHappyPath();
		$this->positions->expects($this->once())->method('insert')
			->with($this->callback(static fn ($p): bool => $p->getClientTs() === null));
		$this->svc->ingest(42, 'w1', 10.0, 10.0, null, 'next Thursday');
	}

	public function testViewOnlyActorCannotInjectBreadcrumbs(): void
	{
		// A supervisor can VIEW the site (and the trail) but must never write
		// positions into a round they are not physically working.
		$this->rounds->method('findById')->willReturn($this->round());
		$this->sites->method('findById')->willReturn($this->site());
		$this->acl->method('siteRole')->willReturn(Constants::ROLE_SUPERVISOR);
		$this->positions->expects($this->never())->method('insert');
		$this->expectException(NotFoundException::class);
		$this->svc->ingest(42, 'sup1', 10.0, 10.0, null, null);
	}

	public function testRoundStarterWithoutWorkerRoleAccepted(): void
	{
		// The physical actor of the round (e.g. an admin running a live drill)
		// writes breadcrumbs even without a site-worker membership.
		$round = $this->round();
		$round->setStartedBy('admin1');
		$this->rounds->method('findById')->willReturn($round);
		$this->sites->method('findById')->willReturn($this->site());
		$this->acl->method('siteRole')->willReturn(Constants::ROLE_ADMIN);
		$this->positions->expects($this->once())->method('insert');
		$this->svc->ingest(42, 'admin1', 10.0, 10.0, null, null);
	}

	public function testTrailForUnknownRoundNotFound(): void
	{
		$this->rounds->method('findById')->willReturn(null);
		$this->expectException(NotFoundException::class);
		$this->svc->trailFor(999);
	}

	public function testTrailRequiresVisibility(): void
	{
		// Supervisor-only: view-level callers must not pull coworker trails.
		$this->rounds->method('findById')->willReturn($this->round());
		$this->acl->expects($this->once())->method('assertCanSuperviseSite')->with(7)
			->willThrowException(new NotFoundException());
		$this->expectException(NotFoundException::class);
		$this->svc->trailFor(42);
	}
}
