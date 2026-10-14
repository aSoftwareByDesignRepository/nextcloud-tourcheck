<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\AnchorMapper;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Db\ReportDelivery;
use OCA\TourCheck\Db\ReportDeliveryMapper;
use OCA\TourCheck\Db\ReportSchedMapper;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\ReportService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Client-portal report sharing (F6): an internal-audience PDF carries worker
 * identities — toggling it portal-visible must be refused outright. Only
 * redacted client-audience deliveries may be shared, and only by a manager.
 */
final class ReportClientVisibilityTest extends TestCase
{
	private ReportDeliveryMapper&MockObject $deliveries;
	private AccessControlService&MockObject $acl;
	private ReportService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->deliveries = $this->createMock(ReportDeliveryMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->svc = new ReportService(
			$this->createMock(RoundMapper::class),
			$this->createMock(CheckEventMapper::class),
			$this->createMock(RoundCheckpointMapper::class),
			$this->createMock(ReportSchedMapper::class),
			$this->deliveries,
			$this->createMock(JournalEntryMapper::class),
			$this->createMock(AnchorMapper::class),
			$this->createMock(IncidentMapper::class),
			$this->acl,
			$this->createMock(SiteService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IAppData::class),
			$this->createMock(IMailer::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(IL10N::class),
			$this->createMock(ChainService::class),
		);
	}

	private function delivery(string $audience): ReportDelivery
	{
		$d = new ReportDelivery();
		$d->setId(9);
		$d->setSiteId(3);
		$d->setAudience($audience);
		$d->setClientVisible(false);
		return $d;
	}

	public function testInternalAudienceCannotBeShared(): void
	{
		$this->deliveries->method('find')->willReturn($this->delivery('internal'));
		$this->deliveries->expects($this->never())->method('update');
		$this->expectException(ValidationException::class);
		$this->svc->setClientVisible(9, true);
	}

	public function testClientAudienceShareFlipsFlag(): void
	{
		$this->deliveries->method('find')->willReturn($this->delivery('client'));
		$this->deliveries->expects($this->once())
			->method('update')
			->with(self::callback(fn (ReportDelivery $d) => (bool)$d->getClientVisible() === true))
			->willReturnArgument(0);
		$out = $this->svc->setClientVisible(9, true);
		self::assertTrue((bool)$out->getClientVisible());
	}

	public function testUnshareAlwaysAllowed(): void
	{
		$d = $this->delivery('internal');
		$d->setClientVisible(true); // poisoned state must be undoable
		$this->deliveries->method('find')->willReturn($d);
		$this->deliveries->expects($this->once())
			->method('update')
			->with(self::callback(fn (ReportDelivery $x) => (bool)$x->getClientVisible() === false))
			->willReturnArgument(0);
		$out = $this->svc->setClientVisible(9, false);
		self::assertFalse((bool)$out->getClientVisible());
	}

	public function testMissingDeliveryIs404(): void
	{
		$this->deliveries->method('find')->willReturn(null);
		$this->expectException(NotFoundException::class);
		$this->svc->setClientVisible(9, true);
	}
}
