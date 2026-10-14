<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class StationMember extends Entity
{
	protected $stationId;
	protected $displayName;
	protected $pinHash;
	protected $active;
	protected $failCount;
	protected $lockoutUntil;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('stationId', 'integer');
		$this->addType('active', 'integer');
		$this->addType('failCount', 'integer');
		$this->addType('lockoutUntil', 'datetime');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'stationId' => (int)$this->stationId,
			'displayName' => $this->displayName,
			'active' => (int)$this->active,
			'failCount' => (int)$this->failCount,
			'lockoutUntil' => $this->lockoutUntil === null ? null : $this->lockoutUntil->format(\DateTimeInterface::ATOM),
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}