<?php

declare(strict_types=1);

namespace OCA\TourCheck\Command;

use OCA\TourCheck\Service\AnchorService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** occ tourcheck:reanchor <siteId> — force a fresh anchor request + delivery. */
class ReanchorCommand extends Command
{
	public function __construct(
		private readonly AnchorService $anchors,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('tourcheck:reanchor')
			->setDescription('Request and deliver an external anchor for a site')
			->addArgument('siteId', InputArgument::REQUIRED, 'Site id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$siteId = (int)$input->getArgument('siteId');
		$anchor = $this->anchors->requestAnchor($siteId, 'manual');
		if ($anchor === null) {
			$output->writeln('<comment>No journal entries — nothing to anchor.</comment>');
			return 1;
		}
		$ok = $this->anchors->deliver($anchor);
		$output->writeln($ok
			? "<info>Anchored seq {$anchor->getSeq()}</info>"
			: "<comment>Anchor pending (TSA unreachable or unconfigured) — will retry via cron.</comment>");
		return $ok ? 0 : 2;
	}
}
