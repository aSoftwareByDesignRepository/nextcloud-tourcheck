<?php

declare(strict_types=1);

namespace OCA\TourCheck\Config;

use OCP\IConfig;

class InstanceId
{
	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function get(): string
	{
		$id = trim($this->config->getSystemValueString('instanceid', ''));
		return $id !== '' ? $id : 'unknown-instance';
	}
}
