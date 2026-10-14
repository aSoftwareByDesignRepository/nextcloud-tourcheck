<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the NH hardening paths (no Infection needed).
 * Each mutant must be KILLED by the unit suite — a surviving mutant means
 * the tests cannot see the defect.
 *
 * Usage (host, from repo nextcloud/apps/tourcheck/):
 *   php tests/Mutation/run-nh-hardening-mutations.php
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

function targets(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Controller/ApiJsonTrait.php',
			'filter' => 'ApiJsonTraitTest',
			'mutations' => [
				// Lock-contention must be a retryable 409 — a dead branch
				// drops it into the opaque 500 bucket instead.
				'locked_branch_dead' => [
					'from' => 'if ($e instanceof \\OCP\\Lock\\LockedException) {',
					'to' => 'if (false) {',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/KeyService.php',
			'filter' => 'KeyServiceTest',
			'mutations' => [
				// A losing CAS that never throws = double custody.
				'take_cas_ignored' => [
					'from' => 'if (!$this->keys->atomicTake($keyId, $holderUid, $holderMemberId, $this->timeFactory->getDateTime())) {',
					'to' => 'if (false && !$this->keys->atomicTake($keyId, $holderUid, $holderMemberId, $this->timeFactory->getDateTime())) {',
				],
				// Ghost uid accepted = stale-member hole in the register.
				'ghost_uid_accepted' => [
					'from' => 'if (!$this->userManager->userExists($holderUid)',
					'to' => 'if (false && !$this->userManager->userExists($holderUid)',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/MissedService.php',
			'filter' => 'DeadmanSweepTest',
			'mutations' => [
				// Interval disabled check removed = every plan escalates.
				'interval_gate_removed' => [
					'from' => 'if ($interval <= 0) {',
					'to' => 'if (false && $interval <= 0) {',
				],
				// Strike always 1 = escalation never progresses past level 1.
				'strike_never_escalates' => [
					'from' => '$strikes = intdiv(max(0, $elapsed), $interval * 60);',
					'to' => '$strikes = min(1, intdiv(max(0, $elapsed), $interval * 60));',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/RoundService.php',
			'filter' => 'RoundScopeTest',
			'mutations' => [
				// Unrestricted for everyone = the listRounds BOLA regresses.
				'unrestricted_for_all' => [
					'from' => '$unrestricted = $siteIds === [] && ($this->acl->isAppAdmin($uid) || $this->acl->canPlan($uid));',
					'to' => '$unrestricted = true;',
				],
				// Dropping the scope = unscoped enumeration for members.
				'scope_dropped' => [
					'from' => 'return $this->todayFor($siteIds, $unrestricted);',
					'to' => 'return $this->todayFor([], $unrestricted);',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/StationService.php',
			'filter' => 'StationLockoutTest',
			'mutations' => [
				// Roster without the manage gate = member-id enumeration.
				'manager_roster_acl_dropped' => [
					'from' => "		\$this->acl->assertCanManageSite((int)\$s->getSiteId());\n		return \$this->members->findForStation(\$stationId);",
					'to' => "		return \$this->members->findForStation(\$stationId);",
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/AccessControlService.php',
			'filter' => 'AccessControlScopeTest',
			'mutations' => [
				// Gate denied for everyone = managers lose the uid picker.
				'manage_any_false' => [
					'from' => 'public function canManageAnySite(string $uid): bool
	{
		return $this->canPlan($uid);',
					'to' => 'public function canManageAnySite(string $uid): bool
	{
		return false;',
				],
				// Gate open for everyone = workers enumerate all NC users.
				'manage_any_true' => [
					'from' => 'public function canManageAnySite(string $uid): bool
	{
		return $this->canPlan($uid);',
					'to' => 'public function canManageAnySite(string $uid): bool
	{
		return true;',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/EventService.php',
			'filter' => 'ThresholdIncidentTest',
			'mutations' => [
				// min-bound removed = under-range values slip through.
				'min_bound_removed' => [
					'from' => 'if ((isset($item[\'min\']) && $v < (float)$item[\'min\']) || (isset($item[\'max\']) && $v > (float)$item[\'max\'])) {',
					'to' => 'if ((isset($item[\'max\']) && $v > (float)$item[\'max\'])) {',
				],
			],
		],
	];
}

$fail = 0;
$killed = 0;
foreach (targets($appRoot) as $t) {
	$src = file_get_contents($t['file']);
	foreach ($t['mutations'] as $name => $m) {
		if (!str_contains($src, $m['from'])) {
			echo "SKIP  {$name}: anchor not found in {$t['file']}\n";
			$fail++;
			continue;
		}
		if (file_put_contents($t['file'], str_replace($m['from'], $m['to'], $src)) === false) {
			fwrite(STDERR, "MUTANT WRITE FAILED: {$name} — fix file perms, do not trust survivors\n");
			$fail++;
			continue;
		}
		$code = run_unit_tests($appRoot, $workspaceRoot, $appId, $t['filter']);
		file_put_contents($t['file'], $src);
		if ($code === 0) {
			echo "SURVIVED {$name} ({$t['file']})\n";
			$fail++;
		} else {
			echo "KILLED   {$name}\n";
			$killed++;
		}
	}
}
echo "---\n{$killed} killed, {$fail} survived/skipped\n";
exit($fail === 0 ? 0 : 1);
