<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Round extends Entity
{
	protected $planId;
	protected $siteId;
	protected $snapshotJson;
	protected $scheduledStart;
	protected $windowEnd;
	protected $status;
	protected $startedAt;
	protected $startedBy;
	protected $completedAt;
	protected $completedBy;
	protected $skipReason;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('planId', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('scheduledStart', 'datetime');
		$this->addType('windowEnd', 'datetime');
		$this->addType('startedAt', 'datetime');
		$this->addType('completedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'planId' => (int)$this->planId,
			'siteId' => (int)$this->siteId,
			'snapshotJson' => $this->snapshotJson,
			'scheduledStart' => $this->scheduledStart === null ? null : $this->scheduledStart->format(\DateTimeInterface::ATOM),
			'windowEnd' => $this->windowEnd === null ? null : $this->windowEnd->format(\DateTimeInterface::ATOM),
			'status' => $this->status,
			'startedAt' => $this->startedAt === null ? null : $this->startedAt->format(\DateTimeInterface::ATOM),
			'startedBy' => $this->startedBy,
			'completedAt' => $this->completedAt === null ? null : $this->completedAt->format(\DateTimeInterface::ATOM),
			'completedBy' => $this->completedBy,
			'skipReason' => $this->skipReason,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}