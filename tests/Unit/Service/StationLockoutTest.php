<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\Station;
use OCA\TourCheck\Db\StationMapper;
use OCA\TourCheck\Db\StationMember;
use OCA\TourCheck\Db\StationMemberMapper;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\LicenseService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\StationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Dual-level PIN lockout (spec §4): members lock individually AND the
 * station locks — member-id probing must burn the station counter even
 * though no member row exists. Pairing codes carry ≥64-bit entropy.
 */
final class StationLockoutTest extends TestCase
{
	private StationMapper&MockObject $stations;
	private StationMemberMapper&MockObject $members;
	private AccessControlService&MockObject $acl;
	private LicenseService&MockObject $license;
	private SettingsService&MockObject $settings;
	private ChainService&MockObject $chain;
	private StationService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->stations = $this->createMock(StationMapper::class);
		$this->members = $this->createMock(StationMemberMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->license = $this->createMock(LicenseService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getPinLength')->willReturn(6);
		$this->settings->method('getPinLockoutAttempts')->willReturn(5);
		$this->settings->method('getPinLockoutMinutes')->willReturn(15);
		$this->settings->method('getChainSecret')->willReturn('pepper');
		$this->chain = $this->createMock(ChainService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);

		$this->svc = new StationService(
			$this->stations,
			$this->members,
			$this->createMock(CheckpointMapper::class),
			$this->acl,
			$this->createMock(SiteService::class),
			$this->chain,
			$this->settings,
			$time,
			$this->createMock(IDBConnection::class),
			$this->license,
			$this->createMock(ILockingProvider::class),
		);
	}

	private function station(array $mut = []): Station
	{
		$s = new Station();
		$s->setId(11);
		$s->setSiteId(7);
		$s->setActive(1);
		$s->setFailCount(0);
		foreach ($mut as $setter => $v) {
			$s->$setter($v);
		}
		return $s;
	}

	private function member(int $id, string $pin): StationMember
	{
		$m = new StationMember();
		$m->setId($id);
		$m->setStationId(11);
		$m->setDisplayName('Worker');
		$m->setPinHash(password_hash($pin . ':pepper', PASSWORD_ARGON2ID));
		$m->setActive(1);
		$m->setFailCount(0);
		return $m;
	}

	public function testLockedStationRejectsBeforeMemberLookup(): void
	{
		$station = $this->station(['setLockoutUntil' => (clone $this->now)->modify('+5 minutes')]);
		$this->members->expects($this->never())->method('find');
		$this->stations->expects($this->never())->method('bumpFailCount');
		try {
			$this->svc->verifyMemberPin($station, 5, '123456');
			self::fail('expected DomainException');
		} catch (DomainException $e) {
			self::assertSame('pin_locked', $e->errorCode);
			self::assertSame(429, $e->httpStatus);
		}
	}

	public function testUnknownMemberBurnsStationCounter(): void
	{
		// Member-id probing must not be free — it decrements the station budget.
		$this->members->method('find')->willReturn(null);
		$this->stations->expects($this->once())->method('bumpFailCount')->with(11)->willReturn(1);
		$this->expectException(NotFoundException::class);
		$this->svc->verifyMemberPin($this->station(), 9999, '123456');
	}

	public function testMemberFromOtherStationBurnsStationCounter(): void
	{
		$foreign = $this->member(5, '123456');
		$foreign->setStationId(999);
		$this->members->method('find')->willReturn($foreign);
		$this->stations->expects($this->once())->method('bumpFailCount')->with(11)->willReturn(1);
		$this->expectException(NotFoundException::class);
		$this->svc->verifyMemberPin($this->station(), 5, '123456');
	}

	public function testWrongPinBumpsMemberAndStation(): void
	{
		$this->members->method('find')->willReturn($this->member(5, '654321'));
		$this->members->expects($this->once())->method('bumpFailCount')->with(5)->willReturn(1);
		$this->stations->expects($this->once())->method('bumpFailCount')->with(11)->willReturn(3);
		$this->expectException(NotFoundException::class);
		$this->svc->verifyMemberPin($this->station(), 5, '123456');
	}

	public function testNthWrongPinLocksMember(): void
	{
		$member = $this->member(5, '654321');
		$this->members->method('find')->willReturn($member);
		$this->members->method('bumpFailCount')->willReturn(5); // = attempts
		$this->members->expects($this->once())->method('update')
			->with(self::callback(static fn (StationMember $m) => $m->getLockoutUntil() instanceof \DateTime));
		try {
			$this->svc->verifyMemberPin($this->station(), 5, '123456');
			self::fail('expected NotFoundException');
		} catch (NotFoundException) {
		}
	}

	public function testStationLocksAtDoubleThreshold(): void
	{
		$this->members->method('find')->willReturn(null);
		$this->stations->method('bumpFailCount')->willReturn(10); // 2×5
		$this->stations->expects($this->once())->method('update')
			->with(self::callback(static fn (Station $s) => $s->getLockoutUntil() instanceof \DateTime));
		try {
			$this->svc->verifyMemberPin($this->station(), 1, '123456');
			self::fail('expected NotFoundException');
		} catch (NotFoundException) {
		}
	}

	public function testSuccessResetsBothCounters(): void
	{
		$member = $this->member(5, '123456');
		$member->setFailCount(2);
		$this->members->method('find')->willReturn($member);
		$this->members->expects($this->once())->method('update')
			->with(self::callback(static fn (StationMember $m) => (int)$m->getFailCount() === 0 && $m->getLockoutUntil() === null));
		$station = $this->station(['setFailCount' => 4]);
		$this->stations->expects($this->once())->method('update')
			->with(self::callback(static fn (Station $s) => (int)$s->getFailCount() === 0));
		$m = $this->svc->verifyMemberPin($station, 5, '123456');
		self::assertSame(5, (int)$m->getId());
	}

	public function testPairingCodeIs64BitHexAndJournaled(): void
	{
		$this->acl->method('assertCanManageSite')->willReturn('planner1');
		$station = $this->station();
		$this->stations->method('find')->willReturn($station);
		$this->stations->expects($this->once())->method('update');
		$this->chain->expects($this->once())->method('append')
			->with(7, self::callback(static fn (array $a) => $a['body'] === 'station_pairing_issued'));

		$code = $this->svc->issuePairingCode(11);
		self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $code);
		self::assertSame(hash('sha256', $code), $station->getPairingCodeHash());
	}

