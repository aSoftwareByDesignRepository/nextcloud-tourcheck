<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\CheckpointToken;
use OCA\TourCheck\Db\CheckpointTokenMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\NotificationService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\TokenAnomalyService;
use OCA\TourCheck\Service\TokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IMemcache;
use OCP\IUser;
use OCP\Lock\ILockingProvider;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * GA-3: token_invalid_burst — >20 invalid submissions / 5 min, global AND
 * per-IP, exactly one structured event per window per scope; raw IPs never
 * leave the process. Site-attributable misses (rotated tag, archived
 * checkpoint) feed the per-site anomaly counter → one chained journal
 * burst + one deduped site notification.
 */
final class TokenAnomalyTest extends TestCase
{
	private IMemcache $cache;
	private LoggerInterface&MockObject $logger;
	private INotificationManager&MockObject $notifications;
	private ChainService&MockObject $chain;
	private NotificationService&MockObject $notify;
	private TokenAnomalyService $svc;
	private int $now = 1760000000;

	protected function setUp(): void
	{
		parent::setUp();
		$store = new \ArrayObject();
		$this->cache = $this->createMock(IMemcache::class);
		$this->cache->method('get')->willReturnCallback(static fn (string $k) => $store[$k] ?? null);
		$this->cache->method('set')->willReturnCallback(static function (string $k, $v, $ttl = 0) use ($store): bool {
			$store[$k] = $v;
			return true;
		});
		$this->cache->method('add')->willReturnCallback(static function (string $k, $v, $ttl = 0) use ($store): bool {
			if (isset($store[$k])) {
				return false;
			}
			$store[$k] = $v;
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$locking = $this->createMock(ILockingProvider::class); // acquire/release = no-op
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->notifications = $this->createMock(INotificationManager::class);
		$this->chain = $this->createMock(ChainService::class);
		$this->notify = $this->createMock(NotificationService::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getAppAdmins')->willReturn(['boss']);
		$groupManager = $this->createMock(IGroupManager::class);
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('ncadmin');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$admin]);
		$groupManager->method('get')->willReturn($group);
		$sites = $this->createMock(SiteService::class);
		$site = new Site();
		$site->setId(9);
		$site->setName('Alpha');
		$sites->method('findById')->willReturn($site);
		$this->notifications->method('createNotification')->willReturnCallback(function () {
			$n = $this->createMock(INotification::class);
			$n->method('setApp')->willReturnSelf();
			$n->method('setUser')->willReturnSelf();
			$n->method('setDateTime')->willReturnSelf();
			$n->method('setObject')->willReturnCallback(function (string $t, string $id) use ($n) {
				if ($t === '' || isset($t[64])) {
					throw new \OCP\Notification\InvalidValueException('objectType');
				}
				if ($id === '' || isset($id[64])) {
					throw new \OCP\Notification\InvalidValueException('objectId');
				}
				return $n;
			});
			$n->method('setSubject')->willReturnCallback(function (string $s) use ($n) {
				if ($s === '' || isset($s[64])) {
					throw new \OCP\Notification\InvalidValueException('subject');
				}
				return $n;
			});
			// Enforce the real OC\Notification\Notification contract:
			// setMessage throws InvalidValueException when the message
			// exceeds 64 chars (isset($message[64])).
			$n->method('setMessage')->willReturnCallback(function (string $m) use ($n) {
				if ($m === '' || isset($m[64])) {
					throw new \OCP\Notification\InvalidValueException('message');
				}
				return $n;
			});
			return $n;
		});
		$this->svc = new TokenAnomalyService(
			$cacheFactory, $time, $locking, $this->notifications, $groupManager,
			$settings, $this->logger, $this->chain, $this->notify, $sites,
		);
	}

	public function testBelowThresholdEmitsNothing(): void
	{
		$this->logger->expects(self::never())->method('warning');
		$this->notifications->expects(self::never())->method('notify');
		for ($i = 0; $i < 20; $i++) {
			$this->svc->recordInvalid('10.0.0.1');
		}
	}

	public function testTwentyFirstHitEmitsExactlyOnce(): void
	{
		$logged = 0;
		$this->logger->method('warning')->willReturnCallback(function ($msg) use (&$logged) {
			if ($msg === 'tourcheck: token_invalid_burst') {
				$logged++;
			}
		});
		for ($i = 0; $i < 40; $i++) {
			$this->svc->recordInvalid('10.0.0.1');
		}
		// global scope once + ip scope once — never more despite 40 hits
		self::assertSame(2, $logged);
	}

	public function testIpScopeNeverLeaksRawAddress(): void
	{
		$this->logger->method('warning')->willReturnCallback(function ($msg, array $ctx = []) {
			if (($ctx['ip_hash'] ?? null) !== null) {
				self::assertStringNotContainsString('10.9.8.7', (string)$ctx['ip_hash']);
				self::assertSame(TokenAnomalyService::ipHash('10.9.8.7'), $ctx['ip_hash']);
			}
			return null;
		});
		for ($i = 0; $i < 25; $i++) {
			$this->svc->recordInvalid('10.9.8.7');
		}
		$this->addToAssertionCount(1);
	}

	public function testPerIpCountersAreIndependent(): void
	{
		$scopes = [];
		$this->logger->method('warning')->willReturnCallback(function ($msg, array $ctx = []) use (&$scopes) {
			if ($msg === 'tourcheck: token_invalid_burst') {
				$scopes[] = $ctx['scope'];
			}
			return null;
		});
		for ($i = 0; $i < 19; $i++) {
			$this->svc->recordInvalid('10.0.0.1');
			$this->svc->recordInvalid('10.0.0.2');
		}
		// 38 hits → global burst fires; neither IP reached 20 → no ip scope.
		self::assertSame(['global'], $scopes);
	}

	public function testSlidingWindowExpirySuppressesBurst(): void
	{
		$this->logger->expects(self::never())->method('warning');
		for ($i = 0; $i < 15; $i++) {
			$this->svc->recordInvalid('10.0.0.1');
		}
		$this->now += 400; // outside the 300 s window — old hits expire
		for ($i = 0; $i < 15; $i++) {
			$this->svc->recordInvalid('10.0.0.1');
		}
	}

	public function testSiteHitBelowThresholdJournalsNothing(): void
	{
		$this->chain->expects(self::never())->method('append');
		$this->notify->expects(self::never())->method('notifySite');
		for ($i = 0; $i < 20; $i++) {
			$this->svc->recordSiteHit(9, 'rotated_token');
		}
	}

	public function testSiteHitBurstChainsExactlyOnceAndNotifies(): void
	{
		$this->chain->expects(self::once())->method('append')
			->with(9, self::callback(static fn (array $p) => $p['body'] === 'token_anomaly_burst:rotated_token'));
		$this->notify->expects(self::once())->method('notifySite')->willReturn(true);
		for ($i = 0; $i < 30; $i++) {
			$this->svc->recordSiteHit(9, 'rotated_token');
		}
	}

	public function testRecordInvalidNeverThrowsOnCacheFailure(): void
	{
		$bad = $this->createMock(IMemcache::class);
		$bad->method('get')->willThrowException(new \RuntimeException('cache down'));
		$cf = $this->createMock(ICacheFactory::class);
		$cf->method('createDistributed')->willReturn($bad);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1);
		$svc = new TokenAnomalyService(
			$cf, $time, $this->createMock(ILockingProvider::class),
			$this->notifications, $this->createMock(IGroupManager::class),
			$this->createMock(SettingsService::class), $this->logger,
		);
		$svc->recordInvalid('10.0.0.1'); // must swallow — capture never breaks
		$this->addToAssertionCount(1);
	}

