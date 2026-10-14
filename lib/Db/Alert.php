<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Alert extends Entity
{
	protected $type;
	protected $siteId;
	protected $workerUid;
	protected $roundId;
	protected $severity;
	protected $state;
	protected $payloadJson;
	protected $escalatedLeg;
	protected $firedAt;
	protected $ackedAt;
	protected $ackedBy;
	protected $resolvedAt;
	protected $resolvedBy;
	protected $resolutionNote;
	protected $clientEventId;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('roundId', 'integer');
		$this->addType('escalatedLeg', 'integer');
		$this->addType('firedAt', 'datetime');
		$this->addType('ackedAt', 'datetime');
		$this->addType('resolvedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'type' => $this->type,
			'siteId' => (int)$this->siteId,
			'workerUid' => $this->workerUid,
			'roundId' => $this->roundId === null ? null : (int)$this->roundId,
			'severity' => $this->severity,
			'state' => $this->state,
			'payload' => $this->payloadJson === null ? null : json_decode((string)$this->payloadJson, true),
			'escalatedLeg' => (int)$this->escalatedLeg,
			'firedAt' => $this->firedAt === null ? null : $this->firedAt->format(\DateTimeInterface::ATOM),
			'ackedAt' => $this->ackedAt === null ? null : $this->ackedAt->format(\DateTimeInterface::ATOM),
			'ackedBy' => $this->ackedBy,
			'resolvedAt' => $this->resolvedAt === null ? null : $this->resolvedAt->format(\DateTimeInterface::ATOM),
			'resolvedBy' => $this->resolvedBy,
		];
	}
}
