<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Incident extends Entity
{
	protected $siteId;
	protected $roundId;
	protected $checkpointId;
	protected $eventId;
	protected $clientIncidentId;
	protected $category;
	protected $severity;
	protected $title;
	protected $note;
	protected $photoFileid;
	protected $audioFileid;
	protected $createdBy;
	protected $memberId;
	protected $status;
	protected $revision;
	protected $woRef;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('roundId', 'integer');
		$this->addType('checkpointId', 'integer');
		$this->addType('eventId', 'integer');
		$this->addType('photoFileid', 'integer');
		$this->addType('audioFileid', 'integer');
		$this->addType('memberId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('woRef', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'roundId' => (int)$this->roundId,
			'checkpointId' => (int)$this->checkpointId,
			'eventId' => (int)$this->eventId,
			'clientIncidentId' => $this->clientIncidentId,
			'category' => $this->category,
			'severity' => $this->severity,
			'title' => $this->title,
			'note' => $this->note,
			'photoFileid' => (int)$this->photoFileid,
			'audioFileid' => (int)($this->audioFileid ?? 0),
			'createdBy' => $this->createdBy,
			'memberId' => (int)$this->memberId,
			'status' => $this->status,
			'revision' => (int)$this->revision,
			'woRef' => $this->woRef,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}