	// ── TokenService::resolve wiring ──────────────────────────────────

	private function tokenSvc(CheckpointTokenMapper&MockObject $tokens, CheckpointMapper&MockObject $cps, TokenAnomalyService&MockObject $anomaly): TokenService
	{
		return new TokenService($tokens, $cps, $this->createMock(ITimeFactory::class), $anomaly, $this->createMock(SettingsService::class), $this->createMock(\OCP\IDBConnection::class));
	}

	public function testResolveMalformedCountsInvalid(): void
	{
		$tokens = $this->createMock(CheckpointTokenMapper::class);
		$anomaly = $this->createMock(TokenAnomalyService::class);
		$anomaly->expects(self::once())->method('recordInvalid')->with('1.2.3.4');
		$anomaly->expects(self::never())->method('recordSiteHit');
		$this->expectException(NotFoundException::class);
		$this->tokenSvc($tokens, $this->createMock(CheckpointMapper::class), $anomaly)->resolve('tiny', '1.2.3.4');
	}

	public function testResolveUnknownHashCountsGlobalOnly(): void
	{
		$tokens = $this->createMock(CheckpointTokenMapper::class);
		$tokens->method('findActiveByHash')->willReturn(null);
		$tokens->method('findAnyByHash')->willReturn(null);
		$anomaly = $this->createMock(TokenAnomalyService::class);
		$anomaly->expects(self::once())->method('recordInvalid');
		$anomaly->expects(self::never())->method('recordSiteHit');
		$this->expectException(NotFoundException::class);
		$this->tokenSvc($tokens, $this->createMock(CheckpointMapper::class), $anomaly)->resolve('tck_' . str_repeat('a', 32), '1.2.3.4');
	}

	public function testResolveRevokedCountsSiteRotated(): void
	{
		$dead = new CheckpointToken();
		$dead->setCheckpointId(5);
		$dead->setRevokedAt(new \DateTime());
		$tokens = $this->createMock(CheckpointTokenMapper::class);
		$tokens->method('findActiveByHash')->willReturn(null);
		$tokens->method('findAnyByHash')->willReturn($dead);
		$cp = new Checkpoint();
		$cp->setSiteId(9);
		$cps = $this->createMock(CheckpointMapper::class);
		$cps->method('find')->willReturn($cp);
		$anomaly = $this->createMock(TokenAnomalyService::class);
		$anomaly->expects(self::once())->method('recordSiteHit')->with(9, 'rotated_token');
		$anomaly->expects(self::once())->method('recordInvalid')->with('1.2.3.4');
		$this->expectException(NotFoundException::class);
		$this->tokenSvc($tokens, $cps, $anomaly)->resolve('tck_' . str_repeat('a', 32), '1.2.3.4');
	}

	public function testResolveActiveTokenInactiveCheckpointCountsSite(): void
	{
		$t = new CheckpointToken();
		$t->setCheckpointId(5);
		$tokens = $this->createMock(CheckpointTokenMapper::class);
		$tokens->method('findActiveByHash')->willReturn($t);
		$cp = new Checkpoint();
		$cp->setSiteId(9);
		$cp->setActive(0);
		$cps = $this->createMock(CheckpointMapper::class);
		$cps->method('find')->willReturn($cp);
		$anomaly = $this->createMock(TokenAnomalyService::class);
		$anomaly->expects(self::once())->method('recordSiteHit')->with(9, 'inactive_checkpoint');
		$this->expectException(NotFoundException::class);
		$this->tokenSvc($tokens, $cps, $anomaly)->resolve('tck_' . str_repeat('a', 32), null);
	}
}