	public function testSixDigitPairingCodeIsRejected(): void
	{
		$this->stations->expects($this->never())->method('findByPairingHash');
		$this->expectException(NotFoundException::class);
		$this->svc->redeemPairingCode('123456');
	}

	/** Web roster is a site-manage surface — the ACL deny must fire before the mapper. */
	public function testManagerRosterDeniesNonManager(): void
	{
		$this->stations->method('find')->willReturn($this->station());
		$this->acl->method('assertCanManageSite')->with(7)
			->willThrowException(new NotFoundException());
		$this->members->expects($this->never())->method('findForStation');
		$this->expectException(NotFoundException::class);
		$this->svc->listMembersForManager(11);
	}

	public function testManagerRosterReturnsForManager(): void
	{
		$this->stations->method('find')->willReturn($this->station());
		$this->acl->method('assertCanManageSite')->with(7)->willReturn('m1');
		$m = new StationMember();
		$this->members->expects($this->once())->method('findForStation')
			->with(11)->willReturn([$m]);
		self::assertSame([$m], $this->svc->listMembersForManager(11));
	}

	/** Station-bearer callers (kiosk/companion) bypass the site ACL by design. */
	public function testBearerRosterHasNoAclCall(): void
	{
		$this->stations->method('find')->willReturn($this->station());
		$this->acl->expects($this->never())->method('assertCanManageSite');
		$this->members->expects($this->once())->method('findForStation')->willReturn([]);
		$this->svc->listMembers(11);
	}
}
