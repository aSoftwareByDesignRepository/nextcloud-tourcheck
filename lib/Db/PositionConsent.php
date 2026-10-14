<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class PositionConsent extends Entity
{
	protected $uid;
	protected $siteId;
	protected $grantedAt;
	protected $revokedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('grantedAt', 'datetime');
		$this->addType('revokedAt', 'datetime');
	}
}
