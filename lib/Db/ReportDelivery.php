<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class ReportDelivery extends Entity
{
	protected $siteId;
	protected $schedId;
	protected $period;
	protected $kind;
	protected $fileId;
	protected $status;
	protected $error;
	protected $clientVisible;
	protected $audience;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('schedId', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('clientVisible', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'schedId' => (int)$this->schedId,
			'period' => $this->period,
			'kind' => $this->kind,
			'fileId' => (int)$this->fileId,
			'status' => $this->status,
			'error' => $this->error,
			'clientVisible' => (bool)$this->clientVisible,
			'audience' => (string)$this->audience,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}