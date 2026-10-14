<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\Corrective;
use OCA\TourCheck\Db\CorrectiveMapper;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbalException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Incidents + corrective actions. Status machine:
 *   open → action (corrective assigned) → verify (corrective done, awaiting
 *   supervisor verify) → closed. Every transition is CAS-guarded and
 *   journaled — an incident that "heals itself" in the DB is exactly the
 *   kind of silent edit the journal exists to prevent.
 */
class IncidentService
{
	public const CATEGORIES = ['security', 'damage', 'safety', 'maintenance', 'customer', 'threshold', 'checklist', 'other'];
	public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

	public function __construct(
		private readonly IncidentMapper $incidents,
		private readonly CorrectiveMapper $correctives,
		private readonly RoundMapper $rounds,
		private readonly CheckpointMapper $checkpoints,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly ChainService $chain,
		private readonly PhotoService $photos,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly NotificationService $notifier,
		private readonly LoggerInterface $logger,
		private readonly ?FlowEmitter $flow = null,
	) {
	}

	public function get(int $incidentId): Incident
	{
		$inc = $this->incidents->find($incidentId);
		if ($inc === null) {
			throw new NotFoundException();
		}
		$this->acl->assertCanViewSite((int)$inc->getSiteId());
		return $inc;
	}

	/** @return list<Incident> */
	public function listForSite(int $siteId, ?string $status, int $limit = 100, int $offset = 0): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->incidents->findForSite($siteId, $status, $limit, $offset);
	}

	/** @return list<Incident> all sites the user can see (incidents page) */
	public function listVisible(?string $status, int $limit = 200): array
	{
		$siteIds = [];
		foreach ($this->sites->listForCurrentUser() as $s) {
			$siteIds[] = (int)$s->getId();
		}
		return $this->incidents->findForSites($siteIds, $status, $limit);
	}

	/**
	 * System-triggered incident (NH-05 threshold breach). No ACL — the actor
	 * is the server itself; created_by stays null so reports never attribute
	 * a machine finding to a person. Everything else (idempotency, chain,
	 * cross-site checks) matches create().
	 */
	public function createSystem(int $siteId, array $data, ?int $memberId = null): Incident
	{
		return $this->doCreate($siteId, $data, null, $memberId);
	}

	/** Minimal capture: category + severity + free note, photo optional. */
	public function create(int $siteId, array $data, ?string $actorUid = null, ?int $memberId = null): Incident
	{
		$this->sites->getVisible($siteId);
		$uid = $actorUid ?? $this->acl->assertCanViewSite($siteId);
		return $this->doCreate($siteId, $data, $uid, $memberId);
	}

	private function doCreate(int $siteId, array $data, ?string $uid, ?int $memberId): Incident
	{
		$category = (string)($data['category'] ?? '');
		$severity = (string)($data['severity'] ?? '');
		if (!in_array($category, self::CATEGORIES, true)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Category required.', ['category' => 'required']);
		}
		if (!in_array($severity, self::SEVERITIES, true)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Severity required.', ['severity' => 'required']);
		}
		$cid = isset($data['client_incident_id']) ? trim((string)$data['client_incident_id']) : '';
		if ($cid !== '') {
			if (strlen($cid) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $cid)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid client id.', ['client_incident_id' => 'invalid']);
			}
			$existing = $this->incidents->findByClientId($cid);
			if ($existing !== null) {
				return $existing; // idempotent replay
			}
		}
		// Cross-site reference checks: a round/checkpoint/photo id from the
		// request must belong to THIS site, or incidents smuggle foreign
		// evidence into the journal.
		$roundId = null;
		if (isset($data['round_id']) && $data['round_id'] !== null && $data['round_id'] !== '') {
			$round = $this->rounds->find((int)$data['round_id']);
			if ($round === null || (int)$round->getSiteId() !== $siteId) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid round reference.', ['round_id' => 'invalid']);
			}
			$roundId = (int)$round->getId();
		}
		$checkpointId = null;
		if (isset($data['checkpoint_id']) && $data['checkpoint_id'] !== null && $data['checkpoint_id'] !== '') {
			$cp = $this->checkpoints->find((int)$data['checkpoint_id']);
			if ($cp === null || (int)$cp->getSiteId() !== $siteId) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid checkpoint reference.', ['checkpoint_id' => 'invalid']);
			}
			$checkpointId = (int)$cp->getId();
		}
		$photoFileid = null;
		if (isset($data['photo_fileid']) && $data['photo_fileid'] !== null && $data['photo_fileid'] !== '') {
			$photoFileid = (int)$data['photo_fileid'];
			if ($photoFileid <= 0 || !$this->photos->exists($siteId, $photoFileid)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid photo reference.', ['photo_fileid' => 'invalid']);
			}
		}
		$audioFileid = null;
		if (isset($data['audio_fileid']) && $data['audio_fileid'] !== null && $data['audio_fileid'] !== '') {
			$audioFileid = (int)$data['audio_fileid'];
			// Same site-binding rule as photos — a voice note never escapes
			// its site via a forged file id.
			if ($audioFileid <= 0 || !$this->photos->audioExists($siteId, $audioFileid)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid voice note reference.', ['audio_fileid' => 'invalid']);
			}
		}
		$now = $this->timeFactory->getDateTime();
		$inc = new Incident();
		$inc->setSiteId($siteId);
		$inc->setRoundId($roundId);
		$inc->setCheckpointId($checkpointId);
		$inc->setClientIncidentId($cid !== '' ? $cid : null);
		// Server-internal linkage (NH-05 threshold incidents point back at the
		// triggering event) — validated as same-site below via round/checkpoint.
		if (isset($data['event_id']) && $data['event_id'] !== null && $data['event_id'] !== '') {
			$inc->setEventId((int)$data['event_id']);
		}
		$inc->setCategory($category);
		$inc->setSeverity($severity);
		$inc->setTitle(isset($data['title']) && trim((string)$data['title']) !== '' ? mb_substr(trim((string)$data['title']), 0, 400) : null);
		$inc->setNote(isset($data['note']) && trim((string)$data['note']) !== '' ? mb_substr((string)$data['note'], 0, 8000) : null);
		$inc->setPhotoFileid($photoFileid);
		$inc->setAudioFileid($audioFileid);
		$inc->setCreatedBy($uid);
		$inc->setMemberId($memberId);
		$inc->setStatus('open');
		$inc->setRevision(1);
		$inc->setCreatedAt($now);
		$inc->setUpdatedAt($now);
		$this->db->beginTransaction();
		try {
			$inc = $this->incidents->insert($inc);
			$this->chain->append($siteId, [
				'roundId' => $inc->getRoundId(),
				'kind' => Constants::KIND_INCIDENT,
				'actorUid' => $uid,
				'memberId' => $memberId,
				'body' => 'incident_created:' . $category . '/' . $severity,
				'payloadRef' => 'incident:' . $inc->getId(),
				'payloadFields' => [
					'id' => (int)$inc->getId(), 'site' => $siteId, 'category' => $category,
					'severity' => $severity, 'title' => (string)($inc->getTitle() ?? ''),
				],
			]);
			$this->db->commit();
		} catch (DbalException $e) {
			$this->db->rollBack();
			if ($e->getReason() === DbalException::REASON_UNIQUE_CONSTRAINT_VIOLATION && $cid !== '') {
				$stored = $this->incidents->findByClientId($cid);
				if ($stored !== null) {
					return $stored;
				}
			}
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		// NH-04 Flow trigger — post-commit, ids only (NN-08). Severity is in
		// the subject so a rule can narrow to e.g. critical only.
		$this->flow?->emit(FlowEmitter::EVENT_INCIDENT_CREATED, [
			'siteId' => $siteId,
			'incidentId' => (int)$inc->getId(),
			'severity' => $severity,
			'category' => $category,
		]);
		if ($severity === 'critical') {
			// NH-13 / escalation: a critical incident (SOS, life-safety finding)
			// must reach supervisors NOW — not on their next dashboard visit.
			// Post-commit + failure-safe: alerting trouble never rolls back
			// recorded evidence; dedupe key is the incident id so an
			// idempotent replay cannot re-alert.
			try {
				$site = $this->sites->findById($siteId);
				if ($site !== null) {
					$this->notifier->notifySite(
						$siteId,
						'incident_critical:' . $inc->getId(),
						'incident_critical',
						'Critical incident ' . $inc->getId() . ' at site ' . $siteId . '.',
						$site,
						['actor' => $uid ?? 'system'],
					);
				}
			} catch (\Throwable $e) {
				$this->logger->warning('tourcheck: critical incident alert failed', ['incident' => (int)$inc->getId(), 'e' => $e->getMessage()]);
				try {
					$this->chain->append($siteId, [
						'kind' => Constants::KIND_ADMIN,
						'actorUid' => $uid,
						'body' => 'critical_incident_notify_failed',
						'payloadRef' => 'incident:' . $inc->getId(),
						'payloadFields' => ['signal' => 'critical_incident_notify_failed', 'error' => $e->getMessage()],
					]);
				} catch (\Throwable $je) {
					$this->logger->warning('tourcheck: alert-failure journal failed', ['incident' => (int)$inc->getId(), 'e' => $je->getMessage()]);
				}
			}
		}
		return $inc;
	}

	public function update(int $incidentId, array $data, ?int $baseRevision): Incident
	{
		$inc = $this->get($incidentId);
		$uid = $this->acl->assertCanSuperviseSite((int)$inc->getSiteId());
		// Row lock before the CAS check — revision compare without it is a
		// lost-update race (same class as SiteService::update).
		$this->db->beginTransaction();
		try {
			$inc = $this->incidents->findForUpdate($incidentId);
			if ($inc === null) {
				throw new NotFoundException();
			}
			$this->sites->assertRevision($inc->getRevision(), $baseRevision);
			if (array_key_exists('note', $data)) {
				$inc->setNote(mb_substr((string)$data['note'], 0, 8000) ?: null);
			}
			if (array_key_exists('title', $data)) {
				$inc->setTitle(mb_substr(trim((string)$data['title']), 0, 400) ?: null);
			}
			if (array_key_exists('severity', $data)) {
				if (!in_array($data['severity'], self::SEVERITIES, true)) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid severity.', ['severity' => 'invalid']);
				}
				$inc->setSeverity((string)$data['severity']);
			}
			if (array_key_exists('status', $data)) {
				$this->assertTransition($inc->getStatus(), (string)$data['status']);
				$inc->setStatus((string)$data['status']);
			}
			$inc->setRevision((int)$inc->getRevision() + 1);
			$inc->setUpdatedAt($this->timeFactory->getDateTime());
			$inc = $this->incidents->update($inc);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->journalIncident($inc, $uid, 'incident_updated');
		return $inc;
	}

	private function assertTransition(string $from, string $to): void
	{
		$allowed = [
			'open' => ['action', 'closed'],
			'action' => ['verify', 'closed'],
			'verify' => ['closed', 'action'],
		];
		if (!in_array($to, $allowed[$from] ?? [], true)) {
			throw new DomainException('illegal_transition', 'Cannot move incident from ' . $from . ' to ' . $to . '.', 409);
		}
	}

	public function addCorrective(int $incidentId, string $text, ?string $assigneeUid, ?string $dueDate): Corrective
	{
		$inc = $this->get($incidentId);
		$uid = $this->acl->assertCanSuperviseSite((int)$inc->getSiteId());
		$text = trim($text);
		if ($text === '' || mb_strlen($text) > 1000) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Action text required.', ['text' => 'required']);
		}
		if ($assigneeUid !== null && $assigneeUid !== '') {
			$this->acl->assertValidUid($assigneeUid);
		}
		$due = null;
		if ($dueDate !== null && $dueDate !== '') {
			$d = \DateTime::createFromFormat('Y-m-d', $dueDate);
			if ($d === false) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Due date must be YYYY-MM-DD.', ['due_at' => 'invalid']);
			}
			$due = $d;
		}
		$now = $this->timeFactory->getDateTime();
		$c = new Corrective();
		$c->setIncidentId($incidentId);
		$c->setText($text);
		$c->setAssigneeUid($assigneeUid ?: null);
		$c->setDueAt($due);
		$c->setStatus('open');
		$c->setRevision(1);
		$c->setCreatedAt($now);
		$c->setUpdatedAt($now);
		$this->db->beginTransaction();
		try {
			// Re-read under the row lock — the entity above may be stale
			// (concurrent verifyCorrective/update hold the same row).
			$inc = $this->incidents->findForUpdate($incidentId);
			if ($inc === null) {
				throw new NotFoundException();
			}
			$c = $this->correctives->insert($c);
			if ($inc->getStatus() === 'open') {
				$inc->setStatus('action');
				$inc->setRevision((int)$inc->getRevision() + 1);
				$inc->setUpdatedAt($now);
				$this->incidents->update($inc);
			}
			$this->journalIncident($inc, $uid, 'corrective_added');
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $c;
	}

	public function updateCorrective(int $correctiveId, array $data, ?int $baseRevision): Corrective
	{
		$c = $this->correctives->find($correctiveId);
		if ($c === null) {
			throw new NotFoundException();
		}
		$inc = $this->get((int)$c->getIncidentId());
		// Only the assignee or a supervisor+ may move a corrective — a mere
		// site viewer must not resolve someone else's action.
		$uid = $this->acl->assertAppAccess();
		$role = $this->acl->siteRole($uid, (int)$inc->getSiteId());
		$isAssignee = $c->getAssigneeUid() !== null && (string)$c->getAssigneeUid() === $uid;
		$canSupervise = in_array($role, [Constants::ROLE_ADMIN, Constants::ROLE_PLANNER, Constants::ROLE_SUPERVISOR], true);
		if (!$isAssignee && !$canSupervise) {
			throw new NotFoundException();
		}
		// Lock order: parent incident row first, then the corrective —
		// matches round→chain-head convention (aggregate before child) and
		// keeps this path deadlock-safe against IncidentService::update.
		$this->db->beginTransaction();
		try {
			$inc = $this->incidents->findForUpdate((int)$inc->getId());
			$c = $this->correctives->findForUpdate($correctiveId);
			if ($inc === null || $c === null || (int)$c->getIncidentId() !== (int)$inc->getId()) {
				throw new NotFoundException();
			}
			$this->sites->assertRevision($c->getRevision(), $baseRevision);
			if (array_key_exists('status', $data)) {
				if (!in_array($data['status'], ['open', 'done'], true)) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid status.', ['status' => 'invalid']);
				}
				$c->setStatus((string)$data['status']);
				if ($data['status'] === 'done' && $inc->getStatus() === 'action') {
					$inc->setStatus('verify');
					$inc->setRevision((int)$inc->getRevision() + 1);
					$inc->setUpdatedAt($this->timeFactory->getDateTime());
					$this->incidents->update($inc);
				}
			}
			if (array_key_exists('resolution', $data)) {
				$c->setResolution(mb_substr((string)$data['resolution'], 0, 1000) ?: null);
			}
			$c->setRevision((int)$c->getRevision() + 1);
			$c->setUpdatedAt($this->timeFactory->getDateTime());
			$c = $this->correctives->update($c);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->journalIncident($inc, $uid, 'corrective_updated:' . $c->getStatus());
		return $c;
	}

	/** Supervisor verifies a done corrective → incident closes. */
	public function verifyCorrective(int $correctiveId): Corrective
	{
		$c = $this->correctives->find($correctiveId);
		if ($c === null) {
			throw new NotFoundException();
		}
		$inc = $this->get((int)$c->getIncidentId());
		$uid = $this->acl->assertCanSuperviseSite((int)$inc->getSiteId());
		if ($c->getStatus() !== 'done') {
			throw new DomainException('not_done', 'Only finished actions can be verified.', 409);
		}
		$this->db->beginTransaction();
		try {
			// Lock incident → corrective (parent-first) and re-check status
			// under the lock — a concurrent verify on the stale 'done' read
			// must not double-verify, double-bump, or double-journal.
			$inc = $this->incidents->findForUpdate((int)$inc->getId());
			$c = $this->correctives->findForUpdate($correctiveId);
			if ($inc === null || $c === null || (int)$c->getIncidentId() !== (int)$inc->getId()) {
				throw new NotFoundException();
			}
			if ($c->getStatus() !== 'done') {
				throw new DomainException('not_done', 'Only finished actions can be verified.', 409);
			}
			$c->setStatus('verified');
			$c->setVerifiedBy($uid);
			$c->setVerifiedAt($this->timeFactory->getDateTime());
			$c->setRevision((int)$c->getRevision() + 1);
			$c->setUpdatedAt($this->timeFactory->getDateTime());
			$c = $this->correctives->update($c);
			$open = $this->correctives->countOpenForIncident((int)$inc->getId());
			if ($open === 0) {
				$inc->setStatus('closed');
				$inc->setRevision((int)$inc->getRevision() + 1);
				$inc->setUpdatedAt($this->timeFactory->getDateTime());
				$this->incidents->update($inc);
			}
			$this->journalIncident($inc, $uid, 'corrective_verified');
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $c;
	}

	/** @return list<Corrective> */
	public function correctivesOf(int $incidentId): array
	{
		return $this->correctives->findForIncident($incidentId);
	}

	private function journalIncident(Incident $inc, string $uid, string $body): void
	{
		$this->chain->append((int)$inc->getSiteId(), [
			'roundId' => $inc->getRoundId(),
			'kind' => Constants::KIND_INCIDENT,
			'actorUid' => $uid,
			'body' => $body,
			'payloadRef' => 'incident:' . $inc->getId(),
			'payloadFields' => [
				'id' => (int)$inc->getId(), 'status' => (string)$inc->getStatus(),
				'revision' => (int)$inc->getRevision(),
			],
		]);
	}
}
