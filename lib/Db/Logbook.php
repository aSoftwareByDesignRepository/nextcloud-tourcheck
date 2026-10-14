<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Logbook extends Entity
{
	protected $siteId;
	protected $authorUid;
	protected $kind;
	protected $pinned;
	protected $body;
	protected $revision;
	protected $createdAt;

	/** @var list<string> acked uids — hydrated by the service, not a column */
	protected $ackedBy = [];

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('pinned', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function setAckedBy(array $uids): void
	{
		$this->ackedBy = $uids;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'authorUid' => $this->authorUid,
			'kind' => $this->kind,
			'pinned' => (int)$this->pinned,
			'body' => $this->body,
			'revision' => (int)$this->revision,
			'ackedBy' => $this->ackedBy,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}
