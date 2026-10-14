<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Geofence extends Entity
{
	protected $siteId;
	protected $kind;
	protected $shape;
	protected $centerLat;
	protected $centerLon;
	protected $radiusM;
	protected $polygonJson;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('centerLat', 'float');
		$this->addType('centerLon', 'float');
		$this->addType('radiusM', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'kind' => $this->kind,
			'shape' => $this->shape,
			'centerLat' => $this->centerLat === null ? null : (float)$this->centerLat,
			'centerLon' => $this->centerLon === null ? null : (float)$this->centerLon,
			'radiusM' => $this->radiusM === null ? null : (int)$this->radiusM,
			'polygon' => $this->polygonJson === null ? null : json_decode((string)$this->polygonJson, true),
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}
