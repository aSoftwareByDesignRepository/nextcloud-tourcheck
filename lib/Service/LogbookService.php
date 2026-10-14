<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Logbook;
use OCA\TourCheck\Db\LogbookMapper;
use OCA\TourCheck\Exception\ConflictException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Site logbook / shift handover (US-F7-01). Entries are revision-tracked —
 * an edit bumps revision and the journal records the change, so prior
 * content is never silently rewritten (audit integrity).
 */
class LogbookService
{
	public const KINDS = ['note', 'handover', 'hazard'];

	public function __construct(
		private readonly LogbookMapper $logbook,
		private readonly AccessControlService $acl,
		private readonly EventService $events,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/** @return list<Logbook> with acks hydrated */
	public function listForSite(int $siteId): array
	{
		$this->acl->assertCanViewSite($siteId);
		$rows = $this->logbook->findForSite($siteId);
		foreach ($rows as $r) {
			$r->setAckedBy($this->logbook->acksFor((int)$r->getId()));
		}
		return $rows;
	}

	public function create(int $siteId, string $actorUid, string $kind, string $body, bool $pinned): Logbook
	{
		$this->acl->assertCanViewSite($siteId);
		$fields = [];
		if (!in_array($kind, self::KINDS, true)) {
			$fields['kind'] = 'invalid';
		}
		$body = trim($body);
		if ($body === '' || mb_strlen($body) > 4000) {
			$fields['body'] = 'required';
		}
		if ($fields !== []) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid logbook entry.', $fields);
		}
		// Only supervisor+ may pin — a pinned hazard is a safety surface.
		if ($pinned) {
			$this->acl->assertCanSuperviseSite($siteId);
		}
		$l = new Logbook();
		$l->setSiteId($siteId);
		$l->setAuthorUid($actorUid);
		$l->setKind($kind);
		$l->setPinned($pinned ? 1 : 0);
		$l->setBody($body);
		$l->setRevision(1);
		$l->setCreatedAt($this->timeFactory->getDateTime());
		$l = $this->logbook->insert($l);
		$this->events->journalSignal($siteId, 'logbook_' . $kind, ['actor_uid' => $actorUid, 'logbook_id' => (int)$l->getId()]);
		return $l;
	}

	/** CAS edit — author or supervisor+. Prior content survives in the journal. */
	public function update(int $id, string $actorUid, ?int $baseRevision, string $body, ?bool $pinned): Logbook
	{
		if ($baseRevision === null) {
			throw new ConflictException(Constants::ERR_CONFLICT_STALE, 'Reload before saving.');
		}
		$l = $this->logbook->find($id);
		if ($l === null) {
			throw new NotFoundException();
		}
		if ($l->getAuthorUid() !== $actorUid) {
			$this->acl->assertCanSuperviseSite((int)$l->getSiteId());
		} else {
			$this->acl->assertCanViewSite((int)$l->getSiteId());
		}
		if ((int)$l->getRevision() !== $baseRevision) {
			throw new ConflictException(Constants::ERR_CONFLICT_STALE, 'Entry changed — reload.');
		}
		$body = trim($body);
		if ($body === '' || mb_strlen($body) > 4000) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Body required.', ['body' => 'required']);
		}
		if ($pinned !== null && $pinned !== ((int)$l->getPinned() === 1)) {
			$this->acl->assertCanSuperviseSite((int)$l->getSiteId());
			$l->setPinned($pinned ? 1 : 0);
		}
		// prior body journaled before overwrite — the audit trail keeps both
		$this->events->journalSignal((int)$l->getSiteId(), 'logbook_edited', [
			'actor_uid' => $actorUid, 'logbook_id' => $id, 'rev' => (int)$l->getRevision(),
			'prior_sha256' => hash('sha256', (string)$l->getBody()),
		]);
		$l->setBody($body);
		$l->setRevision((int)$l->getRevision() + 1);
		$this->logbook->update($l);
		return $l;
	}

	/** Acknowledge — the handover receipt. Idempotent via PK. */
	public function ack(int $id, string $actorUid): void
	{
		$l = $this->logbook->find($id);
		if ($l === null) {
			throw new NotFoundException();
		}
		$this->acl->assertCanViewSite((int)$l->getSiteId());
		$this->logbook->insertAck($id, $actorUid, $this->timeFactory->getDateTime());
		$this->events->journalSignal((int)$l->getSiteId(), 'logbook_acked', ['actor_uid' => $actorUid, 'logbook_id' => $id]);
	}

	/** Entries the worker hasn't acked yet — shown at round start. @return list<Logbook> */
	public function unackedFor(int $siteId, string $uid): array
	{
		$this->acl->assertCanViewSite($siteId);
		return array_values(array_filter(
			$this->logbook->findForSite($siteId, 50),
			fn (Logbook $l) => !in_array($uid, $this->logbook->acksFor((int)$l->getId()), true)
		));
	}
}
