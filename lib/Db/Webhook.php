<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class Webhook extends Entity
{
	protected $url;
	protected $secret;
	protected $eventsJson;
	protected $active;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('active', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	// `secret` is an HMAC shared secret — needed plaintext to sign outbound
	// deliveries (same model as GitHub webhooks); deliberately excluded from
	// jsonSerialize.

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'url' => $this->url,
			'events' => json_decode((string)$this->eventsJson, true) ?? [],
			'active' => (int)$this->active,
			'createdAt' => $this->createdAt === null ? null : $this->createdAt->format(\DateTimeInterface::ATOM),
		];
	}
}
