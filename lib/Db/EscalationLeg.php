<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class EscalationLeg extends Entity
{
	protected $siteId;
	protected $legNo;
	protected $delayMin;
	protected $channel;
	protected $targetJson;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('legNo', 'integer');
		$this->addType('delayMin', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'legNo' => (int)$this->legNo,
			'delayMin' => (int)$this->delayMin,
			'channel' => $this->channel,
			'target' => $this->targetJson === null ? null : json_decode((string)$this->targetJson, true),
		];
	}
}
