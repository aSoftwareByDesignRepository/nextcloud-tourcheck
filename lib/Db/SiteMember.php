<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class SiteMember extends Entity
{
	protected $siteId;
	protected $uid;
	protected $role;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'uid' => $this->uid,
			'role' => $this->role,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}