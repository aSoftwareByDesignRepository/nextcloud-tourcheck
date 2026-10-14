<?php

declare(strict_types=1);

namespace OCA\TourCheck\Command;

use OCA\TourCheck\Service\ChainService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** occ tourcheck:verify-chain <siteId> — the tamper drill's entry point. */
class VerifyChainCommand extends Command
{
	public function __construct(
		private readonly ChainService $chain,
		private readonly IDBConnection $db,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('tourcheck:verify-chain')
			->setDescription('Verify the journal hash chain for a site (or all sites with --all)')
			->addArgument('siteId', InputArgument::OPTIONAL, 'Site id')
			->addOption('all', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Verify every site');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$siteIds = [];
		if ($input->getOption('all')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('tck_site');
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$siteIds[] = (int)$row['id'];
			}
			$res->closeCursor();
		} else {
			$id = (int)$input->getArgument('siteId');
			if ($id <= 0) {
				$output->writeln('<error>Usage: tourcheck:verify-chain <siteId> or --all</error>');
				return 1;
			}
			$siteIds[] = $id;
		}
		$rc = 0;
		foreach ($siteIds as $siteId) {
			$r = $this->chain->verify($siteId);
			if ($r['ok']) {
				$output->writeln("<info>site {$siteId}: OK — {$r['entries']} entries, head {$r['head']}</info>");
			} else {
				$rc = 2;
				$output->writeln("<error>site {$siteId}: BROKEN — {$r['reason']} at seq " . ($r['break_at_seq'] ?? $r['gap_at_seq']) . "</error>");
			}
		}
		return $rc;
	}
}
