#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerate l10n/*.js from l10n/*.json (Nextcloud OC.L10N.register format).
 *
 * Usage: php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';
$files = glob($base . '/*.json') ?: [];
sort($files);
if ($files === []) {
	fwrite(STDERR, "No l10n catalogs found\n");
	exit(1);
}

foreach ($files as $jsonPath) {
	$lang = basename($jsonPath, '.json');
	$jsPath = $base . '/' . $lang . '.js';
	$cat = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
	$translations = $cat['translations'] ?? [];
	$pluralForm = $cat['pluralForm'] ?? null;
	if (!is_string($pluralForm) || $pluralForm === '') {
		$pluralForm = null; // sibling format: no third arg when absent
	}

	$lines = ["OC.L10N.register(\n", "\t\"tourcheck\",\n", "\t{\n"];
	foreach ($translations as $key => $val) {
		$lines[] = "\t" . json_encode((string)$key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			. " : " . json_encode((string)$val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ",\n";
	}
	// trim trailing comma on last entry
	$last = count($lines) - 1;
	$lines[$last] = rtrim($lines[$last], ",\n") . "\n";
	if ($pluralForm !== null) {
		$lines[] = "\t},\n\t" . json_encode($pluralForm) . "\n);\n";
	} else {
		$lines[] = "\t}\n);\n";
	}
	file_put_contents($jsPath, implode('', $lines));
	echo "wrote $jsPath\n";
}
