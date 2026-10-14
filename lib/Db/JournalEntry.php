<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class JournalEntry extends Entity
{
	protected $siteId;
	protected $seq;
	protected $roundId;
	protected $kind;
	protected $actorUid;
	protected $memberId;
	protected $body;
	protected $payloadRef;
	protected $payloadHash;
	protected $prevHash;
	protected $entryHash;
	protected $serverTs;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('seq', 'integer');
		$this->addType('roundId', 'integer');
		$this->addType('memberId', 'integer');
		$this->addType('serverTs', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'seq' => (int)$this->seq,
			'roundId' => (int)$this->roundId,
			'kind' => $this->kind,
			'actorUid' => $this->actorUid,
			'memberId' => (int)$this->memberId,
			'body' => $this->body,
			'payloadRef' => $this->payloadRef,
			'payloadHash' => $this->payloadHash,
			'prevHash' => $this->prevHash,
			'entryHash' => $this->entryHash,
			'serverTs' => $this->serverTs === null ? null : $this->serverTs->format(\DateTimeInterface::ATOM),
		];
	}
}