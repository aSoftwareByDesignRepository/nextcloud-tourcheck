<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Task extends Entity
{
	protected $siteId;
	protected $assigneeUid;
	protected $title;
	protected $body;
	protected $dueAt;
	protected $status;
	protected $evidenceJson;
	protected $revision;
	protected $createdBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('dueAt', 'datetime');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'assigneeUid' => $this->assigneeUid,
			'title' => $this->title,
			'body' => $this->body,
			'dueAt' => $this->dueAt === null ? null : $this->dueAt->format(\DateTimeInterface::ATOM),
			'status' => $this->status,
			'evidence' => $this->evidenceJson === null ? null : json_decode((string)$this->evidenceJson, true),
			'revision' => (int)$this->revision,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}
