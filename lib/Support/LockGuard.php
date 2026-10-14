<?php

declare(strict_types=1);

namespace OCA\TourCheck\Support;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Runs a callable under an exclusive lock with bounded retry.
 *
 * ILockingProvider::acquireLock() is NON-BLOCKING — a contended acquire
 * throws LockedException immediately. Callers that need "wait until free"
 * semantics must retry; this helper centralizes the policy: jittered
 * backoff up to the attempt budget, then the last LockedException
 * propagates for the caller to translate into a client-facing error.
 */
final class LockGuard
{
	/**
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 * @throws LockedException when the lock stays contended past the budget
	 */
	public static function run(ILockingProvider $lock, string $key, callable $fn, int $maxAttempts = 40): mixed
	{
		$attempt = 0;
		while (true) {
			try {
				$lock->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException $e) {
				if (++$attempt >= $maxAttempts) {
					throw $e;
				}
				usleep(random_int(5000, 25000));
			}
		}
		try {
			return $fn();
		} finally {
			$lock->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
