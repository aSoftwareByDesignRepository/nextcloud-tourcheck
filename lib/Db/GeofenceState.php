<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

/** Per-worker inside/outside episode state for one geofence (hysteresis). */
class GeofenceState extends Entity
{
	protected $workerUid;
	protected $geofenceId;
	protected $inside;
	protected $pendingSince;
	protected $changedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('geofenceId', 'integer');
		$this->addType('inside', 'integer');
		$this->addType('pendingSince', 'datetime');
		$this->addType('changedAt', 'datetime');
	}
}
