<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Task;
use OCA\TourCheck\Db\TaskMapper;
use OCA\TourCheck\Exception\ConflictException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Site tasks (US-F5-01): ad-hoc work items between rounds. CAS via revision
 * like every other mutable entity; assignee must exist AND be a site member
 * (api-security §2 — existence + membership, not one or the other).
 */
class TaskService
{
	public const STATUSES = ['open', 'done', 'cancelled'];

	public function __construct(
		private readonly TaskMapper $tasks,
		private readonly AccessControlService $acl,
		private readonly EventService $events,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
	) {
	}

	/** @return list<Task> */
	public function listForSite(int $siteId, ?string $status): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->tasks->findForSite($siteId, $status);
	}

	/** @return list<Task> the caller's own open tasks (companion) */
	public function listMine(string $uid): array
	{
		return $this->tasks->findOpenForAssignee($uid);
	}

	public function create(int $siteId, string $title, ?string $body, ?string $assigneeUid, ?\DateTimeInterface $dueAt, string $actorUid): Task
	{
		$this->acl->assertCanSuperviseSite($siteId);
		$title = trim($title);
		$fields = [];
		if ($title === '' || mb_strlen($title) > 190) {
			$fields['title'] = 'required';
		}
		if ($assigneeUid !== null && $assigneeUid !== '') {
			$this->acl->assertValidUid($assigneeUid);
			if ($this->acl->siteRole($assigneeUid, $siteId) === null) {
				$fields['assignee_uid'] = 'invalid'; // exists but not a site member
			}
		}
		if ($fields !== []) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid task.', $fields);
		}
		$t = new Task();
		$t->setSiteId($siteId);
		$t->setTitle($title);
		$t->setBody($body === null ? null : mb_substr(trim($body), 0, 2000));
		$t->setAssigneeUid($assigneeUid === '' ? null : $assigneeUid);
		$t->setDueAt($dueAt);
		$t->setStatus('open');
		$t->setRevision(1);
		$t->setCreatedBy($actorUid);
		$t->setCreatedAt($this->timeFactory->getDateTime());
		$t = $this->tasks->insert($t);
		$this->events->journalSignal($siteId, 'task_created', ['actor_uid' => $actorUid, 'task_id' => (int)$t->getId()]);
		return $t;
	}

	/** CAS update — missing/invalid base_revision fails closed (409). */
	public function update(int $id, ?int $baseRevision, array $fields, string $actorUid): Task
	{
		if ($baseRevision === null) {
			throw new ConflictException(Constants::ERR_CONFLICT_STALE, 'Reload before saving.');
		}
		$this->db->beginTransaction();
		try {
			$t = $this->tasks->findForUpdate($id);
			if ($t === null) {
				throw new NotFoundException();
			}
			$this->acl->assertCanSuperviseSite((int)$t->getSiteId());
			if ((int)$t->getRevision() !== $baseRevision) {
				throw new ConflictException(Constants::ERR_CONFLICT_STALE, 'Task changed — reload.');
			}
			if (isset($fields['title'])) {
				$title = trim((string)$fields['title']);
				if ($title === '' || mb_strlen($title) > 190) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid title.', ['title' => 'required']);
				}
				$t->setTitle($title);
			}
			if (array_key_exists('body', $fields)) {
				$t->setBody($fields['body'] === null ? null : mb_substr(trim((string)$fields['body']), 0, 2000));
			}
			if (array_key_exists('assignee_uid', $fields)) {
				$au = $fields['assignee_uid'];
				if ($au === null || $au === '') {
					$t->setAssigneeUid(null);
				} else {
					$this->acl->assertValidUid((string)$au);
					if ($this->acl->siteRole((string)$au, (int)$t->getSiteId()) === null) {
						throw new ValidationException(Constants::ERR_VALIDATION, 'Assignee is not a site member.', ['assignee_uid' => 'invalid']);
					}
					$t->setAssigneeUid((string)$au);
				}
			}
			if (array_key_exists('due_at', $fields)) {
				$t->setDueAt($fields['due_at']);
			}
			if (isset($fields['status'])) {
				if (!in_array($fields['status'], self::STATUSES, true)) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid status.', ['status' => 'invalid']);
				}
				$t->setStatus((string)$fields['status']);
			}
			$t->setRevision((int)$t->getRevision() + 1);
			$this->tasks->update($t);
			$this->db->commit();
			return $t;
		} catch (\Throwable $th) {
			$this->db->rollBack();
			throw $th;
		}
	}

	/**
	 * Worker marks a task done — assignee OR supervisor+ may complete.
	 * Optional evidence note journaled on the site.
	 */
	public function complete(int $id, string $actorUid, ?string $note): Task
	{
		$this->db->beginTransaction();
		try {
			$t = $this->tasks->findForUpdate($id);
			if ($t === null) {
				throw new NotFoundException();
			}
			if ($t->getAssigneeUid() !== $actorUid) {
				$this->acl->assertCanSuperviseSite((int)$t->getSiteId());
			} else {
				$this->acl->assertCanViewSite((int)$t->getSiteId());
			}
			if ($t->getStatus() !== 'open') {
				// idempotent for the same actor's retry — already done is a no-op
				if ($t->getStatus() === 'done') {
					$this->db->commit();
					return $t;
				}
				throw new ValidationException(Constants::ERR_VALIDATION, 'Task is ' . $t->getStatus() . '.', ['status' => 'terminal']);
			}
			$t->setStatus('done');
			$evidence = ['completed_by' => $actorUid, 'completed_at' => $this->timeFactory->getDateTime()->format(\DateTimeInterface::ATOM)];
			if ($note !== null && trim($note) !== '') {
				$evidence['note'] = mb_substr(trim($note), 0, 2000);
			}
			$t->setEvidenceJson(json_encode($evidence));
			$t->setRevision((int)$t->getRevision() + 1);
			$this->tasks->update($t);
			$this->db->commit();
		} catch (\Throwable $th) {
			$this->db->rollBack();
			throw $th;
		}
		$this->events->journalSignal((int)$t->getSiteId(), 'task_done', ['actor_uid' => $actorUid, 'task_id' => $id]);
		return $t;
	}
}
