<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TourCheck\Tests\Unit\Support;

use OCA\TourCheck\Support\Badge;
use PHPUnit\Framework\TestCase;

final class BadgeTest extends TestCase
{
	public function testRoundStatusMap(): void
	{
		self::assertSame('success', Badge::roundStatus('completed'));
		self::assertSame('success', Badge::roundStatus('done'));
		self::assertSame('warning', Badge::roundStatus('partial'));
		self::assertSame('danger', Badge::roundStatus('missed'));
		self::assertSame('info', Badge::roundStatus('active'));
		self::assertSame('', Badge::roundStatus('scheduled'));
		self::assertSame('', Badge::roundStatus(''));
	}

	public function testIncidentMaps(): void
	{
		self::assertSame('danger', Badge::incidentSeverity('critical'));
		self::assertSame('warning', Badge::incidentSeverity('medium'));
		self::assertSame('info', Badge::incidentSeverity('low'));
		self::assertSame('danger', Badge::incidentStatus('open'));
		self::assertSame('warning', Badge::incidentStatus('action'));
		self::assertSame('info', Badge::incidentStatus('verify'));
		self::assertSame('success', Badge::incidentStatus('closed'));
	}

	public function testRenderEmitsCanonicalMarkupAndEscapes(): void
	{
		$html = Badge::render('Open', 'danger');
		self::assertStringContainsString('class="tck-badge tck-badge--danger"', $html);
		self::assertStringContainsString('tck-badge__dot', $html);
		self::assertStringContainsString('aria-hidden="true"', $html);

		$xss = Badge::render('<script>x</script>', '');
		self::assertStringNotContainsString('<script>', $xss);
		self::assertStringContainsString('class="tck-badge"', $xss);
	}
}
