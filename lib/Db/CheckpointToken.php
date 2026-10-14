<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class CheckpointToken extends Entity
{
	protected $checkpointId;
	protected $kind;
	protected $tokenHash;
	protected $createdAt;
	protected $revokedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('checkpointId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('revokedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'checkpointId' => (int)$this->checkpointId,
			'kind' => $this->kind,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'revokedAt' => $this->revokedAt === null ? null : $this->revokedAt->format(\DateTimeInterface::ATOM),
		];
	}
}