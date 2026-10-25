<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the map-tile img-src derivation (no Infection
 * needed). Each mutant must be KILLED by TileProviderTest — a surviving
 * mutant means the tests cannot see a CSP regression that re-breaks
 * tile loading (the original bug: `*.host` whitelisted while tiles were
 * requested from the apex `host`, which CSP never matches).
 *
 * The PageController emission loop is e2e-covered (ops-map-tiles.spec.js
 * asserts the header and decoded tile <img>s); this runner covers the
 * pure derivation in SettingsService.
 *
 * Usage (host, from repo nextcloud/apps/tourcheck/):
 *   php tests/Mutation/run-tile-csp-mutations.php
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
			'file' => $appRoot . '/lib/Service/SettingsService.php',
			'filter' => 'TileProviderTest',
			'mutations' => [
				// Without the apex source OSM tiles are CSP-blocked again —
				// the exact regression this change fixes.
				'apex_dropped' => [
					'from' => "\t\t\$domains = [\$base];\n\t\tif (!str_starts_with(\$host, '*.') && \$url === Constants::TILE_URL_OPENSTREETMAP) {",
					'to' => "\t\t\$domains = ['https://*.' . \$host];\n\t\tif (!str_starts_with(\$host, '*.') && \$url === Constants::TILE_URL_OPENSTREETMAP) {",
				],
				// OSM serves a./b./c. mirrors — dropping the wildcard leaves
				// those requests un-whitelisted.
				'osm_wildcard_dropped' => [
					'from' => "\t\tif (!str_starts_with(\$host, '*.') && \$url === Constants::TILE_URL_OPENSTREETMAP) {\n\t\t\t\$domains[] = 'https://*.' . \$host . (\$port === null ? '' : ':' . \$port);\n\t\t}",
					'to' => '',
				],
				// A `{s}` template must emit the wildcard form — substituting
				// a literal label whitelists one subdomain only.
				's_placeholder_not_expanded' => [
					'from' => "str_replace('{s}', '*', \$url)",
					'to' => "str_replace('{s}', 's', \$url)",
				],
				// Non-default ports are part of the host-source — dropping
				// the port whitelists the wrong origin.
				'port_dropped' => [
					'from' => "\$base = 'https://' . \$host . (\$port === null ? '' : ':' . \$port);",
					'to' => "\$base = 'https://' . \$host;",
				],
				// Without leftmost-normalisation a glued label (`{s}cdn.…`)
				// emits `*cdn.example.com` — an invalid source CSP ignores.
				'wildcard_not_leftmost_normalised' => [
					'from' => "\t\tif (str_contains(\$host, '*')) {\n\t\t\t// CSP wildcards must be the leftmost label — widen to the parent\n\t\t\t// domain; a host without one cannot be expressed → fail closed.\n\t\t\t\$dot = strpos(\$host, '.', strpos(\$host, '*'));\n\t\t\tif (\$dot === false || substr(\$host, \$dot + 1) === '') {\n\t\t\t\treturn [];\n\t\t\t}\n\t\t\t\$host = '*' . substr(\$host, \$dot);\n\t\t}",
					'to' => '',
				],
				// Schematic/fail-closed must emit nothing — flipping the
				// guard would whitelist every host and emit none for real
				// providers.
				'empty_url_guard_flipped' => [
					'from' => "\t\t\$url = \$this->getTileUrl();\n\t\tif (\$url === '') {\n\t\t\treturn [];\n\t\t}",
					'to' => "\t\t\$url = \$this->getTileUrl();\n\t\tif (\$url !== '') {\n\t\t\treturn [];\n\t\t}",
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

echo "\nAll tile-CSP mutations killed.\n";
exit(0);
