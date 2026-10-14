<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

/**
 * Minimal PDF 1.4 builder (no external deps) for My-month statements.
 * Standard 14 fonts only (Helvetica / Helvetica-Bold) with WinAnsi encoding.
 */
final class SimplePdfBuilder
{
	private const PAGE_W = 595.28;
	private const PAGE_H = 841.89;
	private const MARGIN_L = 48.0;
	private const MARGIN_R = 48.0;
	private const MARGIN_T = 52.0;
	private const MARGIN_B = 56.0;

	/**
	 * Simple multi-page text document (kept for callers/tests).
	 *
	 * @param list<string> $lines
	 */
	public static function fromLines(string $title, array $lines): string
	{
		$rows = [];
		foreach ($lines as $line) {
			$rows[] = [(string)$line];
		}
		return self::buildStatement([
			'brand' => 'TourCheck',
			'title' => $title,
			'columns' => ['Details'],
			'colWidths' => [1.0],
			'rows' => $rows,
			'totals' => [],
			'note' => '',
		]);
	}

	/**
	 * Payroll-style statement: header, summary, item table, totals (multi-page).
	 *
	 * @param array{
	 *   brand?: string,
	 *   title: string,
	 *   meta?: list<array{0:string,1:string}>,
	 *   keyFigure?: array{label:string,value:string,details?:list<string>},
	 *   breakdown?: list<array{label:string,value:string}>,
	 *   totalLine?: array{label:string,value:string},
	 *   emptyItemsText?: string,
	 *   summary?: list<array{label:string,value:string,strong?:bool}>,
	 *   tableTitle?: string,
	 *   columns?: list<string>,
	 *   colWidths?: list<float>,
	 *   rows?: list<list<string>>,
	 *   totals?: list<array{label:string,value:string,strong?:bool}>,
	 *   note?: string,
	 * } $doc
	 */
	public static function buildStatement(array $doc): string
	{
		$brand = trim((string)($doc['brand'] ?? 'TourCheck'));
		$title = trim((string)($doc['title'] ?? 'Statement'));
		/** @var list<array{0:string,1:string}> $meta */
		$meta = array_values($doc['meta'] ?? []);
		/** @var array{label:string,value:string,details?:list<string>}|null $keyFigure */
		$keyFigure = isset($doc['keyFigure']) && is_array($doc['keyFigure']) ? $doc['keyFigure'] : null;
		/** @var list<array{label:string,value:string}> $breakdown */
		$breakdown = array_values($doc['breakdown'] ?? []);
		/** @var array{label:string,value:string}|null $totalLine */
		$totalLine = isset($doc['totalLine']) && is_array($doc['totalLine']) ? $doc['totalLine'] : null;
		$emptyItemsText = trim((string)($doc['emptyItemsText'] ?? 'No items in this period.'));
		/** @var list<array{label:string,value:string,strong?:bool}> $summary */
		$summary = array_values($doc['summary'] ?? []);
		$tableTitle = trim((string)($doc['tableTitle'] ?? 'Items'));
		/** @var list<string> $columns */
		$columns = array_values($doc['columns'] ?? ['Item']);
		/** @var list<float> $colWidths */
		$colWidths = array_values($doc['colWidths'] ?? [1.0]);
		/** @var list<list<string>> $rows */
		$rows = array_values($doc['rows'] ?? []);
		/** @var list<array{label:string,value:string,strong?:bool}> $totals */
		$totals = array_values($doc['totals'] ?? []);
		$note = trim((string)($doc['note'] ?? ''));

		if ($columns === []) {
			$columns = ['Item'];
			$colWidths = [1.0];
		}
		if (count($colWidths) !== count($columns)) {
			$colWidths = array_fill(0, count($columns), 1.0 / max(1, count($columns)));
		}
		$widthSum = array_sum($colWidths);
		if ($widthSum <= 0.0) {
			$colWidths = array_fill(0, count($columns), 1.0 / count($columns));
		} else {
			$colWidths = array_map(static fn (float $w): float => $w / $widthSum, $colWidths);
		}

		$contentWidth = self::PAGE_W - self::MARGIN_L - self::MARGIN_R;
		$state = [
			'ops' => '',
			'y' => self::PAGE_H - self::MARGIN_T,
			'pages' => [],
			'fresh' => true,
		];

		$flush = static function () use (&$state): void {
			$state['pages'][] = $state['ops'];
			$state['ops'] = '';
			$state['y'] = self::PAGE_H - self::MARGIN_T;
			$state['fresh'] = true;
		};

		$ensure = static function (float $need) use (&$state, $flush): void {
			if ($state['y'] - $need < self::MARGIN_B) {
				$flush();
			}
		};

		$cellPad = 4.0;

		$drawTableHeader = static function () use (&$state, $columns, $colWidths, $contentWidth, $cellPad): void {
			$rowTop = $state['y'] + 4.0;
			$state['ops'] .= self::fillRect(self::MARGIN_L, $rowTop - 13.0, $contentWidth, 16.0, 0.93);
			$x = self::MARGIN_L;
			foreach ($columns as $i => $col) {
				$w = $contentWidth * $colWidths[$i];
				$alignRight = self::columnAlignsRight($col);
				$label = self::clip($col, (int)max(6, floor(($w - ($cellPad * 2.0)) / 5.8)));
				if ($alignRight) {
					$state['ops'] .= self::textRight($x + $w - $cellPad, $state['y'], $label, 9, true);
				} else {
					$state['ops'] .= self::textAt($x + $cellPad, $state['y'], $label, 9, true);
				}
				$x += $w;
			}
			$state['y'] -= 6.0;
			$state['ops'] .= self::hline($state['y']);
			$state['y'] -= 14.0;
			$state['fresh'] = false;
		};

		// Header band + titles
		$state['ops'] .= self::drawHeaderBand();
		$state['ops'] .= self::textAt(self::MARGIN_L, $state['y'], $brand !== '' ? $brand : 'TourCheck', 18, true);
		$state['y'] -= 22.0;
		$state['ops'] .= self::textAt(self::MARGIN_L, $state['y'], $title, 13, false);
		$state['y'] -= 10.0;
		$state['ops'] .= self::hline($state['y']);
		$state['y'] -= 18.0;
		$state['fresh'] = false;

		foreach ($meta as $pair) {
			$ensure(16.0);
			$label = self::clip((string)$pair[0], 24);
			$value = self::clip((string)$pair[1], 72);
			$state['ops'] .= self::textAt(self::MARGIN_L, $state['y'], $label . ':', 10, true);
			$state['ops'] .= self::textAt(self::MARGIN_L + 88.0, $state['y'], $value, 10, false);
			$state['y'] -= 14.0;
		}
		if ($meta !== []) {
			$state['y'] -= 4.0;
		}

		if ($keyFigure !== null && trim((string)($keyFigure['label'] ?? '')) !== '') {
			$boxRows = $breakdown;
			$rowCount = count($boxRows) + ($totalLine !== null ? 1 : 0);
			$boxH = 56.0 + ($rowCount > 0 ? 8.0 + ($rowCount * 15.0) + 6.0 : 0.0);
			$ensure($boxH + 16.0);
			$boxBottom = $state['y'] - $boxH + 8.0;
			$state['ops'] .= self::fillRect(self::MARGIN_L, $boxBottom, $contentWidth, $boxH, 0.97);
			$state['ops'] .= self::strokeRect(self::MARGIN_L, $boxBottom, $contentWidth, $boxH);
			$state['ops'] .= self::textAt(
				self::MARGIN_L + 14.0,
				$state['y'] - 2.0,
				self::clip((string)$keyFigure['label'], 48),
				9,
				true
			);
			$state['ops'] .= self::textRight(
				self::MARGIN_L + $contentWidth - 14.0,
				$state['y'] - 20.0,
				self::clip((string)($keyFigure['value'] ?? ''), 24),
				22,
				true
			);
			$state['y'] -= 34.0;
			if ($boxRows !== []) {
				$state['ops'] .= self::hline($state['y'], 0.78);
				$state['y'] -= 14.0;
			}
			foreach ($boxRows as $row) {
				$state['ops'] .= self::textAt(
					self::MARGIN_L + 14.0,
					$state['y'],
					self::clip((string)$row['label'], 44),
					10,
					false
				);
				$state['ops'] .= self::textRight(
					self::MARGIN_L + $contentWidth - 14.0,
					$state['y'],
					self::clip((string)$row['value'], 24),
					10,
					false
				);
				$state['y'] -= 15.0;
			}
			if ($totalLine !== null) {
				$state['y'] -= 2.0;
				$state['ops'] .= self::hline($state['y'], 0.55);
				$state['y'] -= 14.0;
				$state['ops'] .= self::textAt(
					self::MARGIN_L + 14.0,
					$state['y'],
					self::clip((string)$totalLine['label'], 44),
					10,
					true
				);
				$state['ops'] .= self::textRight(
					self::MARGIN_L + $contentWidth - 14.0,
					$state['y'],
					self::clip((string)$totalLine['value'], 24),
					11,
					true
				);
				$state['y'] -= 12.0;
			}
			$state['y'] -= 14.0;
		}

		if ($summary !== [] && $keyFigure === null) {
			$cardH = 16.0 + (count($summary) * 16.0) + 10.0;
			$ensure($cardH + 8.0);
			$state['ops'] .= self::fillRect(self::MARGIN_L, $state['y'] - $cardH + 8.0, $contentWidth, $cardH, 0.94);
			$state['ops'] .= self::strokeRect(self::MARGIN_L, $state['y'] - $cardH + 8.0, $contentWidth, $cardH);
			$state['ops'] .= self::textAt(self::MARGIN_L + 12.0, $state['y'] - 4.0, 'Summary', 10, true);
			$state['y'] -= 20.0;
			foreach ($summary as $row) {
				$strong = !empty($row['strong']);
				$size = $strong ? 12 : 10;
				$state['ops'] .= self::textAt(
					self::MARGIN_L + 12.0,
					$state['y'],
					self::clip((string)$row['label'], 40),
					$size,
					true
				);
				$state['ops'] .= self::textRight(
					self::MARGIN_L + $contentWidth - 12.0,
					$state['y'],
					self::clip((string)$row['value'], 28),
					$size,
					$strong
				);
				$state['y'] -= $strong ? 18.0 : 15.0;
			}
			$state['y'] -= 14.0;
		}

		$ensure(36.0);
		$state['ops'] .= self::textAt(self::MARGIN_L, $state['y'], $tableTitle, 11, true);
		$state['y'] -= 10.0;
		$drawTableHeader();

		if ($rows === []) {
			$ensure(16.0);
			$state['ops'] .= self::textAt(self::MARGIN_L, $state['y'], self::clip($emptyItemsText, 90), 10, false);
			$state['y'] -= 18.0;
		} else {
			foreach ($rows as $row) {
				$ensure(16.0);
				if ($state['fresh']) {
					$state['ops'] .= self::drawHeaderBand();
					$state['ops'] .= self::textAt(
						self::MARGIN_L,
						$state['y'],
						self::clip(($brand !== '' ? $brand . ' — ' : '') . $title, 70),
						10,
						true
					);
					$state['y'] -= 16.0;
					$state['ops'] .= self::textAt(self::MARGIN_L, $state['y'], $tableTitle . ' (continued)', 10, true);
					$state['y'] -= 14.0;
					$drawTableHeader();
				}
				$x = self::MARGIN_L;
				$cellCount = count($columns);
				for ($i = 0; $i < $cellCount; $i++) {
					$w = $contentWidth * $colWidths[$i];
					$raw = (string)($row[$i] ?? '');
					$maxChars = (int)max(4, floor(($w - ($cellPad * 2.0)) / 5.2));
					$cell = self::clip($raw, $maxChars);
					$alignRight = self::columnAlignsRight($columns[$i]);
					if ($alignRight) {
						$state['ops'] .= self::textRight($x + $w - $cellPad, $state['y'], $cell, 10, false);
					} else {
						$state['ops'] .= self::textAt($x + $cellPad, $state['y'], $cell, 10, false);
					}
					$x += $w;
				}
				$state['y'] -= 6.0;
				$state['ops'] .= self::hline($state['y'], 0.82);
				$state['y'] -= 10.0;
				$state['fresh'] = false;
			}
		}

		if ($totals !== [] && $keyFigure === null) {
			$blockH = 10.0 + (count($totals) * 16.0) + 6.0;
			$ensure($blockH + 8.0);
			$state['y'] -= 4.0;
			$state['ops'] .= self::hline($state['y']);
			$state['y'] -= 14.0;
			foreach ($totals as $row) {
				$strong = !empty($row['strong']);
				$size = $strong ? 11 : 10;
				if ($strong) {
					$state['ops'] .= self::fillRect(self::MARGIN_L, $state['y'] - 5.0, $contentWidth, 18.0, 0.94);
					$state['ops'] .= self::strokeRect(self::MARGIN_L, $state['y'] - 5.0, $contentWidth, 18.0);
				}
				$pad = $strong ? 10.0 : 0.0;
				$state['ops'] .= self::textAt(
					self::MARGIN_L + $pad,
					$state['y'],
					self::clip((string)$row['label'], 40),
					$size,
					true
				);
				$state['ops'] .= self::textRight(
					self::MARGIN_L + $contentWidth - $pad,
					$state['y'],
					self::clip((string)$row['value'], 28),
					$size,
					true
				);
				$state['y'] -= $strong ? 20.0 : 14.0;
			}
		}

		if ($note !== '') {
			$state['y'] -= 8.0;
			foreach (self::wrap($note, 88) as $noteLine) {
				$ensure(13.0);
				$state['ops'] .= self::textAt(self::MARGIN_L, $state['y'], $noteLine, 9, false);
				$state['y'] -= 12.0;
			}
		}

		$state['pages'][] = $state['ops'];
		$pageCount = count($state['pages']);
		for ($i = 0; $i < $pageCount; $i++) {
			$state['pages'][$i] .= self::footer($i + 1, $pageCount);
		}

		return self::assemble($state['pages']);
	}

