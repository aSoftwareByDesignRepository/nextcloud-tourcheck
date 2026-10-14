<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Append-only take/return ledger for NH-10. Rows are never updated or
 * deleted — the movement history is the evidence.
 *
 * @method int getKeyId()
 * @method void setKeyId(int $v)
 * @method int getSiteId()
 * @method void setSiteId(int $v)
 * @method string getAction()
 * @method void setAction(string $v)
 * @method ?string getActorUid()
 * @method void setActorUid(?string $v)
 * @method ?int getMemberId()
 * @method void setMemberId(?int $v)
 * @method ?string getHolderUid()
 * @method void setHolderUid(?string $v)
 * @method ?string getNote()
 * @method void setNote(?string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class KeyLog extends Entity
{
	protected $keyId;
	protected $siteId;
	protected $action;
	protected $actorUid;
	protected $memberId;
	protected $holderUid;
	protected $note;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('keyId', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('memberId', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int)$this->id,
			'key_id' => (int)$this->keyId,
			'site_id' => (int)$this->siteId,
			'action' => (string)$this->action,
			'actor_uid' => $this->actorUid !== null ? (string)$this->actorUid : null,
			'member_id' => $this->memberId !== null ? (int)$this->memberId : null,
			'holder_uid' => $this->holderUid !== null ? (string)$this->holderUid : null,
			'note' => $this->note !== null ? (string)$this->note : null,
			'created_at' => $this->createdAt instanceof \DateTime ? $this->createdAt->format('c') : null,
		];
	}
}
