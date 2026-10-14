<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Service\CsvExportBuilder;
use Test\TestCase;

final class CsvExportBuilderTest extends TestCase
{
	public function testBomAndSemicolonDelimiter(): void
	{
		$out = CsvExportBuilder::build(['a', 'b'], [['1', '2']]);
		self::assertStringStartsWith("\xEF\xBB\xBF", $out);
		self::assertStringContainsString("a;b\n", $out);
	}

	/** @return array<string,array{0:string}> */
	public static function formulaPrefixes(): array
	{
		return [
			'equals' => ['=1+2'],
			'plus' => ['+SUM(A1)'],
			'minus' => ['-2-3'],
			'at' => ['@cmd'],
			'tab' => ["\tcmd"],
			'cr' => ["\rcmd"],
			'vt' => ["\x0Bcmd"],
			'dde' => ['=cmd|"/c calc"!A1'],
		];
	}

	/** @dataProvider formulaPrefixes */
	public function testFormulaInjectionIsNeutralized(string $cell): void
	{
		$out = CsvExportBuilder::build(['h'], [[$cell]]);
		$line = explode("\n", substr($out, 3))[1];
		self::assertMatchesRegularExpression('/^"?\'/', $line, "cell not neutralized");
	}

	public function testQuotingEscapesDelimiterAndQuotes(): void
	{
		$out = CsvExportBuilder::build(['h'], [['say "hi"; ok']]);
		self::assertStringContainsString('"say ""hi""; ok"', $out);
	}

	public function testNewlinesForceQuoting(): void
	{
		$out = CsvExportBuilder::build(['h'], [["a\nb"]]);
		self::assertStringContainsString("\"a\nb\"", $out);
	}
}
