<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Controller;

use OCA\TourCheck\Controller\ApiController;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Db\ReportDeliveryMapper;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Integration\DutyShiftLinkService;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\AnchorService;
use OCA\TourCheck\Service\AttestationService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\CheckpointService;
use OCA\TourCheck\Service\ClaimBundleService;
use OCA\TourCheck\Service\DashboardService;
use OCA\TourCheck\Service\DriveSegmentService;
use OCA\TourCheck\Service\ErasureService;
use OCA\TourCheck\Service\IncidentService;
use OCA\TourCheck\Service\KeyService;
use OCA\TourCheck\Service\LicenseService;
use OCA\TourCheck\Service\LivePageService;
use OCA\TourCheck\Service\MaintenanceHandoffService;
use OCA\TourCheck\Service\PlanService;
use OCA\TourCheck\Service\QrSheetService;
use OCA\TourCheck\Service\RateLimitService;
use OCA\TourCheck\Service\ReportService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteMemberService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\StationService;
use OCA\TourCheck\Service\TemplateService;
use OCA\TourCheck\Service\TokenService;
use OCP\AppFramework\Http;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

final class WidgetSnapshotTest extends TestCase
{
	private AccessControlService&MockObject $acl;
	private DashboardService&MockObject $dashboard;
	private ICacheFactory&MockObject $cacheFactory;
	private ICache&MockObject $cache;
	private ApiController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->acl = $this->createMock(AccessControlService::class);
		$this->dashboard = $this->createMock(DashboardService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);
		$this->cacheFactory->method('createDistributed')
			->with('tourcheck_wsnap')
			->willReturn($this->cache);

		$this->controller = new ApiController(
			'tourcheck',
			$this->createMock(IRequest::class),
			$this->acl,
			$this->createMock(SiteService::class),
			$this->createMock(CheckpointService::class),
			$this->createMock(PlanService::class),
			$this->createMock(SiteMemberService::class),
			$this->createMock(RoundService::class),
			$this->createMock(IncidentService::class),
			$this->createMock(AttestationService::class),
			$this->createMock(StationService::class),
			$this->createMock(ReportService::class),
			$this->createMock(ClaimBundleService::class),
			$this->createMock(LivePageService::class),
			$this->createMock(AnchorService::class),
			$this->createMock(ChainService::class),
			$this->createMock(JournalEntryMapper::class),
			$this->dashboard,
			$this->createMock(SettingsService::class),
			$this->createMock(LicenseService::class),
			$this->createMock(TemplateService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ReportDeliveryMapper::class),
			$this->createMock(DriveSegmentService::class),
			$this->createMock(MaintenanceHandoffService::class),
			$this->createMock(KeyService::class),
			$this->createMock(ErasureService::class),
			$this->createMock(TokenService::class),
			new QrSheetService(),
			$this->createMock(IURLGenerator::class),
			$this->createMock(RateLimitService::class),
			new DutyShiftLinkService(
				$this->createMock(\OCP\App\IAppManager::class),
				$this->createMock(\Psr\Log\LoggerInterface::class),
			),
			$this->cacheFactory,
		);
	}

	public function testDeniedDoorNeverReadsCache(): void
	{
		// A revoked/expelled caller must not be served cached aggregates —
		// the access door runs before the cache read (privacycheck lesson).
		$this->acl->method('assertAppAccess')->willThrowException(new NotFoundException());
		$this->cache->expects($this->never())->method('get');
		$this->cache->expects($this->never())->method('set');
		$this->dashboard->expects($this->never())->method('home');

		$res = $this->controller->widgetSnapshot();
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$data = $res->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('NOT_FOUND', $data['error']);
	}

	public function testCacheHitSkipsHome(): void
	{
		$cached = ['status' => ['rounds' => ['active' => 2]], 'serverNow' => '2026-11-07T20:00:00+00:00'];
		$this->acl->method('assertAppAccess')->willReturn('alice');
		$this->cache->method('get')->with('alice:dashboard')->willReturn($cached);
		$this->dashboard->expects($this->never())->method('home');
		$this->cache->expects($this->never())->method('set');

		$res = $this->controller->widgetSnapshot();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['success' => true, 'data' => $cached], $res->getData());
	}

	public function testCacheMissBuildsCachesAndWraps(): void
	{
		$home = ['sites' => [], 'rounds' => ['active' => 1], 'can_plan' => false];
		$this->acl->method('assertAppAccess')->willReturn('alice');
		$this->cache->method('get')->with('alice:dashboard')->willReturn(null);
		$this->dashboard->method('home')->willReturn($home);
		$this->cache->expects($this->once())
			->method('set')
			->with('alice:dashboard', $this->callback(
				static fn ($p) => is_array($p) && $p['status'] === $home && isset($p['serverNow'])
			), 60);

		$res = $this->controller->widgetSnapshot();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$data = $res->getData();
		$this->assertTrue($data['success']);
		$this->assertSame($home, $data['data']['status']);
		$this->assertNotEmpty($data['data']['serverNow']);
	}

	public function testHomeFailureNeverCached(): void
	{
		$this->acl->method('assertAppAccess')->willReturn('alice');
		$this->cache->method('get')->willReturn(null);
		$this->dashboard->method('home')->willThrowException(
			new DomainException('VAL', 'broken', Http::STATUS_BAD_REQUEST)
		);
		$this->cache->expects($this->never())->method('set');

		$res = $this->controller->widgetSnapshot();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertFalse($res->getData()['success']);
	}
}
