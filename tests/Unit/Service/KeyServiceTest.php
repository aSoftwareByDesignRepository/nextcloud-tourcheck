<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\KeyLogMapper;
use OCA\TourCheck\Db\Station;
use OCA\TourCheck\Db\StationMapper;
use OCA\TourCheck\Db\StationMember;
use OCA\TourCheck\Db\StationMemberMapper;
use OCA\TourCheck\Db\TckKey;
use OCA\TourCheck\Db\TckKeyMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\KeyService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * NH-10 key register: custody transitions must be atomic (double-take
 * impossible), holder validation must bind to the site (ghost uid and
 * foreign station member both rejected), and the journal gets an entry
 * per movement.
 */
final class KeyServiceTest extends TestCase
{
	private TckKeyMapper&MockObject $keys;
	private KeyLogMapper&MockObject $log;
	private StationMemberMapper&MockObject $members;
	private StationMapper&MockObject $stations;
	private AccessControlService&MockObject $acl;
	private ChainService&MockObject $chain;
	private KeyService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->keys = $this->createMock(TckKeyMapper::class);
		$this->log = $this->createMock(KeyLogMapper::class);
		$this->members = $this->createMock(StationMemberMapper::class);
		$this->stations = $this->createMock(StationMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->chain = $this->createMock(ChainService::class);
		$users = $this->createMock(\OCP\IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn ($u) => $u !== 'ghost');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-20T12:00:00Z'));
		$this->svc = new KeyService(
			$this->keys,
			$this->log,
			$this->members,
			$this->stations,
			$this->createMock(SiteService::class),
			$this->acl,
			$this->chain,
			$users,
			$time,
			$this->createMock(IDBConnection::class),
		);
	}

	private function key(int $siteId = 7, string $status = 'in'): TckKey
	{
		$k = new TckKey();
		$k->setId(42);
		$k->setSiteId($siteId);
		$k->setLabel('Main door');
		$k->setStatus($status);
		return $k;
	}

	public function testTakeHappyPathJournalsAndLogs(): void
	{
		$this->keys->method('find')->willReturn($this->key());
		$this->acl->method('assertCanViewSite')->willReturn('anna');
		$this->acl->method('currentUid')->willReturn('anna');
		$this->acl->method('siteRole')->willReturn('worker');
		$this->keys->expects($this->once())->method('atomicTake')->willReturn(true);
		$this->log->expects($this->once())->method('insert')->with(
			$this->callback(fn ($l) => $l->getAction() === 'take' && $l->getKeyId() === 42),
		);
		$this->chain->expects($this->once())->method('append')->with(
			7, $this->callback(fn ($a) => $a['body'] === 'key_take'),
		);
		$this->svc->take(42, 'anna', null);
	}

	public function testDoubleTakeFailsClosed(): void
	{
		$this->keys->method('find')->willReturn($this->key());
		$this->acl->method('assertCanViewSite')->willReturn('anna');
		$this->acl->method('siteRole')->willReturn('worker');
		// CAS loses — a concurrent take won the row.
		$this->keys->method('atomicTake')->willReturn(false);
		$this->expectException(ValidationException::class);
		$this->svc->take(42, null, null);
	}

	public function testGhostUidHolderRejected(): void
	{
		$this->keys->method('find')->willReturn($this->key());
		$this->acl->method('assertCanViewSite')->willReturn('anna');
		// The trap case: the deleted user's STALE membership row still
		// reports a role — only the userExists leg catches the ghost.
		$this->acl->method('siteRole')->willReturn('worker');
		// Fail-closed: validation precedes the CAS — the mutation must
		// never be attempted for a ghost holder.
		$this->keys->expects($this->never())->method('atomicTake');
		$this->expectException(ValidationException::class);
		$this->svc->take(42, 'ghost', null);
	}

	public function testForeignSiteMemberRejected(): void
	{
		$this->keys->method('find')->willReturn($this->key(7));
		$this->acl->method('assertCanViewSite')->willReturn('anna');
		$m = new StationMember();
		$m->setId(9);
		$m->setStationId(3);
		$m->setActive(1);
		$this->members->method('find')->willReturn($m);
		$st = new Station();
		$st->setId(3);
		$st->setSiteId(99); // belongs to a DIFFERENT site
		$this->stations->method('find')->willReturn($st);
		$this->expectException(ValidationException::class);
		$this->svc->take(42, null, 9);
	}

	public function testReturnByNonHolderNonManagerRejected(): void
	{
		$k = $this->key(7, 'out');
		$k->setHolderUid('bob');
		$this->keys->method('find')->willReturn($k);
		$this->acl->method('assertCanViewSite')->willReturn('anna');
		$this->acl->method('assertCanManageSite')->willThrowException(new NotFoundException());
		$this->expectException(ValidationException::class);
		$this->svc->return(42);
	}

	public function testReturnByManagerAllowed(): void
	{
		$k = $this->key(7, 'out');
		$k->setHolderUid('bob');
		$this->keys->method('find')->willReturn($k);
		$this->acl->method('assertCanViewSite')->willReturn('anna');
		$this->acl->method('assertCanManageSite')->willReturn('anna');
		$this->acl->method('currentUid')->willReturn('anna');
		$this->keys->expects($this->once())->method('atomicReturn')->willReturn(true);
		$this->svc->return(42);
	}

	public function testStationTakeOnForeignKeyFails(): void
	{
		$station = new Station();
		$station->setId(5);
		$station->setSiteId(7);
		$this->keys->method('find')->willReturn($this->key(99));
		$this->expectException(NotFoundException::class);
		$this->svc->takeForStation($station, 42, 9);
	}

	public function testStationReturnOnlyByHoldingMember(): void
	{
		$station = new Station();
		$station->setSiteId(7);
		$k = $this->key(7, 'out');
		$k->setHolderMemberId(8); // member 8 holds it — member 9 taps return
		$this->keys->method('find')->willReturn($k);
		$this->expectException(ValidationException::class);
		$this->svc->returnForStation($station, 42, 9);
	}
}
