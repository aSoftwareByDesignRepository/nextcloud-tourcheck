<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Station extends Entity
{
	protected $siteId;
	protected $label;
	protected $checkpointId;
	protected $secretHash;
	protected $active;
	protected $expiresAt;
	protected $revokedAt;
	protected $pairingCodeHash;
	protected $pairingExpires;
	protected $failCount;
	protected $lockoutUntil;
	protected $lastSeenAt;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('active', 'integer');
		$this->addType('expiresAt', 'datetime');
		$this->addType('revokedAt', 'datetime');
		$this->addType('pairingExpires', 'datetime');
		$this->addType('failCount', 'integer');
		$this->addType('lockoutUntil', 'datetime');
		$this->addType('lastSeenAt', 'datetime');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'label' => $this->label,
			'checkpointId' => $this->checkpointId,
			'active' => (int)$this->active,
			'expiresAt' => $this->expiresAt === null ? null : $this->expiresAt->format(\DateTimeInterface::ATOM),
			'revokedAt' => $this->revokedAt === null ? null : $this->revokedAt->format(\DateTimeInterface::ATOM),
			'pairingExpires' => $this->pairingExpires === null ? null : $this->pairingExpires->format(\DateTimeInterface::ATOM),
			'lastSeenAt' => $this->lastSeenAt === null ? null : $this->lastSeenAt->format(\DateTimeInterface::ATOM),
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}