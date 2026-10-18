<?php

declare(strict_types=1);

namespace OCA\TourCheck\Integration;

use OCA\MobilityCheck\Public\TodayBookingReadFacade;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * NH-15b / F11(b): read-only pool-vehicle badge ("today's car") sourced
 * from MobilityCheck's versioned facade on the same instance. Fail-closed
 * by construction: MC absent/disabled, facade missing/too old, or any
 * error → null badge, never an error surfaced to the worker.
 * Allow-listed fields only (label, plate, pickup/return window) — no
 * booking purpose, cost centre, or other member data crosses the seam.
 */
final class PoolVehicleService
{
	private const MC_APP_ID = 'mobilitycheck';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool
	{
		try {
			return $this->appManager->isEnabledForUser(self::MC_APP_ID)
				&& class_exists(TodayBookingReadFacade::class)
				&& TodayBookingReadFacade::FACADE_VERSION >= 1;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @return array{vehicle_label:string,plate:string,pickup:string,return:string}|null
	 */
	public function badgeFor(string $uid, \DateTimeInterface $now): ?array
	{
		if ($uid === '' || !$this->isAvailable()) {
			return null;
		}
		try {
			$facade = \OCP\Server::get(TodayBookingReadFacade::class);
			return $facade->todayBookingFor($uid, $now);
		} catch (\Throwable $e) {
			// Fail closed — a broken facade must never break a round.
			$this->logger->info('tourcheck pool-vehicle badge read failed', ['exception' => $e->getMessage()]);
			return null;
		}
	}
}
