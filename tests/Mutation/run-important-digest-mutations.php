<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the 2026-11 important-flag / digest / customer-mail /
 * ad-hoc-round feature set (SPEC: planning/features/tourcheck/
 * important-flag-digest-customer-mail-2026-11/). Every mutant must be KILLED
 * by the unit suite — a survivor means the tests cannot see the defect.
 *
 * Usage (host, from repo nextcloud/apps/tourcheck/):
 *   php tests/Mutation/run-important-digest-mutations.php
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
			// F1: strict 'wichtig' parsing — a form-encoded "false" must
			// UNFLAG, an arbitrary truthy string must 422, never silently
			// flip state. Losing the strict gate is a data-integrity bug.
			'file' => $appRoot . '/lib/Service/IncidentService.php',
			'filter' => 'ImportantFlagMailDigestTest',
			'mutations' => [
				'important_yes_accepted' => [
					'from' => "in_array(\$v, [true, 1, '1', 'true'], true)",
					'to' => "in_array(\$v, [true, 1, '1', 'true', 'yes'], true)",
				],
				'important_string_false_flags' => [
					'from' => "in_array(\$v, [false, 0, '0', 'false'], true)",
					'to' => "in_array(\$v, [false, 0, '0'], true)",
				],
				'important_true_gate_dead' => [
					'from' => "if (in_array(\$v, [true, 1, '1', 'true'], true)) {\n\t\t\treturn 1;",
					'to' => "if (false) {\n\t\t\treturn 1;",
				],
			],
		],
		[
			// F5: ad-hoc round composition — cross-site checkpoint (IDOR),
			// archived checkpoint, empty selection, past start, unbounded
			// duration. Each guard is a distinct failure mode.
			'file' => $appRoot . '/lib/Service/RoundService.php',
			'filter' => 'ImportantFlagMailDigestTest',
			'mutations' => [
				'adhoc_empty_selection_allowed' => [
					'from' => 'if ($ids === []) {',
					'to' => 'if (false && $ids === []) {',
				],
				'adhoc_past_start_allowed' => [
					'from' => "if (\$startsAt < \$this->timeFactory->getDateTime()->sub(new \\DateInterval('PT1H'))) {",
					'to' => 'if (false) {',
				],
				'adhoc_cross_site_inverted' => [
					'from' => '(int)$cp->getSiteId() !== $siteId',
					'to' => '(int)$cp->getSiteId() === $siteId',
				],
				'adhoc_archived_allowed' => [
					'from' => '(int)$cp->getActive() !== 1',
					'to' => '(int)$cp->getActive() === 1',
				],
				'adhoc_duration_unclamped' => [
					'from' => '$durationMin = max(5, min(720, $durationMin));',
					'to' => '$durationMin = $durationMin;',
				],
			],
		],
		[
			// F3: customer mail — a bad recipient must never reach the
			// mailer, an SMTP outage must degrade to {sent:false} + journal,
			// never roll back the incident itself.
			'file' => $appRoot . '/lib/Service/EntryMailService.php',
			'filter' => 'ImportantFlagMailDigestTest',
			'mutations' => [
				'recipient_validation_removed' => [
					'from' => 'if ($to === \'\' || filter_var($to, FILTER_VALIDATE_EMAIL) === false || mb_strlen($to) > 254) {',
					'to' => 'if (false) {',
				],
				'entrymail_ratelimit_removed' => [
					'from' => "\$this->rateLimit->check('entrymail', (int)\$inc->getSiteId() . '|' . \$actorUid, 10, 600);",
					'to' => '',
				],
				'mail_failure_fatal' => [
					'from' => "return ['sent' => false, 'sent_to' => null];",
					'to' => 'throw $e;',
				],
				'sent_marker_lost' => [
					'from' => "return ['sent' => true, 'sent_to' => \$to];",
					'to' => "return ['sent' => false, 'sent_to' => null];",
				],
			],
		],
		[
			// F2: morning digest — the enabled gate, the once-per-day hour
			// gate, the quiet-morning skip, and the dedupe-coupled email leg
			// are the difference between "one morning mail" and "spam every
			// cron tick".
			'file' => $appRoot . '/lib/Cron/ImportantDigestJob.php',
			'filter' => 'ImportantFlagMailDigestTest',
			'mutations' => [
				'digest_disabled_still_fires' => [
					'from' => 'if (!$this->settings->isDigestEnabled()) {',
					'to' => 'if (false) {',
				],
				'digest_hour_gate_removed' => [
					'from' => 'if ((int)$local->format(\'G\') !== $this->settings->getDigestHour()) {',
					'to' => 'if (false) {',
				],
				'digest_quiet_morning_notifies' => [
					'from' => 'if ($total === 0) {',
					'to' => 'if (false && $total === 0) {',
				],
				'digest_mail_ignores_dedupe' => [
					'from' => 'if ($sent && $this->settings->isDigestEmailEnabled()) {',
					'to' => 'if ($this->settings->isDigestEmailEnabled()) {',
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

echo "\nAll important/digest/mail/adhoc mutations killed.\n";
exit(0);
