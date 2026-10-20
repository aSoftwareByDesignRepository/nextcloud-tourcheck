<?php

declare(strict_types=1);

namespace OCA\TourCheck\Repair;

use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Role groups the access door and site roles build on. Idempotent.
 *
 * Group ids are inlined: repair steps run during the in-request app update
 * while the previously installed `Constants` class may still be loaded, so
 * referencing constants added in the same release can fatal with "Undefined
 * constant" (arbeitszeitcheck 1.9.4 / GH #45 lesson).
 */
class EnsureGroups implements IRepairStep
{
	private const GROUP_IDS = [
		'tourcheck_admin',
		'tourcheck_planner',
		'tourcheck_supervisor',
		'tourcheck_worker',
		'tourcheck_client',
	];

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
		foreach (self::GROUP_IDS as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				$this->groupManager->createGroup($gid);
				$output->info('Created group ' . $gid);
			}
		}
	}
}
