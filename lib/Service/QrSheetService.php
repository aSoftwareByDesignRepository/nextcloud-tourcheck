<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use splitbrain\phpQRCode\QRCode;

/**
 * Printable QR for checkpoint tokens — same-origin inline SVG only, never a
 * third-party QR API (evidence payloads must not leave the instance).
 */
final class QrSheetService
{
	public function svg(string $payload): string
	{
		return QRCode::svg($payload, ['s' => 'qrm']);
	}
}
