<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Persisted man-down watch state per (round, worker). Phases:
 *   watching → alarmed | closed
 * The companion drives a local pre-alarm; this row is the server-side
 * source of truth for the inactivity clock so a dead phone still alarms.
 */
class ManDownState extends Entity
{
	protected $roundId;
	protected $workerUid;
	protected $siteId;
	protected $lastActivityAt;
	protected $phase;
	protected $prealarmAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('roundId', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('lastActivityAt', 'datetime');
		$this->addType('prealarmAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'roundId' => (int)$this->roundId,
			'workerUid' => $this->workerUid,
			'siteId' => (int)$this->siteId,
			'lastActivityAt' => $this->lastActivityAt === null ? null : $this->lastActivityAt->format(\DateTimeInterface::ATOM),
			'phase' => $this->phase,
			'updatedAt' => $this->updatedAt === null ? null : $this->updatedAt->format(\DateTimeInterface::ATOM),
		];
	}
}
