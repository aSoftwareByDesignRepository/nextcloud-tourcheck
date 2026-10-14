<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\Task;
use OCA\TourCheck\Db\TaskMapper;
use OCA\TourCheck\Exception\ConflictException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\TaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Site tasks (F5): CAS update fails CLOSED on a missing base_revision
 * (never merges against current state — that is the lost-update hole the
 * revision exists to close). Assignee validation is existence AND site
 * membership — a deleted user's stale member row must not be assignable.
 * Complete is idempotent for the assignee's retry but rejects cancelled.
 */
final class TaskServiceTest extends TestCase
{
	private TaskMapper&MockObject $tasks;
	private AccessControlService&MockObject $acl;
	private EventService&MockObject $events;
	private TaskService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->events = $this->createMock(EventService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-20T12:00:00Z'));
		$this->svc = new TaskService(
			$this->tasks, $this->acl, $this->events, $time,
			$this->createMock(IDBConnection::class),
		);
	}

	private function task(string $status = 'open', ?string $assignee = 'w1', int $rev = 3): Task
	{
		$t = new Task();
		$t->setId(21);
		$t->setSiteId(3);
		$t->setTitle('Check pump');
		$t->setStatus($status);
		$t->setAssigneeUid($assignee);
		$t->setRevision($rev);
		return $t;
	}

	public function testCreateRejectsGhostAssignee(): void
	{
		// exists() passes but siteRole is null → stale member row rejected
		$this->acl->method('siteRole')->willReturn(null);
		$this->expectException(ValidationException::class);
		$this->svc->create(3, 'Fix the gate', null, 'ghost', null, 'sup1');
	}

	public function testCreateValidatesTitle(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->create(3, '   ', null, null, null, 'sup1');
	}

	public function testUpdateWithoutBaseRevisionFailsClosed(): void
	{
		$this->tasks->expects($this->never())->method('findForUpdate');
		$this->expectException(ConflictException::class);
		$this->svc->update(21, null, ['title' => 'x'], 'sup1');
	}

	public function testUpdateOnStaleRevision409s(): void
	{
		$this->tasks->method('findForUpdate')->willReturn($this->task('open', 'w1', 4));
		$this->expectException(ConflictException::class);
		$this->svc->update(21, 3, ['title' => 'x'], 'sup1');
	}

	public function testUpdateOnMissingTaskIs404(): void
	{
		$this->tasks->method('findForUpdate')->willReturn(null);
		$this->expectException(NotFoundException::class);
		$this->svc->update(999, 1, ['title' => 'x'], 'sup1');
	}

	public function testUpdateBumpsRevision(): void
	{
		$t = $this->task('open', 'w1', 3);
		$this->tasks->method('findForUpdate')->willReturn($t);
		$this->tasks->expects($this->once())->method('update')
			->with($this->callback(static fn (Task $x) => (int)$x->getRevision() === 4 && $x->getTitle() === 'New title'));
		$this->svc->update(21, 3, ['title' => 'New title'], 'sup1');
	}

	public function testCompleteByAssigneeIsIdempotentOnDone(): void
	{
		$t = $this->task('done', 'w1');
		$this->tasks->method('findForUpdate')->willReturn($t);
		$this->tasks->expects($this->never())->method('update');
		$this->assertSame($t, $this->svc->complete(21, 'w1', null));
	}

	public function testCompleteOnCancelledRejects(): void
	{
		$this->tasks->method('findForUpdate')->willReturn($this->task('cancelled', 'w1'));
		$this->expectException(ValidationException::class);
		$this->svc->complete(21, 'w1', null);
	}

	public function testCompleteByNonAssigneeNeedsSupervisor(): void
	{
		$t = $this->task('open', 'w1');
		$this->tasks->method('findForUpdate')->willReturn($t);
		// outsider 'w2' completing w1's task → supervisor gate invoked
		$this->acl->expects($this->once())->method('assertCanSuperviseSite')->with(3);
		$this->acl->expects($this->never())->method('assertCanViewSite');
		$this->svc->complete(21, 'w2', null);
	}
}
