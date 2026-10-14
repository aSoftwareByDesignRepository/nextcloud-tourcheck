<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class PositionEvent extends Entity
{
	protected $roundId;
	protected $actorUid;
	protected $lat;
	protected $lon;
	protected $accuracyM;
	protected $serverReceivedAt;
	protected $clientTs;
	protected $expiresAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('roundId', 'integer');
		$this->addType('lat', 'float');
		$this->addType('lon', 'float');
		$this->addType('accuracyM', 'integer');
		$this->addType('serverReceivedAt', 'datetime');
		$this->addType('clientTs', 'datetime');
		$this->addType('expiresAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'roundId' => (int)$this->roundId,
			'actorUid' => $this->actorUid,
			'lat' => (float)$this->lat,
			'lon' => (float)$this->lon,
			'accuracyM' => (int)$this->accuracyM,
			'serverReceivedAt' => $this->serverReceivedAt === null ? null : $this->serverReceivedAt->format(\DateTimeInterface::ATOM),
			'clientTs' => $this->clientTs === null ? null : $this->clientTs->format(\DateTimeInterface::ATOM),
			'expiresAt' => $this->expiresAt === null ? null : $this->expiresAt->format(\DateTimeInterface::ATOM),
		];
	}
}