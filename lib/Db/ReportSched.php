<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class ReportSched extends Entity
{
	protected $siteId;
	protected $cadence;
	protected $recipient;
	protected $sharePolicyJson;
	protected $active;
	protected $lastPeriod;
	protected $revision;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('active', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'cadence' => $this->cadence,
			'recipient' => $this->recipient,
			'sharePolicyJson' => $this->sharePolicyJson,
			'audience' => \OCA\TourCheck\Service\ReportService::audienceOf($this),
			'active' => (int)$this->active,
			'lastPeriod' => $this->lastPeriod,
			'revision' => (int)$this->revision,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}