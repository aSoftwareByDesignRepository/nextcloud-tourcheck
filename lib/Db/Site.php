<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Site extends Entity
{
	protected $name;
	protected $address;
	protected $clientContact;
	protected $logoFileid;
	protected $tz;
	protected $briefing;
	protected $livePosition;
	protected $mandownMin;
	protected $mandownPreS = 60; // schema NOT NULL DEFAULT 60 — entity must never emit NULL
	protected $active;
	protected $revision;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('logoFileid', 'integer');
		$this->addType('livePosition', 'integer');
		$this->addType('mandownMin', 'integer');
		$this->addType('mandownPreS', 'integer');
		$this->addType('active', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'name' => $this->name,
			'address' => $this->address,
			'clientContact' => $this->clientContact,
			'logoFileid' => (int)$this->logoFileid,
			'tz' => $this->tz,
			'briefing' => $this->briefing,
			'livePosition' => (int)$this->livePosition,
			'mandownMin' => $this->mandownMin === null ? null : (int)$this->mandownMin,
			'mandownPreS' => (int)$this->mandownPreS,
			'active' => (int)$this->active,
			'revision' => (int)$this->revision,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}