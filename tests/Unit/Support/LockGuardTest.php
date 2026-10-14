<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Support;

use OCA\TourCheck\Support\LockGuard;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

final class LockGuardTest extends TestCase
{
	public function testRunsCallableUnderLockAndReleases(): void
	{
		$lock = $this->createMock(ILockingProvider::class);
		$lock->expects($this->once())->method('acquireLock')
			->with('k', ILockingProvider::LOCK_EXCLUSIVE);
		$lock->expects($this->once())->method('releaseLock')
			->with('k', ILockingProvider::LOCK_EXCLUSIVE);

		$this->assertSame(42, LockGuard::run($lock, 'k', fn () => 42));
	}

	public function testRetriesAfterContentionThenSucceeds(): void
	{
		$lock = $this->createMock(ILockingProvider::class);
		$calls = 0;
		$lock->method('acquireLock')->willReturnCallback(function () use (&$calls): void {
			if (++$calls < 3) {
				throw new LockedException('k');
			}
		});
		$lock->expects($this->once())->method('releaseLock');

		$this->assertSame('ok', LockGuard::run($lock, 'k', fn () => 'ok'));
		$this->assertSame(3, $calls);
	}

	public function testGivesUpAfterAttemptBudgetAndNeverRunsCallable(): void
	{
		$lock = $this->createMock(ILockingProvider::class);
		$lock->method('acquireLock')->willThrowException(new LockedException('k'));
		$lock->expects($this->never())->method('releaseLock');

		$ran = false;
		try {
			LockGuard::run($lock, 'k', function () use (&$ran): void {
				$ran = true;
			}, 3);
			$this->fail('expected LockedException');
		} catch (LockedException) {
		}
		$this->assertFalse($ran);
	}

	public function testReleasesLockWhenCallableThrows(): void
	{
		$lock = $this->createMock(ILockingProvider::class);
		$lock->expects($this->once())->method('releaseLock');

		$this->expectException(\RuntimeException::class);
		LockGuard::run($lock, 'k', function (): void {
			throw new \RuntimeException('boom');
		});
	}
}
