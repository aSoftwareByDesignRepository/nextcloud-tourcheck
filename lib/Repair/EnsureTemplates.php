<?php

declare(strict_types=1);

namespace OCA\TourCheck\Repair;

use OCA\TourCheck\Service\TemplateService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Seed the vertical template packs — idempotent upsert by tkey. */
class EnsureTemplates implements IRepairStep
{
	public function __construct(
		private readonly TemplateService $templates,
	) {
	}

	public function getName(): string
	{
		return 'Seed TourCheck vertical templates';
	}

	public function run(IOutput $output): void
	{
		$n = $this->templates->seed();
		$output->info('Seeded ' . $n . ' template packs');
	}
}
