<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the evidence/security hot path (no Infection needed).
 * Each mutant must be KILLED by the unit suite — a surviving mutant means the
 * tests cannot see the defect.
 *
 * Usage (host, from repo nextcloud/apps/tourcheck/):
 *   php tests/Mutation/run-evidence-security-mutations.php
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
			'file' => $appRoot . '/lib/Service/ChainService.php',
			'filter' => 'ChainVerifyPayloadTest',
			'mutations' => [
				'payload_compare_removed' => [
					'from' => 'if (!hash_equals($actual, (string)$row->getPayloadHash())) {',
					'to' => 'if (false && !hash_equals($actual, (string)$row->getPayloadHash())) {',
				],
				'tombstone_skip_removed' => [
					'from' => 'if ((int)$ev->getTombstoned() === 1) {',
					'to' => 'if (false) {',
				],
				'prev_hash_check_removed' => [
					'from' => 'if (!hash_equals($prev, (string)$row->getPrevHash())) {',
					'to' => 'if (false && !hash_equals($prev, (string)$row->getPrevHash())) {',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/EventService.php',
			'filter' => 'EventReplayTest',
			'mutations' => [
				'replay_always_matches' => [
					'from' => 'private function replayMatches(CheckEvent $e, array $in): bool' . "\n\t{",
					'to' => 'private function replayMatches(CheckEvent $e, array $in): bool' . "\n\t{" . "\n\t\treturn true;",
				],
				'values_cap_removed' => [
					'from' => 'if (strlen((string)json_encode($values)) > 65536) {',
					'to' => 'if (strlen((string)json_encode($values)) > 99999999) {',
				],
				'claim_compare_removed' => [
					'from' => "if (\$claim !== '' && \$e->getMethod() !== \$claim) {",
					'to' => "if (false && \$claim !== '' && \$e->getMethod() !== \$claim) {",
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/EventService.php',
			'filter' => 'GeoStampTest',
			'mutations' => [
				'geo_gate_removed' => [
					'from' => 'if (!$checkpointAllows || !is_array($geo)) {',
					'to' => 'if (!is_array($geo)) {',
				],
				'geo_unbound_from_payload' => [
					'from' => "'geo_lat' => \$e->getGeoLat() === null ? '' : (string)\$e->getGeoLat(),",
					'to' => "'geo_lat' => '',",
				],
				'photo_scope_removed' => [
					'from' => '&& $this->photos !== null && !$this->photos->exists($siteId, $fileid)) {',
					'to' => '&& $this->photos !== null && $this->photos->exists($siteId, $fileid)) {',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/PhotoService.php',
			'filter' => 'PhotoStoreTest',
			'mutations' => [
				'pixel_cap_removed' => [
					'from' => 'if (($info[0] * $info[1]) > self::MAX_PIXELS) {',
					'to' => 'if (false && ($info[0] * $info[1]) > self::MAX_PIXELS) {',
				],
				'byte_cap_removed' => [
					'from' => 'if (filesize($tmpPath) > self::MAX_BYTES) {',
					'to' => 'if (false && filesize($tmpPath) > self::MAX_BYTES) {',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/StationService.php',
			'filter' => 'StationLockoutTest',
			'mutations' => [
				'station_failure_not_burned' => [
					'from' => "\t\t\t\$this->registerStationFailure(\$station, \$now);\n\t\t\tthrow new NotFoundException();",
					'to' => "\t\t\tthrow new NotFoundException();",
				],
				'station_lockout_ignored' => [
					'from' => 'if ($station->getLockoutUntil() !== null && $station->getLockoutUntil() > $now) {',
					'to' => 'if (false && $station->getLockoutUntil() !== null && $station->getLockoutUntil() > $now) {',
				],
				'pairing_entropy_20bit' => [
					'from' => '$code = bin2hex(random_bytes(8));',
					'to' => '$code = str_pad((string)random_int(0, 999999), 6, \'0\', STR_PAD_LEFT);',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/ReportService.php',
			'filter' => 'ReportSlaTest',
			'mutations' => [
				'sla_divides_by_done' => [
					'from' => "$sla = \$tot['sla_denom'] > 0 ? round((\$tot['on_time'] + \$tot['early']) / \$tot['sla_denom'] * 100, 1) : 0.0;",
					'to' => "$sla = \$tot['done'] > 0 ? round((\$tot['on_time'] + \$tot['early']) / \$tot['done'] * 100, 1) : 0.0;",
				],
				'skipped_in_denom' => [
					'from' => 'if (!$skippedRound) {',
					'to' => 'if (true) {',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/IncidentService.php',
			'filter' => 'IncidentAclTest',
			'mutations' => [
				'cross_site_round_ignored' => [
					'from' => 'if ($round === null || (int)$round->getSiteId() !== $siteId) {',
					'to' => 'if ($round === null) {',
				],
				'corrective_acl_removed' => [
					'from' => 'if (!$isAssignee && !$canSupervise) {',
					'to' => 'if (false && !$isAssignee && !$canSupervise) {',
				],
				'critical_escalation_skipped' => [
					'from' => 'if ($severity === \'critical\') {',
					'to' => 'if ($severity === \'never_critical_mutant\') {',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/RoundService.php',
			'filter' => 'RoundCloseTest',
			'mutations' => [
				'worker_close_scheduled' => [
					'from' => "if (\$round->getStatus() !== Constants::ROUND_ACTIVE) {",
					'to' => "if (false && \$round->getStatus() !== Constants::ROUND_ACTIVE) {",
				],
				'skip_terminal_relabel' => [
					'from' => "!in_array(\$status, [Constants::ROUND_SCHEDULED, Constants::ROUND_ACTIVE], true)",
					'to' => "false",
				],
				'skip_hides_recorded_work' => [
					'from' => "|| (\$status === Constants::ROUND_ACTIVE && \$this->hasAnyEvent(\$roundId))) {",
					'to' => ")) {",
				],
			],
		],
		[
			// GDPR erasure — every uid-PII leg must die when skipped, or the
			// DSR path leaks identity through a side door.
			'file' => $appRoot . '/lib/Service/ErasureService.php',
			'filter' => 'RetentionPurgeIntegrationTest',
			'mutations' => [
				'erase_attester_leg_dead' => [
					'from' => "->andWhere(\$att->expr()->eq('attester_uid', \$att->createNamedParameter(\$targetUid)));",
					'to' => "->andWhere(\$att->expr()->eq('attester_uid', \$att->createNamedParameter('__never__')));",
				],
				'erase_signature_leak' => [
					'from' => "->set('attestation_reason', \$att->createNamedParameter(null))\n\t\t\t\t->set('signature_fileid', \$att->createNamedParameter(null))",
					'to' => "->set('attestation_reason', \$att->createNamedParameter(null))",
				],
				'erase_plan_assignee_leak' => [
					'from' => "->andWhere(\$p->expr()->eq('assignee', \$p->createNamedParameter(\$targetUid)));",
					'to' => "->andWhere(\$p->expr()->eq('assignee', \$p->createNamedParameter('__never__')));",
				],
				'erase_key_holder_leak' => [
					'from' => "foreach (['actor_uid', 'holder_uid'] as \$col) {",
					'to' => "foreach (['actor_uid'] as \$col) {",
				],
				'erase_verifier_leg_dead' => [
					'from' => "\$corr->expr()->eq('verified_by', \$corr->createNamedParameter(\$targetUid)),",
					'to' => "\$corr->expr()->eq('verified_by', \$corr->createNamedParameter('__never__')),",
				],
				'erase_positions_kept' => [
					'from' => "->where(\$pos->expr()->eq('actor_uid', \$pos->createNamedParameter(\$targetUid)))",
					'to' => "->where(\$pos->expr()->eq('actor_uid', \$pos->createNamedParameter('__never__')))",
				],
			],
		],
		[
			// NH-09 privacy gates — losing ANY of these tracks workers covertly.
			'file' => $appRoot . '/lib/Service/PositionService.php',
			'filter' => 'PositionGateTest',
			'mutations' => [
				'coordinate_bounds_removed' => [
					'from' => 'if (abs($lat) > 90 || abs($lon) > 180) {',
					'to' => 'if (false && abs($lat) > 90 || abs($lon) > 180) {',
				],
				'site_acl_removed' => [
					'from' => "\t\t\$this->acl->assertCanViewSite((int)\$round->getSiteId());",
					'to' => '',
				],
				'optin_gate_removed' => [
					'from' => 'if ($site === null || (int)$site->getLivePosition() !== 1) {',
					'to' => 'if (false && ($site === null || (int)$site->getLivePosition() !== 1)) {',
				],
				'active_gate_removed' => [
					'from' => "if (\$round->getStatus() !== 'active') {",
					'to' => "if (false && \$round->getStatus() !== 'active') {",
				],
				'rate_limit_removed' => [
					'from' => "\t\t\$this->rateLimit->check('pos', \$roundId . '|' . \$uid, 1, 30);",
					'to' => '',
				],
				'accuracy_unclamped' => [
					'from' => 'max(0, (int)$accuracy)',
					'to' => '(int)$accuracy',
				],
				'trail_acl_removed' => [
					'from' => "\t\t\$this->acl->assertCanSuperviseSite((int)\$round->getSiteId());",
					'to' => '',
				],
				'ts_strict_gate_removed' => [
					'from' => 'if (preg_match(\'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/\', $clientTs) === 1) {',
					'to' => 'if (true) {',
				],
			],
		],
		[
			// NH-11 signed NFC — a live mutant here means forged or cloned
			// tags would verify.
			'file' => $appRoot . '/lib/Service/TokenService.php',
			'filter' => 'SignedNfcTest',
			'mutations' => [
				'sig_verify_removed' => [
					'from' => 'if (!$ok) {',
					'to' => 'if (false && !$ok) {',
				],
				// NB: no mutant for the empty-UID fast path — it is dead code by
				// design (an empty UID cannot satisfy the Ed25519 check anyway);
				// removing it provably cannot change behavior.
				'uid_binding_removed_from_verify' => [
					'from' => '$sig,' . "\n\t\t\t" . '\'tcks:v1:\' . self::hash($raw) . \':\' . $tagUid,',
					'to' => '$sig,' . "\n\t\t\t" . '\'tcks:v1:\' . self::hash($raw),',
				],
				'signed_shape_gate_removed' => [
					'from' => 'if (!preg_match(\'/^tcks:\\/\\/v1\\.([A-Za-z0-9_-]{20,128})\\.([A-Za-z0-9_-]{40,120})$/\', $payload, $m)) {',
					'to' => 'if (false && !preg_match(\'/^tcks:\\/\\/v1\\.([A-Za-z0-9_-]{20,128})\\.([A-Za-z0-9_-]{40,120})$/\', $payload, $m)) {',
				],
				'nonnfc_upgrade_allowed' => [
					'from' => 'if ($wasSigned && $token->getKind() !== \'nfc\') {',
					'to' => 'if (false && $wasSigned) {',
				],
			],
		],
		[
			// GA-3 token-anomaly burst thresholds + dedupe.
			'file' => $appRoot . '/lib/Service/TokenAnomalyService.php',
			'filter' => 'TokenAnomalyTest',
			'mutations' => [
				'global_burst_never_fires' => [
					'from' => 'if ($global > self::THRESHOLD) {',
					'to' => 'if (false && $global > self::THRESHOLD) {',
				],
				'per_ip_scope_removed' => [
					'from' => 'if ($ip !== null && $ip !== \'\') {',
					'to' => 'if (false && $ip !== null && $ip !== \'\') {',
				],
				'site_threshold_always_fires' => [
					'from' => 'if ($count <= self::THRESHOLD) {',
					'to' => 'if (true) {',
				],
				'emit_dedupe_removed' => [
					'from' => 'if (!$this->cache->add($emitKey, 1, self::WINDOW)) {',
					'to' => 'if (false) {',
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

echo "\nAll evidence/security mutations killed.\n";
exit(0);
