<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Support\LockGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Distributed rate limiting (memcache backend — survives across PHP workers,
 * unlike per-process counters). Buckets: token resolve, station pairing,
 * PIN attempts (also DB lockout for persistence), live page, event sync.
 */
class RateLimitService
{
	private readonly IMemcache $cache;

	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly ITimeFactory $timeFactory,
		private readonly ILockingProvider $locking,
	) {
		$this->cache = $cacheFactory->createDistributed('tck_rl');
	}

	/** @throws DomainException (429) when the bucket is exhausted. */
	public function check(string $bucket, string $subject, int $maxPerWindow, int $windowSeconds, int $weight = 1): void
	{
		// The hits array is read-modify-write — serialize per bucket+subject or
		// parallel requests under-count (lost updates = permissive limiter).
		// file_locks.key is varchar(64): md5 keeps the lock path short.
		$lockKey = 'tck-rl-' . md5($bucket . '|' . $subject);
		try {
			LockGuard::run($this->locking, $lockKey,
				fn () => $this->checkLocked($bucket, $subject, $maxPerWindow, $windowSeconds, $weight), 20);
		} catch (LockedException) {
			// Contention on one bucket+subject IS load — fail closed.
			throw new DomainException(Constants::ERR_RATE_LIMITED, 'Too many requests. Try again later.', 429, $windowSeconds);
		}
	}

	private function checkLocked(string $bucket, string $subject, int $maxPerWindow, int $windowSeconds, int $weight): void
	{
		$key = $bucket . '|' . $subject;
		$now = $this->timeFactory->getTime();
		$raw = $this->cache->get($key);
		$hits = is_array($raw) ? $raw : [];
		$hits = array_values(array_filter($hits, static fn ($t) => is_int($t) && $t > $now - $windowSeconds));
		$weight = min(max(1, $weight), $maxPerWindow);
		if (count($hits) + $weight > $maxPerWindow) {
			sort($hits);
			// Retry when enough of the oldest hits have expired to fit the weight.
			$idx = max(0, count($hits) + $weight - $maxPerWindow - 1);
			$retry = max(1, $hits[$idx] + $windowSeconds - $now);
			throw new DomainException(Constants::ERR_RATE_LIMITED, 'Too many requests. Try again later.', 429, $retry);
		}
		for ($i = 0; $i < $weight; $i++) {
			$hits[] = $now;
		}
		$this->cache->set($key, $hits, $windowSeconds + 60);
	}

	/** Invalid checkpoint-token attempts: per-IP global (invalid tokens name no site). */
	public function hitTokenResolve(string $ip): void
	{
		$this->check('tok', $ip, 10, 60);
	}

	public function hitPairing(string $ip): void
	{
		$this->check('pair', $ip, 5, 600);
	}

	public function hitLivePage(string $ip): void
	{
		$this->check('live', $ip, 20, 60);
	}

	public function hitStationAuth(string $ip): void
	{
		$this->check('stauth', $ip, 20, 60);
	}

	public function hitEventSync(string $subject): void
	{
		$this->check('sync', $subject, 120, 60);
	}

	/**
	 * GA-15: per-device event-count cap on the batch-ingest endpoint —
	 * 120 req/min alone allows 12,000 events/min (100/batch). $count is the
	 * submitted batch size; replays still consume capacity (they cost work).
	 */
	public function hitEventIngest(string $subject, int $count): void
	{
		$this->check('events', $subject, 600, 60, $count);
	}

	/**
	 * Chain verification reloads + rehashes the entire journal — O(rows) of
	 * expensive work. Supervisors verify rarely in practice; 5/min per
	 * site+uid stops scripted hammering without touching real use.
	 */
	public function hitVerifyChain(int $siteId, string $uid): void
	{
		$this->check('vchain', $siteId . '|' . $uid, 5, 60);
	}
}
