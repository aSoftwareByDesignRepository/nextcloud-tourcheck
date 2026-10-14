<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class TourPlan extends Entity
{
	protected $siteId;
	protected $name;
	protected $cpsJson;
	protected $recurrenceJson;
	protected $assignee;
	protected $toleranceMin;
	protected $checkinIntervalMin;
	protected $active;
	protected $revision;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('toleranceMin', 'integer');
		$this->addType('checkinIntervalMin', 'integer');
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
			'cpsJson' => $this->cpsJson,
			'recurrenceJson' => $this->recurrenceJson,
			'assignee' => $this->assignee,
			'toleranceMin' => (int)$this->toleranceMin,
			'checkinIntervalMin' => $this->checkinIntervalMin === null ? null : (int)$this->checkinIntervalMin,
			'active' => (int)$this->active,
			'revision' => (int)$this->revision,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}