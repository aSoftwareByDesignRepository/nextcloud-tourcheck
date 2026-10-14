<?php

declare(strict_types=1);

namespace OCA\TourCheck;

use OCP\Capabilities\IPublicCapability;

/**
 * Suite flange advertisement (F11): MobilityCheck capability-detects
 * `check.drive-segments` before pulling. Versioned so the contract can
 * evolve without silent consumer breakage.
 */
class Capabilities implements IPublicCapability
{
	public function getCapabilities(): array
	{
		return [
			'check' => [
				'drive-segments' => [
					'version' => 1,
					'endpoint' => '/apps/tourcheck/api/drive-segments',
				],
			],
		];
	}
}
