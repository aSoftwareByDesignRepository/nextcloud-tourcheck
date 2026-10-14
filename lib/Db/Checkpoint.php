<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Checkpoint extends Entity
{
	protected $siteId;
	protected $name;
	protected $instructions;
	protected $orderHint;
	protected $profileJson;
	protected $geoLat;
	protected $geoLon;
	protected $geoRadius;
	protected $active;
	protected $revision;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('orderHint', 'integer');
		$this->addType('geoLat', 'float');
		$this->addType('geoLon', 'float');
		$this->addType('geoRadius', 'integer');
		$this->addType('active', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'name' => $this->name,
			'instructions' => $this->instructions,
			'orderHint' => (int)$this->orderHint,
			'profileJson' => $this->profileJson,
			'geoLat' => (float)$this->geoLat,
			'geoLon' => (float)$this->geoLon,
			'geoRadius' => (int)$this->geoRadius,
			'active' => (int)$this->active,
			'revision' => (int)$this->revision,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}