<?php

declare(strict_types=1);

namespace OCA\TourCheck;

use OCP\Capabilities\IPublicCapability;
use OCP\IURLGenerator;

/**
 * Suite flange advertisement (F11): MobilityCheck capability-detects
 * `check.drive-segments` before pulling. Versioned so the contract can
 * evolve without silent consumer breakage.
 */
class Capabilities implements IPublicCapability
{
	public function __construct(private IURLGenerator $urlGenerator)
	{
	}

	public function getCapabilities(): array
	{
		return [
			'check' => [
				'drive-segments' => [
					'version' => 1,
					// linkToRoute keeps webroot + /index.php correct for consumers.
					'endpoint' => $this->urlGenerator->linkToRoute('tourcheck.api.driveSegments'),
				],
			],
		];
	}
}
