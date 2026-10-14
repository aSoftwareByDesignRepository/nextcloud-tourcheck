<?php

declare(strict_types=1);

namespace OCA\TourCheck\Command;

use OCA\TourCheck\Service\ErasureService;
use OCA\TourCheck\Service\PositionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** occ tourcheck:purge — manual retention + position sweep. */
class PurgeCommand extends Command
{
	public function __construct(
		private readonly ErasureService $erasure,
		private readonly PositionService $positions,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('tourcheck:purge')
			->setDescription('Run retention tombstoning and position expiry now');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$tombstoned = $this->erasure->purgeAllSites();
		$positions = $this->positions->purgeExpired();
		$output->writeln("<info>Tombstoned {$tombstoned} event rows; deleted {$positions} positions.</info>");
		return 0;
	}
}
