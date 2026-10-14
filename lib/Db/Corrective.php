<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Corrective extends Entity
{
	protected $incidentId;
	protected $text;
	protected $assigneeUid;
	protected $dueAt;
	protected $status;
	protected $resolution;
	protected $verifiedBy;
	protected $verifiedAt;
	protected $revision;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('incidentId', 'integer');
		$this->addType('dueAt', 'date');
		$this->addType('verifiedAt', 'datetime');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'incidentId' => (int)$this->incidentId,
			'text' => $this->text,
			'assigneeUid' => $this->assigneeUid,
			'dueAt' => $this->dueAt === null ? null : $this->dueAt->format(\DateTimeInterface::ATOM),
			'status' => $this->status,
			'resolution' => $this->resolution,
			'verifiedBy' => $this->verifiedBy,
			'verifiedAt' => $this->verifiedAt === null ? null : $this->verifiedAt->format(\DateTimeInterface::ATOM),
			'revision' => (int)$this->revision,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}