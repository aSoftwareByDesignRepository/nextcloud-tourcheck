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
 * GA-15: the batch-ingest cap is an EVENT count per device per minute
 * (600/min), not just a request count — 100-event batches must consume
 * 100 tokens so 7 batches/min is rejected with 429 + Retry-After.
 */
class EventIngestRateLimitTest extends TestCase
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

	public function testSixHundredEventsPerMinutePass(): void
	{
		for ($i = 0; $i < 6; $i++) {
			$this->rl->hitEventIngest('worker-1', 100);
		}
		$this->addToAssertionCount(1);
	}

	public function testSixHundredAndOneEventsRejectedWithRetryAfter(): void
	{
		for ($i = 0; $i < 6; $i++) {
			$this->rl->hitEventIngest('worker-1', 100);
		}
		try {
			$this->rl->hitEventIngest('worker-1', 100);
			self::fail('7th batch must 429');
		} catch (DomainException $e) {
			self::assertSame(429, $e->httpStatus);
			self::assertNotNull($e->retryAfterSeconds);
			// All 600 hits land at $this->now — earliest expires in 60s.
			self::assertSame(60, $e->retryAfterSeconds);
		}
	}

	public function testPartialWeightRetryAfterIsWhenRoomFrees(): void
	{
		$this->rl->hitEventIngest('worker-1', 550);
		$this->now += 30; // oldest hits now 30s old
		try {
			$this->rl->hitEventIngest('worker-1', 100); // 550+100 > 600
			self::fail('must 429 — only 50 slots free');
		} catch (DomainException $e) {
			// Need 50 slots freed → the 50 oldest expire 30s from now.
			self::assertSame(30, $e->retryAfterSeconds);
		}
	}

	public function testWindowExpiryRefillsBudget(): void
	{
		for ($i = 0; $i < 6; $i++) {
			$this->rl->hitEventIngest('worker-1', 100);
		}
		$this->now += 61;
		$this->rl->hitEventIngest('worker-1', 100); // window slid — allowed again
		$this->addToAssertionCount(1);
	}

	public function testSubjectsAreIsolated(): void
	{
		for ($i = 0; $i < 6; $i++) {
			$this->rl->hitEventIngest('worker-1', 100);
		}
		$this->rl->hitEventIngest('worker-2', 100); // different device — fresh budget
		$this->addToAssertionCount(1);
	}

	public function testRequestCapStillIndependent(): void
	{
		// The 120 req/min sync limiter is a different bucket — event-cap
		// consumption must not drain the request budget and vice versa.
		$this->rl->hitEventIngest('worker-1', 600);
		$this->rl->hitEventSync('worker-1');
		$this->addToAssertionCount(1);
	}
}
