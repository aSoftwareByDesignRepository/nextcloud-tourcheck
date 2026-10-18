<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\MaintenanceHandoffService;
use OCA\TourCheck\Service\SiteService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * F18 caller half: stored wo_ref short-circuits; a missing sibling fails
 * soft (the incident stays usable); ACL gates on site-supervise. The live
 * facade path runs in-container — the unit env cannot resolve
 * \OCP\Server::get() for a foreign app's service.
 */
final class MaintenanceHandoffServiceTest extends TestCase
{
	private IncidentMapper&MockObject $incidents;
	private AccessControlService&MockObject $acl;
	private IAppManager&MockObject $apps;
	private ChainService&MockObject $chain;
	private MaintenanceHandoffService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->incidents = $this->createMock(IncidentMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->apps = $this->createMock(IAppManager::class);
		$this->chain = $this->createMock(ChainService::class);
		$this->svc = new MaintenanceHandoffService(
			$this->incidents,
			$this->createMock(SiteService::class),
			$this->createMock(CheckpointMapper::class),
			$this->acl,
			$this->chain,
			$this->apps,
			$this->createMock(LoggerInterface::class),
		);
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
}
