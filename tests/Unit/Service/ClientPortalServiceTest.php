<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\ClientGrant;
use OCA\TourCheck\Db\ClientGrantMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Db\ReportDeliveryMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ClientPortalService;
use OCA\TourCheck\Service\ReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Client portal scope (F6): the door and every site read collapse to the
 * same NotFoundException for non-clients, non-granted sites, and missing
 * sites — no existence oracle in either direction. Grant/revoke are
 * idempotent; granting a non-client uid is a validation error, not a row.
 */
final class ClientPortalServiceTest extends TestCase
{
	private ClientGrantMapper&MockObject $grants;
	private SiteMapper&MockObject $sites;
	private IUserSession&MockObject $session;
	private IGroupManager&MockObject $groups;
	private AccessControlService&MockObject $acl;
	private ReportDeliveryMapper&MockObject $deliveries;
	private ClientPortalService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->grants = $this->createMock(ClientGrantMapper::class);
		$this->sites = $this->createMock(SiteMapper::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->deliveries = $this->createMock(ReportDeliveryMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-20T12:00:00Z'));
		$this->svc = new ClientPortalService(
			$this->grants, $this->sites, $this->session, $this->groups,
			$this->acl, $time, $this->createMock(IDBConnection::class),
			$this->deliveries,
			$this->createMock(ReportService::class),
		);
	}

	private function login(?string $uid, bool $inClientGroup): void
	{
		if ($uid === null) {
			$this->session->method('getUser')->willReturn(null);
			return;
		}
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($u);
		$this->groups->method('isInGroup')->with($uid, 'tourcheck_client')->willReturn($inClientGroup);
	}

	public function testAssertClientRejectsAnonymous(): void
	{
		$this->login(null, false);
		$this->expectException(NotFoundException::class);
		$this->svc->assertClient();
	}

	public function testAssertClientRejectsNonClientUser(): void
	{
		$this->login('staff1', false);
		$this->expectException(NotFoundException::class);
		$this->svc->assertClient();
	}

	public function testAssertClientPassesForClient(): void
	{
		$this->login('client1', true);
		$this->assertSame('client1', $this->svc->assertClient());
	}

	public function testSiteScopeIsUniform404(): void
	{
		// ungranted site id looks identical to a nonexistent one
		$this->grants->method('hasAccess')->with('client1', 99)->willReturn(false);
		$this->expectException(NotFoundException::class);
		$this->svc->assertSiteScope('client1', 99);
	}

	public function testOverviewOnInactiveSiteIs404(): void
	{
		$this->grants->method('hasAccess')->willReturn(true);
		$site = new Site();
		$site->setId(3);
		$site->setActive(0);
		$this->sites->method('find')->willReturn($site);
		$this->expectException(NotFoundException::class);
		$this->svc->overview('client1', 3);
	}

	public function testGrantRejectsNonClientUid(): void
	{
		$this->groups->method('isInGroup')->with('staff1', 'tourcheck_client')->willReturn(false);
		$this->grants->expects($this->never())->method('insert');
		$this->expectException(ValidationException::class);
		$this->svc->grant('staff1', 3, 'admin1');
	}

	public function testGrantIsIdempotentOnLiveGrant(): void
	{
		$this->groups->method('isInGroup')->willReturn(true);
		$g = new ClientGrant();
		$g->setClientUid('client1');
		$g->setSiteId(3);
		$this->grants->method('find')->willReturn($g); // unrevoked
		$this->grants->expects($this->never())->method('insert');
		$this->grants->expects($this->never())->method('update');
		$this->svc->grant('client1', 3, 'admin1');
	}

	public function testRevokeIsIdempotentOnMissing(): void
	{
		$this->grants->method('find')->willReturn(null);
		$this->grants->expects($this->never())->method('update');
		$this->svc->revoke('client1', 3, 'admin1');
	}

	private function delivery(int $id, int $siteId, string $audience, bool $visible): \OCA\TourCheck\Db\ReportDelivery
	{
		$d = new \OCA\TourCheck\Db\ReportDelivery();
		$d->setId($id);
		$d->setSiteId($siteId);
		$d->setAudience($audience);
		$d->setClientVisible($visible);
		return $d;
	}

	public function testReportsFiltersNonClientAudience(): void
	{
		$this->grants->method('hasAccess')->willReturn(true);
		$this->deliveries->method('findClientVisible')->willReturn([
			$this->delivery(1, 3, 'client', true),
			// belt: a flag set on an internal PDF must never surface
			$this->delivery(2, 3, 'internal', true),
		]);
		$rows = $this->svc->reports('client1', 3);
		$this->assertCount(1, $rows);
		$this->assertSame(1, $rows[0]['id']);
	}

	public function testReportForDownloadRejectsInternalAudience(): void
	{
		$this->deliveries->method('find')->willReturn($this->delivery(5, 3, 'internal', true));
		$this->expectException(NotFoundException::class);
		$this->svc->reportForDownload('client1', 5);
	}

	public function testReportForDownloadRejectsUnshared(): void
	{
		$this->deliveries->method('find')->willReturn($this->delivery(5, 3, 'client', false));
		$this->expectException(NotFoundException::class);
		$this->svc->reportForDownload('client1', 5);
	}

	public function testReportForDownloadRejectsForeignSite(): void
	{
		// delivery is portal-visible but belongs to a site the client has no grant for
		$this->deliveries->method('find')->willReturn($this->delivery(5, 7, 'client', true));
		$this->grants->method('hasAccess')->with('client1', 7)->willReturn(false);
		$this->expectException(NotFoundException::class);
		$this->svc->reportForDownload('client1', 5);
	}
}
