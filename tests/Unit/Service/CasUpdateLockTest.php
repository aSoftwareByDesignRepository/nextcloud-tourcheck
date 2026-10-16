<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\Corrective;
use OCA\TourCheck\Db\CorrectiveMapper;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Db\SiteMemberMapper;
use OCA\TourCheck\Db\StationMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Exception\ConflictException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\CheckpointService;
use OCA\TourCheck\Service\IncidentService;
use OCA\TourCheck\Service\NotificationService;
use OCA\TourCheck\Service\PhotoService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * CAS lost-update guard: every revision-checked update must hold the row
 * lock (findForUpdate) inside a transaction BEFORE assertRevision runs.
 * Without it two writers read revision N, both pass CAS, and the second
 * silently clobbers the first — same class as the carecheck plan/client
 * races fixed 2026-11-14. These tests pin the call ORDER, not just the
 * outcome: begin → findForUpdate → update → commit, and stale CAS →
 * rollback without any write.
 */
final class CasUpdateLockTest extends TestCase
{
	private IDBConnection&MockObject $db;
	private AccessControlService&MockObject $acl;
	private SiteService&MockObject $sites;
	private ChainService&MockObject $chain;
	private ITimeFactory&MockObject $time;
	private array $order = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->order = [];
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('beginTransaction')->willReturnCallback(function () { $this->order[] = 'begin'; });
		$this->db->method('commit')->willReturnCallback(function () { $this->order[] = 'commit'; });
		$this->db->method('rollBack')->willReturnCallback(function () { $this->order[] = 'rollback'; });
		$this->acl = $this->createMock(AccessControlService::class);
		$this->sites = $this->createMock(SiteService::class);
		$this->chain = $this->createMock(ChainService::class);
		$this->chain->method('append')->willReturn(new \OCA\TourCheck\Db\JournalEntry());
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getDateTime')->willReturn(new \DateTime('2026-11-05T12:00:00Z'));
	}

	private function siteService(SiteMapper&MockObject $mapper, SiteMemberMapper&MockObject $members): SiteService
	{
		return new SiteService(
			$mapper,
			$members,
			$this->createMock(StationMapper::class),
			$this->acl,
			$this->chain,
			$this->time,
			$this->db,
			$this->createMock(ILockingProvider::class),
		);
	}

	public function testSiteUpdateLocksRowBeforeCas(): void
	{
		$mapper = $this->createMock(SiteMapper::class);
		$site = new Site();
		$site->setId(5);
		$site->setActive(1);
		$site->setRevision(3);
		$mapper->method('findForUpdate')->willReturnCallback(function () use ($site) {
			$this->order[] = 'findForUpdate';
			return $site;
		});
		$mapper->method('update')->willReturnCallback(function ($s) {
			$this->order[] = 'update';
			return $s;
		});
		$members = $this->createMock(SiteMemberMapper::class);
		$mapper->method('find')->willReturn($site);
		$this->acl->method('assertCanViewSite')->willReturn('boss');
		$this->acl->method('assertCanManageSite')->willReturn('boss');
		$svc = $this->siteService($mapper, $members);

		$svc->update(5, ['address' => 'Bahnhofstr. 1'], 3);

		self::assertSame(['begin', 'findForUpdate', 'update', 'commit'], $this->order);
	}

	public function testSiteUpdateStaleRevisionRollsBackWithoutWrite(): void
	{
		$mapper = $this->createMock(SiteMapper::class);
		$site = new Site();
		$site->setId(5);
		$site->setActive(1);
		$site->setRevision(9);
		$mapper->method('find')->willReturn($site);
		$mapper->method('findForUpdate')->willReturn($site);
		$mapper->expects($this->never())->method('update');
		$this->acl->method('assertCanViewSite')->willReturn('boss');
		$this->acl->method('assertCanManageSite')->willReturn('boss');
		$svc = $this->siteService($mapper, $this->createMock(SiteMemberMapper::class));

		try {
			$svc->update(5, ['address' => 'X'], 3);
			self::fail('stale revision must conflict');
		} catch (ConflictException) {
		}
		self::assertSame(['begin', 'rollback'], $this->order);
	}

	public function testSiteArchiveLocksRowBeforeWrite(): void
	{
		$mapper = $this->createMock(SiteMapper::class);
		$site = new Site();
		$site->setId(5);
		$site->setActive(1);
		$site->setRevision(3);
		$mapper->method('find')->willReturn($site);
		$mapper->method('findForUpdate')->willReturnCallback(function () use ($site) {
			$this->order[] = 'findForUpdate';
			return $site;
		});
		$mapper->method('update')->willReturnCallback(function ($s) {
			$this->order[] = 'update';
			return $s;
		});
		$this->acl->method('assertCanViewSite')->willReturn('boss');
		$this->acl->method('assertCanManageSite')->willReturn('boss');
		$svc = $this->siteService($mapper, $this->createMock(SiteMemberMapper::class));

		$svc->archive(5);

		self::assertSame(['begin', 'findForUpdate', 'update', 'commit'], $this->order);
	}

	/**
	 * License seat leak: a station on an archived site can never be revoked
	 * (assertCanManageSite 404s on inactive sites), so archive() must retire
	 * the site's active stations in the same transaction — or every archived
	 * site permanently burns device seats (redteam e2e hit cap=2 on 2026-10-15).
	 */
	public function testSiteArchiveRevokesActiveStations(): void
	{
		$mapper = $this->createMock(SiteMapper::class);
		$site = new Site();
		$site->setId(5);
		$site->setActive(1);
		$site->setRevision(3);
		$mapper->method('find')->willReturn($site);
		$mapper->method('findForUpdate')->willReturn($site);
		$mapper->method('update')->willReturnArgument(0);
		$this->acl->method('assertCanViewSite')->willReturn('boss');
		$this->acl->method('assertCanManageSite')->willReturn('boss');

		$active = new \OCA\TourCheck\Db\Station();
		$active->setId(11);
		$active->setSiteId(5);
		$active->setActive(1);
		$inactive = new \OCA\TourCheck\Db\Station();
		$inactive->setId(12);
		$inactive->setSiteId(5);
		$inactive->setActive(0);
		$stationMapper = $this->createMock(StationMapper::class);
		$stationMapper->method('findForSite')->willReturn([$active, $inactive]);
		$stationMapper->expects($this->once())->method('update')
			->with(self::callback(fn ($s) => $s === $active && (int)$s->getActive() === 0 && $s->getRevokedAt() !== null));

		$journaled = [];
		$chain = $this->createMock(ChainService::class);
		$chain->method('append')->willReturnCallback(function ($siteId, $entry) use (&$journaled) {
			$journaled[] = $entry['body'];
			return new \OCA\TourCheck\Db\JournalEntry();
		});

		$svc = new SiteService(
			$mapper,
			$this->createMock(SiteMemberMapper::class),
			$stationMapper,
			$this->acl,
			$chain,
			$this->time,
			$this->db,
			$this->createMock(ILockingProvider::class),
		);
		$svc->archive(5);

		self::assertSame(['site_archived', 'station_revoked'], $journaled);
	}

	public function testCheckpointUpdateLocksRowBeforeCas(): void
	{
		$cpMapper = $this->createMock(CheckpointMapper::class);
		$cp = new Checkpoint();
		$cp->setId(9);
		$cp->setSiteId(5);
		$cp->setActive(1);
		$cp->setRevision(4);
		$cpMapper->method('find')->willReturn($cp);
		$cpMapper->method('findForUpdate')->willReturnCallback(function () use ($cp) {
			$this->order[] = 'findForUpdate';
			return $cp;
		});
		$cpMapper->method('update')->willReturnCallback(function ($c) {
			$this->order[] = 'update';
			return $c;
		});
		$site = new Site();
		$site->setId(5);
		$site->setActive(1);
		$this->sites->method('getVisible')->willReturn($site);
		$this->sites->method('assertRevision');
		$this->acl->method('assertCanManageSite')->willReturn('boss');

		$svc = new CheckpointService(
			$cpMapper,
			$this->createMock(\OCA\TourCheck\Service\TokenService::class),
			$this->acl,
			$this->sites,
			$this->chain,
			$this->time,
			$this->db,
		);
		$svc->update(5, 9, ['instructions' => 'Side door'], 4);

		self::assertSame(['begin', 'findForUpdate', 'update', 'commit'], $this->order);
	}

	public function testIncidentUpdateLocksRowBeforeCas(): void
	{
		$incMapper = $this->createMock(IncidentMapper::class);
		$inc = new Incident();
		$inc->setId(50);
		$inc->setSiteId(7);
		$inc->setStatus('open');
		$inc->setRevision(2);
		$incMapper->method('find')->willReturn($inc);
		$incMapper->method('findForUpdate')->willReturnCallback(function () use ($inc) {
			$this->order[] = 'findForUpdate';
			return $inc;
		});
		$incMapper->method('update')->willReturnCallback(function ($i) {
			$this->order[] = 'update';
			return $i;
		});
		$this->acl->method('assertCanViewSite')->willReturn('sup');
		$this->acl->method('assertCanSuperviseSite')->willReturn('sup');
		$this->sites->method('assertRevision');

		$svc = $this->incidentService($incMapper, $this->createMock(CorrectiveMapper::class));
		$svc->update(50, ['note' => 'follow-up'], 2);

		self::assertSame(['begin', 'findForUpdate', 'update', 'commit'], $this->order);
	}

	public function testCorrectiveUpdateLocksIncidentBeforeCorrective(): void
	{
		$incMapper = $this->createMock(IncidentMapper::class);
		$corMapper = $this->createMock(CorrectiveMapper::class);
		$inc = new Incident();
		$inc->setId(50);
		$inc->setSiteId(7);
		$inc->setStatus('action');
		$inc->setRevision(2);
		$c = new Corrective();
		$c->setId(9);
		$c->setIncidentId(50);
		$c->setStatus('open');
		$c->setRevision(1);
		$incMapper->method('find')->willReturn($inc);
		$corMapper->method('find')->willReturn($c);
		$incMapper->method('findForUpdate')->willReturnCallback(function () use ($inc) {
			$this->order[] = 'lock_incident';
			return $inc;
		});
		$corMapper->method('findForUpdate')->willReturnCallback(function () use ($c) {
			$this->order[] = 'lock_corrective';
			return $c;
		});
		$corMapper->method('update')->willReturnCallback(function ($x) {
			$this->order[] = 'update';
			return $x;
		});
		$this->acl->method('assertCanViewSite')->willReturn('sup');
		$this->acl->method('assertAppAccess')->willReturn('sup');
		$this->acl->method('siteRole')->willReturn('supervisor');
		$this->sites->method('assertRevision');

		$svc = $this->incidentService($incMapper, $corMapper);
		$svc->updateCorrective(9, ['resolution' => 'done'], 1);

		// Parent-before-child lock order — incident row locked first.
		self::assertSame(['begin', 'lock_incident', 'lock_corrective', 'update', 'commit'], $this->order);
	}

	private function incidentService(IncidentMapper $inc, CorrectiveMapper $cor): IncidentService
	{
		return new IncidentService(
			$inc,
			$cor,
			$this->createMock(RoundMapper::class),
			$this->createMock(CheckpointMapper::class),
			$this->acl,
			$this->sites,
			$this->chain,
			$this->createMock(PhotoService::class),
			$this->time,
			$this->db,
			$this->createMock(NotificationService::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
