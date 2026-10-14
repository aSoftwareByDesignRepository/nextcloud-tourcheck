<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

/**
 * UTF-8 BOM + semicolon CSV (DACH payroll import friendly).
 */
final class CsvExportBuilder
{
	/**
	 * @param list<string> $headers
	 * @param list<list<string|int|float>> $rows
	 * @param list<list<string|int|float>> $meta  optional key;value preamble (NN-13 evidence lines)
	 */
	public static function build(array $headers, array $rows, array $meta = []): string
	{
		$buf = "\xEF\xBB\xBF";
		foreach ($meta as $m) {
			$buf .= self::row($m);
		}
		$buf .= self::row($headers);
		foreach ($rows as $row) {
			$buf .= self::row($row);
		}
		return $buf;
	}

	/**
	 * Neutralize spreadsheet formula injection (= + - @ | tab) per OWASP CSV guidance.
	 */
	public static function neutralizeFormula(string $value): string
	{
		if ($value === '') {
			return $value;
		}
		$first = $value[0];
		if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r" || $first === "\x0B") {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * @param list<string|int|float> $cells
	 */
	private static function row(array $cells): string
	{
		$escaped = [];
		foreach ($cells as $cell) {
			$s = self::neutralizeFormula((string)$cell);
			$s = str_replace('"', '""', $s);
			if (str_contains($s, ';') || str_contains($s, '"') || str_contains($s, "\n") || str_contains($s, "\r")) {
				$s = '"' . $s . '"';
			}
			$escaped[] = $s;
		}
		return implode(';', $escaped) . "\n";
	}
}
