<?php

declare(strict_types=1);

namespace OCA\TourCheck\Integration;

use OCA\DutyCheck\Public\ShiftRoundLinkReadFacade;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * F17 consumer half — annotates a round detail with the DutyCheck shifts
 * linked to it (dc_assignments.tck_round_id), so a missed round visibly
 * maps to its coverage. Fail-closed like PoolVehicleService: DC absent,
 * facade missing/too old, or any error → empty list, never a 500 inside
 * the round view. Allow-listed fields only (assignment/period/employee
 * ids + times) — no employee names cross the seam.
 */
final class DutyShiftLinkService
{
	private const DC_APP_ID = 'dutycheck';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool
	{
		try {
			return $this->appManager->isEnabledForUser(self::DC_APP_ID)
				&& class_exists(ShiftRoundLinkReadFacade::class)
				&& ShiftRoundLinkReadFacade::FACADE_VERSION >= 1;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @return list<array{assignmentId:int, periodId:int, employeeId:int, workDate:string, startTime:string, endTime:string}>
	 */
	public function shiftsForRound(int $roundId): array
	{
		if ($roundId <= 0 || !$this->isAvailable()) {
			return [];
		}
		try {
			$shifts = \OCP\Server::get(ShiftRoundLinkReadFacade::class)->shiftsForRound($roundId);
		} catch (\Throwable $e) {
			$this->logger->info('tourcheck duty-shift link read failed', ['exception' => $e->getMessage()]);
			return [];
		}
		return is_array($shifts) ? $shifts : [];
	}
}
