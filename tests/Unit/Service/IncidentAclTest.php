<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\Corrective;
use OCA\TourCheck\Db\CorrectiveMapper;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\IncidentService;
use OCA\TourCheck\Service\NotificationService;
use OCA\TourCheck\Service\PhotoService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Incident references are validated against the site — a foreign round_id,
 * checkpoint_id, or photo_fileid must not smuggle cross-site evidence into
 * the journal. Corrective mutations need assignee-or-supervisor, not merely
 * site-view rights.
 */
final class IncidentAclTest extends TestCase
{
	private IncidentMapper&MockObject $incidents;
	private CorrectiveMapper&MockObject $correctives;
	private RoundMapper&MockObject $rounds;
	private CheckpointMapper&MockObject $checkpoints;
	private AccessControlService&MockObject $acl;
	private PhotoService&MockObject $photos;
	private NotificationService&MockObject $notifier;
	private SiteService&MockObject $sites;
	private IncidentService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->incidents = $this->createMock(IncidentMapper::class);
		$this->correctives = $this->createMock(CorrectiveMapper::class);
		$this->rounds = $this->createMock(RoundMapper::class);
		$this->checkpoints = $this->createMock(CheckpointMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->photos = $this->createMock(PhotoService::class);
		$this->sites = $this->createMock(SiteService::class);
		$site = new Site();
		$site->setId(7);
		$site->setName('Alpha');
		$this->sites->method('getVisible')->willReturn($site);
		$this->sites->method('findById')->with(7)->willReturn($site);
		$this->notifier = $this->createMock(NotificationService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-20T12:00:00Z'));
		$chain = $this->createMock(ChainService::class);
		$chain->method('append')->willReturn(new \OCA\TourCheck\Db\JournalEntry());

		$this->svc = new IncidentService(
			$this->incidents,
			$this->correctives,
			$this->rounds,
			$this->checkpoints,
			$this->acl,
			$this->sites,
			$chain,
			$this->photos,
			$time,
			$this->createMock(IDBConnection::class),
			$this->notifier,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testForeignRoundIdRejected(): void
	{
		$this->acl->method('assertCanViewSite')->willReturn('worker1');
		$foreign = new Round();
		$foreign->setId(99);
		$foreign->setSiteId(8); // NOT site 7
		$this->rounds->method('find')->with(99)->willReturn($foreign);
		$this->incidents->expects($this->never())->method('insert');

		try {
			$this->svc->create(7, ['category' => 'security', 'severity' => 'high', 'round_id' => 99]);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('round_id', $e->fields);
		}
	}

	public function testForeignCheckpointIdRejected(): void
	{
		$this->acl->method('assertCanViewSite')->willReturn('worker1');
		$cp = new Checkpoint();
		$cp->setId(66);
		$cp->setSiteId(8);
		$this->checkpoints->method('find')->with(66)->willReturn($cp);
		$this->incidents->expects($this->never())->method('insert');

		try {
			$this->svc->create(7, ['category' => 'damage', 'severity' => 'low', 'checkpoint_id' => 66]);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('checkpoint_id', $e->fields);
		}
	}

	public function testForeignPhotoRejected(): void
	{
		$this->acl->method('assertCanViewSite')->willReturn('worker1');
		$this->photos->method('exists')->with(7, 12345)->willReturn(false);
		$this->incidents->expects($this->never())->method('insert');

		try {
			$this->svc->create(7, ['category' => 'safety', 'severity' => 'medium', 'photo_fileid' => 12345]);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('photo_fileid', $e->fields);
		}
	}

	private function stubInsert(int $id): void
	{
		$this->incidents->method('insert')->willReturnCallback(function (Incident $i) use ($id) {
			$i->setId($id);
			return $i;
		});
	}

	public function testCriticalIncidentEscalatesToSupervisors(): void
	{
		$this->acl->method('assertCanViewSite')->willReturn('worker1');
		$this->stubInsert(42);
		$this->notifier->expects($this->once())->method('notifySite')
			->with(7, 'incident_critical:42', 'incident_critical');
		$this->svc->create(7, ['category' => 'safety', 'severity' => 'critical', 'title' => 'SOS']);
	}

	public function testNonCriticalIncidentDoesNotEscalate(): void
	{
		$this->acl->method('assertCanViewSite')->willReturn('worker1');
		$this->stubInsert(43);
		$this->notifier->expects($this->never())->method('notifySite');
		$this->svc->create(7, ['category' => 'damage', 'severity' => 'low', 'note' => 'scratch']);
	}

	public function testEscalationFailureNeverRollsBackEvidence(): void
	{
		$this->acl->method('assertCanViewSite')->willReturn('worker1');
		$this->stubInsert(44);
		$this->notifier->method('notifySite')->willThrowException(new \RuntimeException('notify down'));
		// The incident is committed before alerting — a broken notifier must
		// not surface as an API failure; the failure is journaled instead.
		$inc = $this->svc->create(7, ['category' => 'safety', 'severity' => 'critical']);
		self::assertSame(44, $inc->getId());
	}

	private function correctiveFor(string $assignee): Corrective
	{
		$inc = new Incident();
		$inc->setId(50);
		$inc->setSiteId(7);
		$inc->setStatus('action');
		$inc->setRevision(2);
		$this->incidents->method('find')->willReturn($inc);

		$c = new Corrective();
		$c->setId(9);
		$c->setIncidentId(50);
		$c->setAssigneeUid($assignee);
		$c->setStatus('open');
		$c->setRevision(1);
		$this->correctives->method('find')->willReturn($c);
		return $c;
	}

	public function testViewerCannotMoveForeignCorrective(): void
	{
		$this->correctiveFor('bob');
		$this->acl->method('assertCanViewSite')->willReturn('alice');
		$this->acl->method('assertAppAccess')->willReturn('alice');
		$this->acl->method('siteRole')->with('alice', 7)->willReturn('worker');
		$this->correctives->expects($this->never())->method('update');
		$this->expectException(NotFoundException::class);
		$this->svc->updateCorrective(9, ['status' => 'done'], 1);
	}

	public function testAssigneeCanMoveOwnCorrective(): void
	{
		$this->correctiveFor('alice');
		$this->acl->method('assertCanViewSite')->willReturn('alice');
		$this->acl->method('assertAppAccess')->willReturn('alice');
		$this->acl->method('siteRole')->with('alice', 7)->willReturn('worker');
		$this->correctives->expects($this->once())->method('update')
			->willReturnCallback(static fn (Corrective $c) => $c);

		$c = $this->svc->updateCorrective(9, ['status' => 'done'], 1);
		self::assertSame('done', $c->getStatus());
	}

	public function testSupervisorCanMoveAnyCorrective(): void
	{
		$this->correctiveFor('bob');
		$this->acl->method('assertCanViewSite')->willReturn('carol');
		$this->acl->method('assertAppAccess')->willReturn('carol');
		$this->acl->method('siteRole')->with('carol', 7)->willReturn('supervisor');
		$this->correctives->expects($this->once())->method('update')
			->willReturnCallback(static fn (Corrective $c) => $c);

		$c = $this->svc->updateCorrective(9, ['resolution' => 'fixed'], 1);
		self::assertSame('fixed', $c->getResolution());
	}
}
