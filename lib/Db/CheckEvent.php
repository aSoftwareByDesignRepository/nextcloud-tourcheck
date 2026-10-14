<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class CheckEvent extends Entity
{
	protected $siteId;
	protected $roundId;
	protected $roundCpId;
	protected $checkpointId;
	protected $clientEventId;
	protected $actorUid;
	protected $stationMemberId;
	protected $stationId;
	protected $method;
	protected $attesterUid;
	protected $attestationReason;
	protected $serverReceivedAt;
	protected $clientTs;
	protected $valuesJson;
	protected $photoFileid;
	protected $signatureFileid;
	protected $note;
	protected $geoLat;
	protected $geoLon;
	protected $geoAcc;
	protected $lateSync;
	protected $flaggedEarly;
	protected $orphan;
	protected $tombstoned;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('roundId', 'integer');
		$this->addType('roundCpId', 'integer');
		$this->addType('checkpointId', 'integer');
		$this->addType('stationMemberId', 'integer');
		$this->addType('stationId', 'integer');
		$this->addType('serverReceivedAt', 'datetime');
		$this->addType('clientTs', 'datetime');
		$this->addType('photoFileid', 'integer');
		$this->addType('signatureFileid', 'integer');
		$this->addType('geoLat', 'float');
		$this->addType('geoLon', 'float');
		$this->addType('geoAcc', 'integer');
		$this->addType('lateSync', 'integer');
		$this->addType('flaggedEarly', 'integer');
		$this->addType('orphan', 'integer');
		$this->addType('tombstoned', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'roundId' => (int)$this->roundId,
			'roundCpId' => (int)$this->roundCpId,
			'checkpointId' => (int)$this->checkpointId,
			'clientEventId' => $this->clientEventId,
			'actorUid' => $this->actorUid,
			'stationMemberId' => (int)$this->stationMemberId,
			'stationId' => (int)$this->stationId,
			'method' => $this->method,
			'attesterUid' => $this->attesterUid,
			'attestationReason' => $this->attestationReason,
			'serverReceivedAt' => $this->serverReceivedAt === null ? null : $this->serverReceivedAt->format(\DateTimeInterface::ATOM),
			'clientTs' => $this->clientTs === null ? null : $this->clientTs->format(\DateTimeInterface::ATOM),
			'valuesJson' => $this->valuesJson,
			'photoFileid' => (int)$this->photoFileid,
			'signatureFileid' => (int)($this->signatureFileid ?? 0),
			'note' => $this->note,
			'geoLat' => $this->geoLat,
			'geoLon' => $this->geoLon,
			'geoAcc' => $this->geoAcc,
			'lateSync' => (int)$this->lateSync,
			'flaggedEarly' => (int)$this->flaggedEarly,
			'orphan' => (int)$this->orphan,
			'tombstoned' => (int)$this->tombstoned,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}