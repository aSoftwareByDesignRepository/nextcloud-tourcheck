<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Notif extends Entity
{
	protected $dedupeKey;
	protected $sentAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('sentAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'dedupeKey' => $this->dedupeKey,
			'sentAt' => $this->sentAt === null ? null : $this->sentAt->format(\DateTimeInterface::ATOM),
		];
	}
}