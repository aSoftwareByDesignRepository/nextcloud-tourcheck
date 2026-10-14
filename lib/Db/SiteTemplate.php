<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class SiteTemplate extends Entity
{
	protected $tkey;
	protected $vertical;
	protected $name;
	protected $payloadJson;

	public function __construct()
	{
		$this->addType('id', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'tkey' => $this->tkey,
			'vertical' => $this->vertical,
			'name' => $this->name,
			'payloadJson' => $this->payloadJson,
		];
	}
}