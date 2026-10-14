<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Dashboard;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Dashboard\TourCheckWidget;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\IncidentService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * NH-04: widget items are ACL-scoped — no access → empty, member → only
 * their sites' rounds; ordering is missed → running → incidents.
 */
final class TourCheckWidgetTest extends TestCase
{
	private AccessControlService&MockObject $acl;
	private RoundService&MockObject $rounds;
	private IncidentService&MockObject $incidents;
	private TourCheckWidget $widget;

	protected function setUp(): void
	{
		parent::setUp();
		$this->acl = $this->createMock(AccessControlService::class);
		$this->rounds = $this->createMock(RoundService::class);
		$this->incidents = $this->createMock(IncidentService::class);
		$sites = $this->createMock(SiteService::class);
		$site = new Site();
		$site->setId(9);
		$site->setName('Alpha');
		$sites->method('findById')->willReturn($site);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t, $p = []) => vsprintf($t, (array)$p));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $r, array $p = []) => 'https://nc/' . $r . '/' . implode(',', $p)
		);
		$urls->method('imagePath')->willReturn('img/app.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $p) => 'https://nc/' . $p);
		$this->widget = new TourCheckWidget(
			$this->acl, $this->rounds, $sites, $this->incidents,
			$urls, $l10n, $this->createMock(ITimeFactory::class),
		);
	}

	private function round(int $id, string $status): Round
	{
		$r = new Round();
		$r->setId($id);
		$r->setSiteId(9);
		$r->setStatus($status);
		$r->setWindowEnd(new \DateTime('2040-06-15T10:00:00Z'));
		return $r;
	}

	public function testNoAppAccessGetsNoItems(): void
	{
		$this->acl->method('hasAppAccess')->willReturn(false);
		$this->rounds->expects(self::never())->method('todayFor');
		self::assertSame([], $this->widget->getItems('stranger'));
	}

	public function testMissedSortsBeforeRunningBeforeIncidents(): void
	{
		$this->acl->method('hasAppAccess')->willReturn(true);
		$this->acl->method('visibleSiteIds')->willReturn([9]);
		$this->acl->method('isAppAdmin')->willReturn(false);
		$this->acl->method('canPlan')->willReturn(false);
		$this->rounds->method('todayFor')->willReturn([
			$this->round(2, Constants::ROUND_ACTIVE),
			$this->round(1, Constants::ROUND_MISSED),
			$this->round(3, Constants::ROUND_COMPLETED), // not shown
		]);
		$inc = new Incident();
		$inc->setId(7);
		$inc->setTitle('Door open');
		$inc->setSeverity('critical');
		$this->incidents->method('listVisible')->willReturn([$inc]);

		$items = $this->widget->getItems('worker1');
		self::assertCount(3, $items);
		$s = json_decode((string)json_encode($items), true);
		self::assertSame('round-1', $s[0]['sinceId']);
		self::assertSame('round-2', $s[1]['sinceId']);
		self::assertSame('incident-7', $s[2]['sinceId']);
		self::assertStringContainsString('Alpha', $s[0]['title']);
		self::assertStringContainsString('tourcheck.page.round/1', $s[0]['link']);
	}

	public function testLowSeverityIncidentsNotShown(): void
	{
		$this->acl->method('hasAppAccess')->willReturn(true);
		$this->acl->method('visibleSiteIds')->willReturn([9]);
		$this->rounds->method('todayFor')->willReturn([]);
		$inc = new Incident();
		$inc->setId(8);
		$inc->setTitle('Scratches');
		$inc->setSeverity('low');
		$this->incidents->method('listVisible')->willReturn([$inc]);
		self::assertSame([], $this->widget->getItems('w'));
	}

	public function testMemberWithNoSitesGetsEmptyNotUnrestricted(): void
	{
		// visibleSiteIds [] + no admin/planner role must NOT mean "all sites".
		$this->acl->method('hasAppAccess')->willReturn(true);
		$this->acl->method('visibleSiteIds')->willReturn([]);
		$this->acl->method('isAppAdmin')->willReturn(false);
		$this->acl->method('canPlan')->willReturn(false);
		$this->rounds->expects(self::never())->method('todayFor');
		self::assertSame([], $this->widget->getItems('w'));
	}
}
