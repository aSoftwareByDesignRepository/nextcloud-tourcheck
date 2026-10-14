<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the CAS/row-lock paths (no Infection needed).
 * Each mutant must be KILLED by the unit suite — a surviving mutant means
 * the tests cannot see a lost-update regression.
 *
 * Usage (host, from repo nextcloud/apps/tourcheck/):
 *   php tests/Mutation/run-cas-lock-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$appId = basename($appRoot);
$workspaceRoot = dirname($appRoot, 2);

function run_unit_tests(string $appRoot, string $workspaceRoot, string $appId, string $filter): int
{
	$inside = is_file('/var/www/html/lib/base.php');
	if ($inside) {
		$phpunit = $appRoot . '/vendor/bin/phpunit';
		$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
			. ' --do-not-cache-result --filter ' . escapeshellarg($filter);
	} else {
		$cmd = 'docker compose -f ' . escapeshellarg($workspaceRoot . '/docker-compose.yml')
			. ' exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. '/var/www/html/custom_apps/' . $appId . '/vendor/bin/phpunit '
			. '-c /var/www/html/custom_apps/' . $appId . '/phpunit.xml '
			. '--do-not-cache-result --filter ' . escapeshellarg($filter);
	}
	passthru($cmd, $code);
	return (int)$code;
}

/**
 * @return list<array{file:string, filter:string, mutations:array<string,array{from:string,to:string}>}>
 */
function targets(string $appRoot): array
{
	return [
		[
			// CAS without the row lock is a lost-update race — every mutant
			// that degrades findForUpdate to an unlocked find must die.
			'file' => $appRoot . '/lib/Service/SiteService.php',
			'filter' => 'CasUpdateLockTest',
			'mutations' => [
				'site_update_unlocked' => [
					'from' => '$site = $this->sites->findForUpdate($siteId);',
					'to' => '$site = $this->sites->find($siteId);',
				],
				'site_archive_unlocked' => [
					'from' => "\$site = \$this->sites->findForUpdate(\$siteId);\n\t\t\tif (\$site === null || (int)\$site->getActive() !== 1) {\n\t\t\t\tthrow new NotFoundException();\n\t\t\t}\n\t\t\t\$site->setActive(0);",
					'to' => "\$site = \$this->sites->find(\$siteId);\n\t\t\tif (\$site === null || (int)\$site->getActive() !== 1) {\n\t\t\t\tthrow new NotFoundException();\n\t\t\t}\n\t\t\t\$site->setActive(0);",
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/CheckpointService.php',
			'filter' => 'CasUpdateLockTest',
			'mutations' => [
				'checkpoint_update_unlocked' => [
					'from' => '$cp = $this->checkpoints->findForUpdate($checkpointId);',
					'to' => '$cp = $this->checkpoints->find($checkpointId);',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/IncidentService.php',
			'filter' => 'CasUpdateLockTest',
			'mutations' => [
				'incident_update_unlocked' => [
					'from' => '$inc = $this->incidents->findForUpdate($incidentId);',
					'to' => '$inc = $this->incidents->find($incidentId);',
				],
				// Both corrective paths share the lock pattern — replacing all
				// findForUpdate calls covers updateCorrective + verifyCorrective.
				'corrective_locks_removed' => [
					'from' => '$this->correctives->findForUpdate($correctiveId)',
					'to' => '$this->correctives->find($correctiveId)',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/ReportService.php',
			'filter' => 'ReportScheduleRaceTest',
			'mutations' => [
				// Upsert must retry as update on natural-key collision —
				// always rethrowing surfaces a raw 500 on concurrent saves.
				'upsert_collision_always_rethrows' => [
					'from' => 'if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {',
					'to' => 'if (true) {',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Cron/ReportScheduleJob.php',
			'filter' => 'ReportScheduleRaceTest',
			'mutations' => [
				// 'sent' deliveries must never be re-mailed (spec: retry only
				// the failed leg). Removing the guard double-mails clients.
				'rerun_remails_sent' => [
					'from' => "if (\$dlv->getStatus() !== 'sent') {",
					'to' => 'if (true) {',
				],
				// Without ensureDeliveryFile a crash between insert and
				// newFile leaves a permanently failed, unrecoverable row.
				'rerun_file_repair_removed' => [
					'from' => "\t\t\t\t\t\t\$dlv = \$this->reports->ensureDeliveryFile(\$dlv);",
					'to' => '',
				],
			],
		],
	];
}

$failedToKill = [];
foreach (targets($appRoot) as $target) {
	$source = $target['file'];
	if (!is_file($source)) {
		fwrite(STDERR, "Missing {$source}\n");
		exit(1);
	}
	$orig = (string)file_get_contents($source);
	$backup = $source . '.mutation-bak';

	echo "== baseline {$target['filter']} ==\n";
	if (run_unit_tests($appRoot, $workspaceRoot, $appId, $target['filter']) !== 0) {
		fwrite(STDERR, "Baseline {$target['filter']} must pass before mutation run\n");
		exit(1);
	}

	foreach ($target['mutations'] as $name => $pair) {
		echo "\n== mutation: {$name} ==\n";
		$original = (string)file_get_contents($source);
		if (!str_contains($original, $pair['from'])) {
			fwrite(STDERR, "Mutation anchor not found for {$name}\n");
			$failedToKill[] = $name . ' (anchor missing)';
			continue;
		}
		file_put_contents($backup, $original);
		// A failed write (bind-mount perms) must NEVER count as "survived" —
		// that would masquerade an infra error as a coverage gap.
		if (file_put_contents($source, str_replace($pair['from'], $pair['to'], $original)) === false) {
			fwrite(STDERR, "MUTANT WRITE FAILED: {$name} — fix file perms, do not trust survivors\n");
			$failedToKill[] = $name . ' (write failed)';
			unlink($backup);
			continue;
		}
		$code = run_unit_tests($appRoot, $workspaceRoot, $appId, $target['filter']);
		file_put_contents($source, (string)file_get_contents($backup));
		unlink($backup);
		if ($code === 0) {
			$failedToKill[] = $name;
			echo "MUTATION SURVIVED: {$name}\n";
		} else {
			echo "killed {$name}\n";
		}
	}
	if ((string)file_get_contents($source) !== $orig) {
		file_put_contents($source, $orig);
	}
}

if ($failedToKill !== []) {
	fwrite(STDERR, "Mutations not killed: " . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll CAS/lock mutations killed.\n";
exit(0);
