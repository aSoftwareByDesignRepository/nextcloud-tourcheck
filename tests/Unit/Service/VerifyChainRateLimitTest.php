<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Service\RateLimitService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * verifyChain reloads + rehashes the entire journal — O(rows) of work on a
 * supervisor-reachable endpoint. The bucket must 429 scripted hammering
 * while leaving real supervisor checks untouched.
 */
class VerifyChainRateLimitTest extends TestCase
{
	/** @var array<string,mixed> */
	private array $store = [];
	private int $now = 1_760_000_000;
	private RateLimitService $rl;

	protected function setUp(): void
	{
		$cache = $this->createMock(IMemcache::class);
		$cache->method('get')->willReturnCallback(fn ($k) => $this->store[$k] ?? null);
		$cache->method('set')->willReturnCallback(function ($k, $v) {
			$this->store[$k] = $v;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$lock = $this->createMock(ILockingProvider::class);
		$this->rl = new RateLimitService($factory, $time, $lock);
	}

	public function testFiveVerifiesPerMinutePass(): void
	{
		for ($i = 0; $i < 5; $i++) {
			$this->rl->hitVerifyChain(7, 'sup');
		}
		$this->addToAssertionCount(1);
	}

	public function testSixthVerifyIsRejectedWithRetryAfter(): void
	{
		for ($i = 0; $i < 5; $i++) {
			$this->rl->hitVerifyChain(7, 'sup');
		}
		try {
			$this->rl->hitVerifyChain(7, 'sup');
			self::fail('6th verify inside 60s must 429');
		} catch (DomainException $e) {
			self::assertSame(429, $e->httpStatus);
			self::assertSame(60, $e->retryAfterSeconds);
		}
	}

	public function testDifferentSupervisorSameSiteIsolated(): void
	{
		for ($i = 0; $i < 5; $i++) {
			$this->rl->hitVerifyChain(7, 'sup');
		}
		$this->rl->hitVerifyChain(7, 'sup2'); // different uid — fresh budget
		$this->addToAssertionCount(1);
	}

	public function testSameSupervisorDifferentSiteIsolated(): void
	{
		for ($i = 0; $i < 5; $i++) {
			$this->rl->hitVerifyChain(7, 'sup');
		}
		$this->rl->hitVerifyChain(8, 'sup'); // different site — fresh budget
		$this->addToAssertionCount(1);
	}

	public function testWindowExpiryRefillsBudget(): void
	{
		for ($i = 0; $i < 5; $i++) {
			$this->rl->hitVerifyChain(7, 'sup');
		}
		$this->now += 61;
		$this->rl->hitVerifyChain(7, 'sup'); // window slid — allowed again
		$this->addToAssertionCount(1);
	}
}