	private static function drawHeaderBand(): string
	{
		return self::fillRect(0.0, self::PAGE_H - 18.0, self::PAGE_W, 18.0, 0.18);
	}

	private static function footer(int $page, int $of): string
	{
		$y = 28.0;
		return self::hline(36.0)
			. self::textAt(self::MARGIN_L, $y, 'TourCheck', 8, false)
			. self::textRight(self::PAGE_W - self::MARGIN_R, $y, 'Page ' . $page . ' of ' . $of, 8, false);
	}

	/**
	 * @param list<string> $pageContents
	 */
	private static function assemble(array $pageContents): string
	{
		if ($pageContents === []) {
			$pageContents = [''];
		}
		$n = count($pageContents);
		$catalogId = 1;
		$pagesId = 2;
		$firstPageId = 3;
		$firstContentId = 3 + $n;
		$fontRegularId = 3 + (2 * $n);
		$fontBoldId = $fontRegularId + 1;

		$kids = [];
		for ($i = 0; $i < $n; $i++) {
			$kids[] = ($firstPageId + $i) . ' 0 R';
		}

		$objects = [];
		$objects[$catalogId] = "<< /Type /Catalog /Pages $pagesId 0 R >>";
		$objects[$pagesId] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $n >>";
		for ($i = 0; $i < $n; $i++) {
			$pageId = $firstPageId + $i;
			$contentId = $firstContentId + $i;
			$objects[$pageId] = sprintf(
				'<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> >>',
				$pagesId,
				self::PAGE_W,
				self::PAGE_H,
				$contentId,
				$fontRegularId,
				$fontBoldId
			);
			$stream = $pageContents[$i];
			$objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
		}
		$objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		$pdf = "%PDF-1.4\n";
		$offsets = [0];
		$maxId = $fontBoldId;
		for ($id = 1; $id <= $maxId; $id++) {
			$offsets[$id] = strlen($pdf);
			$pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
		}
		$xref = strlen($pdf);
		$pdf .= 'xref' . "\n0 " . ($maxId + 1) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ($id = 1; $id <= $maxId; $id++) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
		}
		$pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root $catalogId 0 R >>\n";
		$pdf .= "startxref\n$xref\n%%EOF\n";
		return $pdf;
	}

	private static function textAt(float $x, float $y, string $text, int $size, bool $bold): string
	{
		$font = $bold ? '/F2' : '/F1';
		return sprintf(
			"BT %s %d Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
			$font,
			$size,
			$x,
			$y,
			self::esc($text)
		);
	}

	private static function textRight(float $rightX, float $y, string $text, int $size, bool $bold): string
	{
		$width = self::estimateWidth($text, $size, $bold);
		return self::textAt($rightX - $width, $y, $text, $size, $bold);
	}

	private static function estimateWidth(string $text, int $size, bool $bold): float
	{
		$factor = $bold ? 0.55 : 0.50;
		return strlen(self::toLatin1($text)) * $size * $factor;
	}

	private static function columnAlignsRight(string $column): bool
	{
		$key = strtolower(trim($column));
		return in_array($key, ['qty', 'amount', 'total'], true);
	}

	private static function hline(float $y, float $gray = 0.65): string
	{
		$x2 = self::PAGE_W - self::MARGIN_R;
		return sprintf("%.2F G %.2F %.2F m %.2F %.2F l S 0 G\n", $gray, self::MARGIN_L, $y, $x2, $y);
	}

	private static function fillRect(float $x, float $y, float $w, float $h, float $gray): string
	{
		return sprintf("%.2F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, $y, $w, $h);
	}

	private static function strokeRect(float $x, float $y, float $w, float $h): string
	{
		return sprintf("0.55 G %.2F %.2F %.2F %.2F re S 0 G\n", $x, $y, $w, $h);
	}

	/** @return list<string> */
	private static function wrap(string $text, int $width): array
	{
		$text = trim($text);
		if ($text === '') {
			return [];
		}
		$words = preg_split('/\s+/', $text) ?: [];
		$lines = [];
		$cur = '';
		foreach ($words as $word) {
			$next = $cur === '' ? $word : ($cur . ' ' . $word);
			if (strlen($next) > $width && $cur !== '') {
				$lines[] = $cur;
				$cur = $word;
			} else {
				$cur = $next;
			}
		}
		if ($cur !== '') {
			$lines[] = $cur;
		}
		return $lines;
	}

	private static function clip(string $s, int $maxChars): string
	{
		$s = trim(preg_replace("/\s+/u", ' ', $s) ?? $s);
		if ($maxChars < 1) {
			return '';
		}
		$latin = self::toLatin1($s);
		if (strlen($latin) <= $maxChars) {
			return $s;
		}
		if ($maxChars <= 1) {
			return substr($latin, 0, 1);
		}
		return rtrim(substr($latin, 0, $maxChars - 1)) . '.';
	}

	private static function toLatin1(string $s): string
	{
		$out = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
		return is_string($out) ? $out : $s;
	}

	private static function esc(string $s): string
	{
		$s = self::toLatin1($s);
		return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
	}
}
