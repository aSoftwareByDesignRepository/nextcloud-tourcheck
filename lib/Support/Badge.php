<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TourCheck\Support;

/**
 * Maps domain statuses/severities onto the canonical view-components badge
 * variants (success / warning / danger / info / neutral). The leading dot is
 * rendered by the template via `<span class="tck-badge__dot">` — colour is
 * never the only cue (DESIGN-SYSTEM §3.2).
 */
final class Badge
{
	public static function roundStatus(string $status): string
	{
		return match ($status) {
			'done', 'completed', 'attested', 'ok' => 'success',
			'partial', 'due' => 'warning',
			'missed', 'failed' => 'danger',
			'active' => 'info',
			default => '',
		};
	}

	public static function incidentSeverity(string $severity): string
	{
		return match ($severity) {
			'critical', 'high' => 'danger',
			'medium' => 'warning',
			default => 'info',
		};
	}

	public static function incidentStatus(string $status): string
	{
		return match ($status) {
			'open' => 'danger',
			'action' => 'warning',
			'verify' => 'info',
			default => 'success',
		};
	}

	public static function actionStatus(string $status): string
	{
		return match ($status) {
			'open' => 'warning',
			'done' => 'info',
			default => 'success',
		};
	}

	public static function onOff(bool $on): string
	{
		return $on ? 'success' : 'warning';
	}

	/**
	 * Canonical badge markup: `<span class="tck-badge tck-badge--X">
	 * <span class="tck-badge__dot"></span>Label</span>`. The label is
	 * escaped here — templates pass raw text.
	 */
	public static function render(string $label, string $variant): string
	{
		$cls = 'tck-badge' . ($variant !== '' ? ' tck-badge--' . $variant : '');
		return '<span class="' . $cls . '"><span class="tck-badge__dot" aria-hidden="true"></span>'
			. htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
	}
}
