<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * NH-10 Schlüsselmanagement: a registered key for a site. Status is
 * in|out — exactly one holder when out (worker uid OR station member id).
 *
 * @method int getSiteId()
 * @method void setSiteId(int $v)
 * @method string getLabel()
 * @method void setLabel(string $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method ?string getHolderUid()
 * @method void setHolderUid(?string $v)
 * @method ?int getHolderMemberId()
 * @method void setHolderMemberId(?int $v)
 * @method int getRevision()
 * @method void setRevision(int $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class TckKey extends Entity
{
	protected $siteId;
	protected $label;
	protected $status;
	protected $holderUid;
	protected $holderMemberId;
	protected $revision;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('siteId', 'integer');
		$this->addType('holderMemberId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'site_id' => (int)$this->siteId,
			'label' => (string)$this->label,
			'status' => (string)$this->status,
			'holder_uid' => $this->holderUid !== null ? (string)$this->holderUid : null,
			'holder_member_id' => $this->holderMemberId !== null ? (int)$this->holderMemberId : null,
			'revision' => (int)$this->revision,
		];
	}
}
