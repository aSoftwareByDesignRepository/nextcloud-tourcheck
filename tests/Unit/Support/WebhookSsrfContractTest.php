<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Contract: user-configured webhook delivery must never dial an
 * unverified address — platform local-address guard on, no redirects
 * into unvalidated hosts (same class as ticketcheck/bookingcheck 2026-11-08).
 */
final class WebhookSsrfContractTest extends TestCase
{
	public function testDeliveryEnablesLocalAddressGuardAndNoRedirects(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Service/WebhookService.php');

		self::assertStringContainsString(
			"'allow_local_address' => false",
			$src,
			'webhook posts must enable the Nextcloud local-address SSRF guard',
		);
		self::assertStringContainsString(
			"'allow_redirects' => false",
			$src,
			'webhook posts must not follow redirects into unvalidated hosts',
		);
	}
}
