<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class ClientGrant extends Entity
{
	protected $clientUid;
	protected $siteId;
	protected $createdBy;
	protected $createdAt;
	protected $revokedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('revokedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'clientUid' => $this->clientUid,
			'siteId' => (int)$this->siteId,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'revokedAt' => $this->revokedAt === null ? null : $this->revokedAt->format(\DateTimeInterface::ATOM),
		];
	}
}
