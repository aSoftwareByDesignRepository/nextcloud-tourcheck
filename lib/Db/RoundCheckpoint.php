<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class RoundCheckpoint extends Entity
{
	protected $roundId;
	protected $checkpointId;
	protected $nameSnap;
	protected $sort;
	protected $earliest;
	protected $due;
	protected $latest;
	protected $itemsJson;
	protected $profileJson;
	protected $status;
	protected $eventId;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('roundId', 'integer');
		$this->addType('checkpointId', 'integer');
		$this->addType('sort', 'integer');
		$this->addType('earliest', 'datetime');
		$this->addType('due', 'datetime');
		$this->addType('latest', 'datetime');
		$this->addType('eventId', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'roundId' => (int)$this->roundId,
			'checkpointId' => (int)$this->checkpointId,
			'nameSnap' => $this->nameSnap,
			'sort' => (int)$this->sort,
			'earliest' => $this->earliest === null ? null : $this->earliest->format(\DateTimeInterface::ATOM),
			'due' => $this->due === null ? null : $this->due->format(\DateTimeInterface::ATOM),
			'latest' => $this->latest === null ? null : $this->latest->format(\DateTimeInterface::ATOM),
			'itemsJson' => $this->itemsJson,
			'profileJson' => $this->profileJson,
			'status' => $this->status,
			'eventId' => (int)$this->eventId,
		];
	}
}