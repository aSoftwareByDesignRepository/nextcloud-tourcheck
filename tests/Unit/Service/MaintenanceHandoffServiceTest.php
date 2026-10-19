<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\JournalEntry;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\MaintenanceHandoffService;
use OCA\TourCheck\Service\SiteService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * F18 caller half: stored wo_ref short-circuits; a missing sibling fails
 * soft (the incident stays usable); ACL gates on site-supervise. The live
 * facade path runs in-container — the unit env cannot resolve
 * \OCP\Server::get() for a foreign app's service, so the facade seam is
 * stubbed via createMnDraft().
 */
final class MaintenanceHandoffServiceTest extends TestCase
{
	private IncidentMapper&MockObject $incidents;
	private AccessControlService&MockObject $acl;
	private IAppManager&MockObject $apps;
	private ChainService&MockObject $chain;
	private IDBConnection&MockObject $db;
	private MaintenanceHandoffService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->incidents = $this->createMock(IncidentMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->apps = $this->createMock(IAppManager::class);
		$this->chain = $this->createMock(ChainService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->svc = new MaintenanceHandoffService(
			$this->incidents,
			$this->createMock(SiteService::class),
			$this->createMock(CheckpointMapper::class),
			$this->acl,
			$this->chain,
			$this->apps,
			$this->db,
			$this->createMock(LoggerInterface::class),
		);
	}

	/** Service with the MN facade seam stubbed to a scripted result. */
	private function svcWithDraft(array $draftRes): MaintenanceHandoffService
	{
		return new class(
			$this->incidents,
			$this->createMock(SiteService::class),
			$this->createMock(CheckpointMapper::class),
			$this->acl,
			$this->chain,
			$this->apps,
			$this->db,
			$this->createMock(LoggerInterface::class),
			$draftRes,
		) extends MaintenanceHandoffService {
			public function __construct(
				IncidentMapper $incidents,
				SiteService $sites,
				CheckpointMapper $checkpoints,
				AccessControlService $acl,
				ChainService $chain,
				IAppManager $apps,
				IDBConnection $db,
				LoggerInterface $logger,
				private readonly array $draftRes,
			) {
				parent::__construct($incidents, $sites, $checkpoints, $acl, $chain, $apps, $db, $logger);
			}

			public function installed(): bool
			{
				return true;
			}

			protected function createMnDraft(array $payload, int $incidentId): array
			{
				return $this->draftRes;
			}
		};
	}

	private function incident(?string $woRef = null): Incident
	{
		$i = new Incident();
		$i->setId(12);
		$i->setSiteId(3);
		$i->setTitle('Door closer broken');
		$i->setWoRef($woRef);
		return $i;
	}

	public function testUnknownIncidentIs404(): void
	{
		$this->incidents->method('find')->willReturn(null);
		$this->acl->expects($this->never())->method('assertCanSuperviseSite');
		$this->expectException(NotFoundException::class);
		$this->svc->handoff(99);
	}

	public function testStoredWoRefShortCircuits(): void
	{
		$this->incidents->method('find')->willReturn($this->incident('WO-2026-00007'));
		$this->acl->method('assertCanSuperviseSite')->willReturn('sup1');
		$this->incidents->expects($this->never())->method('update');
		$r = $this->svc->handoff(12);
		$this->assertTrue($r['ok']);
		$this->assertTrue($r['already']);
		$this->assertSame('WO-2026-00007', $r['woNumber']);
	}

	public function testMissingSiblingFailsSoft(): void
	{
		$this->incidents->method('find')->willReturn($this->incident());
		$this->acl->method('assertCanSuperviseSite')->willReturn('sup1');
		$this->apps->method('isEnabledForUser')->with('maintenancecheck')->willReturn(false);
		$this->incidents->expects($this->never())->method('update');
		$this->chain->expects($this->never())->method('append');
		$r = $this->svc->handoff(12);
		$this->assertFalse($r['ok']);
		$this->assertSame('maintenancecheck_unavailable', $r['code']);
	}

	public function testHandoffPersistsWoRefAndJournalsInOneTx(): void
	{
		$this->incidents->method('find')->willReturn($this->incident());
		$this->acl->method('assertCanSuperviseSite')->willReturn('sup1');

		$order = [];
		$this->db->method('beginTransaction')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'begin';
		});
		$this->incidents->method('update')->willReturnCallback(static function (Incident $i) use (&$order): Incident {
			$order[] = 'update:' . (string)$i->getWoRef();
			return $i;
		});
		$this->chain->method('append')->willReturnCallback(static function (int $siteId, array $args) use (&$order): JournalEntry {
			$order[] = 'journal:' . $args['body'];
			return new JournalEntry();
		});
		$this->db->method('commit')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'commit';
		});
		$this->db->expects($this->never())->method('rollBack');

		$svc = $this->svcWithDraft(['ok' => true, 'number' => 'WO-2026-00009']);
		$r = $svc->handoff(12);

		$this->assertTrue($r['ok']);
		$this->assertSame('WO-2026-00009', $r['woNumber']);
		$this->assertSame(
			['begin', 'update:WO-2026-00009', 'journal:wo_handoff:WO-2026-00009', 'commit'],
			$order,
		);
	}

	public function testHealBranchJournalsInsideTx(): void
	{
		// MN dedupe hit while our wo_ref was empty — the healed state must
		// still leave an audit record that the handoff happened.
		$this->incidents->method('find')->willReturn($this->incident());
		$this->acl->method('assertCanSuperviseSite')->willReturn('sup1');

		$journaled = [];
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->incidents->expects($this->once())->method('update');
		$this->chain->expects($this->once())->method('append')
			->willReturnCallback(static function (int $siteId, array $args) use (&$journaled): JournalEntry {
				$journaled[] = $args['body'];
				return new JournalEntry();
			});

		$svc = $this->svcWithDraft(['ok' => true, 'number' => 'WO-2026-00010', 'already' => true]);
		$r = $svc->handoff(12);

		$this->assertTrue($r['ok']);
		$this->assertTrue($r['already']);
		$this->assertSame(['wo_handoff:WO-2026-00010'], $journaled);
	}

	public function testJournalFailureRollsBackWoRef(): void
	{
		// A journal failure must not leave a committed wo_ref — otherwise
		// every later call short-circuits already:true and the handoff is
		// permanently absent from the evidence chain.
		$this->incidents->method('find')->willReturn($this->incident());
		$this->acl->method('assertCanSuperviseSite')->willReturn('sup1');

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->incidents->expects($this->once())->method('update')->willReturnArgument(0);
		$this->chain->method('append')->willThrowException(new \RuntimeException('chain busy'));

		$svc = $this->svcWithDraft(['ok' => true, 'number' => 'WO-2026-00011']);
		$this->expectException(\RuntimeException::class);
		$svc->handoff(12);
	}
}
