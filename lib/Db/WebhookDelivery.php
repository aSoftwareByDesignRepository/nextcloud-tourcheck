<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class WebhookDelivery extends Entity
{
	protected $webhookId;
	protected $eventKey;
	protected $payloadJson;
	protected $secret;
	protected $status;
	protected $attempt;
	protected $nextRetryAt;
	protected $responseCode;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('webhookId', 'integer');
		$this->addType('attempt', 'integer');
		$this->addType('nextRetryAt', 'datetime');
		$this->addType('responseCode', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		// `secret` and `payloadJson` are deliberately omitted — a delivery
		// row's HMAC snapshot is server-side state, never API output.
		return [
			'id' => (int)$this->id,
			'webhookId' => (int)$this->webhookId,
			'eventKey' => $this->eventKey,
			'status' => $this->status,
			'attempt' => (int)$this->attempt,
			'nextRetryAt' => $this->nextRetryAt === null ? null : $this->nextRetryAt->format(\DateTimeInterface::ATOM),
			'responseCode' => $this->responseCode === null ? null : (int)$this->responseCode,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}
