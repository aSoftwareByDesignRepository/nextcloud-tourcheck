<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class AlertDelivery extends Entity
{
	protected $alertId;
	protected $legNo;
	protected $channel;
	protected $status;
	protected $attempt;
	protected $createdAt;
	protected $sentAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('alertId', 'integer');
		$this->addType('legNo', 'integer');
		$this->addType('attempt', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('sentAt', 'datetime');
	}
}
