<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class LiveLink extends Entity
{
	protected $siteId;
	protected $tokenHash;
	protected $label;
	protected $expiresAt;
	protected $revokedAt;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('expiresAt', 'datetime');
		$this->addType('revokedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'label' => $this->label,
			'expiresAt' => $this->expiresAt === null ? null : $this->expiresAt->format(\DateTimeInterface::ATOM),
			'revokedAt' => $this->revokedAt === null ? null : $this->revokedAt->format(\DateTimeInterface::ATOM),
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}