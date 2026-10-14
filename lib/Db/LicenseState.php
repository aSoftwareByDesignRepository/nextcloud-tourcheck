<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

class LicenseState extends Entity
{
	protected $customerId;
	protected $validUntil;
	protected $mobileSeats;
	protected $stations;
	protected $bundle;
	protected $keyAppliedAt;
	protected $payloadB64;
	protected $signatureB64;
	protected $licenseFingerprint;
	protected $boundInstanceId;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('validUntil', 'date');
		$this->addType('mobileSeats', 'integer');
		$this->addType('stations', 'integer');
		$this->addType('bundle', 'integer');
		$this->addType('keyAppliedAt', 'datetime');
	}
}
