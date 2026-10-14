<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Anchor extends Entity
{
	protected $siteId;
	protected $seq;
	protected $headHash;
	protected $triggerKind;
	protected $status;
	protected $tsaToken;
	protected $error;
	protected $attempts;
	protected $requestedAt;
	protected $anchoredAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('seq', 'integer');
		$this->addType('attempts', 'integer');
		$this->addType('requestedAt', 'datetime');
		$this->addType('anchoredAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'siteId' => (int)$this->siteId,
			'seq' => (int)$this->seq,
			'headHash' => $this->headHash,
			'triggerKind' => $this->triggerKind,
			'status' => $this->status,
			'tsaToken' => $this->tsaToken,
			'error' => $this->error,
			'attempts' => (int)$this->attempts,
			'requestedAt' => $this->requestedAt === null ? null : $this->requestedAt->format(\DateTimeInterface::ATOM),
			'anchoredAt' => $this->anchoredAt === null ? null : $this->anchoredAt->format(\DateTimeInterface::ATOM),
		];
	}
}