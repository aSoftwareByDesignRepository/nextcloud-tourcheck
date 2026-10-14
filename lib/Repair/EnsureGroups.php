<?php

declare(strict_types=1);

namespace OCA\TourCheck\Repair;

use OCA\TourCheck\Constants;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Role groups the access door and site roles build on. Idempotent. */
class EnsureGroups implements IRepairStep
{
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {
	}

	public function getName(): string
	{
		return 'Ensure TourCheck role groups exist';
	}

	public function run(IOutput $output): void
	{
		foreach ([Constants::GROUP_ADMIN, Constants::GROUP_PLANNER, Constants::GROUP_SUPERVISOR, Constants::GROUP_WORKER] as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				$this->groupManager->createGroup($gid);
				$output->info('Created group ' . $gid);
			}
		}
	}
}
