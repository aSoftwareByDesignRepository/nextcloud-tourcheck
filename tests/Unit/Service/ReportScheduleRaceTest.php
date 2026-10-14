<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Cron\ReportScheduleJob;
use OCA\TourCheck\Db\AnchorMapper;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Db\ReportDelivery;
use OCA\TourCheck\Db\ReportDeliveryMapper;
use OCA\TourCheck\Db\ReportSched;
use OCA\TourCheck\Db\ReportSchedMapper;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\ReportService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbalException;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Report-schedule race coverage:
 *  1) upsertSchedule insert collision (unique site+cadence+recipient) must
 *     retry as an UPDATE of the winner — never a 500, never a second row.
 *  2) ReportScheduleJob rerun leg: a delivery row that exists (crash
 *     between insert and last_period) is reloaded and only the failed leg
 *     retried — and last_period still advances (no livelock).
 */
final class ReportScheduleRaceTest extends TestCase
{
	private function reportService(ReportSchedMapper&MockObject $schedules): ReportService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-05T12:00:00Z'));
		$chain = $this->createMock(ChainService::class);
		$chain->method('append')->willReturn(new \OCA\TourCheck\Db\JournalEntry());
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanManageSite')->willReturn('boss');
		return new ReportService(
			$this->createMock(RoundMapper::class),
			$this->createMock(CheckEventMapper::class),
			$this->createMock(RoundCheckpointMapper::class),
			$schedules,
			$this->createMock(ReportDeliveryMapper::class),
			$this->createMock(JournalEntryMapper::class),
			$this->createMock(AnchorMapper::class),
			$this->createMock(IncidentMapper::class),
			$acl,
			$this->createMock(SiteService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IAppData::class),
			$this->createMock(IMailer::class),
			$this->createMock(LoggerInterface::class),
			$time,
			$this->createMock(IDBConnection::class),
			$this->createMock(IL10N::class),
			$chain,
		);
	}

	/** A DbalException whose getReason() reports a unique violation — the
	 *  base OCP\DB\Exception always returns null; reason lives on the
	 *  private wrapper, so tests must stub it. */
	private function uniqueViolation(): DbalException
	{
		$e = $this->createMock(DbalException::class);
		$e->method('getReason')->willReturn(DbalException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		return $e;
	}

	public function testUpsertInsertCollisionFallsBackToUpdate(): void
	{
		$schedules = $this->createMock(ReportSchedMapper::class);
		$winner = new ReportSched();
		$winner->setId(77);
		$winner->setRevision(4);
		// First findByNatural: nobody. After the insert collision: the winner.
		$schedules->method('findByNatural')->willReturnOnConsecutiveCalls(null, $winner);
		$schedules->method('insert')->willThrowException($this->uniqueViolation());
		$schedules->expects($this->once())->method('update')
			->willReturnCallback(static fn (ReportSched $s) => $s);

		$svc = $this->reportService($schedules);
		$sched = $svc->upsertSchedule(5, 'weekly', 'ops@example.org', true, 'client');
		self::assertSame(77, $sched->getId());
		self::assertSame(5, $sched->getRevision()); // winner revision bumped once
		self::assertSame('{"audience":"client"}', $sched->getSharePolicyJson());
	}

	public function testUpsertInsertCollisionPropagatesNonUniqueErrors(): void
	{
		$schedules = $this->createMock(ReportSchedMapper::class);
		$schedules->method('findByNatural')->willReturn(null);
		$schedules->method('insert')->willThrowException(
			new DbalException('disk full')
		);
		$schedules->expects($this->never())->method('update');
		$svc = $this->reportService($schedules);
		$this->expectException(DbalException::class);
		$svc->upsertSchedule(5, 'weekly', 'ops@example.org', true);
	}

	public function testJobRerunRetriesFailedLegAndAdvances(): void
	{
		$sched = new ReportSched();
		$sched->setId(3);
		$sched->setSiteId(5);
		$sched->setCadence('weekly');
		$sched->setRecipient('ops@example.org');
		$sched->setLastPeriod('2000-W01'); // forces "due" — never equals last week
		$sched->setActive(1);

		$schedules = $this->createMock(ReportSchedMapper::class);
		$schedules->method('findActive')->willReturn([$sched]);
		$schedules->expects($this->once())->method('update')
			->with(self::callback(static fn (ReportSched $s) => $s->getLastPeriod() !== '2000-W01'));

		$dlv = new ReportDelivery();
		$dlv->setId(9);
		$dlv->setStatus('generated'); // row exists, mail leg never ran
		$deliveries = $this->createMock(ReportDeliveryMapper::class);
		$deliveries->method('findForSchedPeriod')->willReturn($dlv);

		$reports = $this->createMock(ReportService::class);
		$reports->method('scheduleOwner')->willReturn('boss');
		$reports->method('generate')->willThrowException(
			$this->uniqueViolation()
		);
		$reports->expects($this->once())->method('ensureDeliveryFile')->willReturn($dlv);
		$reports->expects($this->once())->method('sendDelivery')->with($dlv);

		$lock = $this->createMock(ILockingProvider::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-05T12:00:00Z'));
		$settings = $this->createMock(SettingsService::class);

		$job = new ReportScheduleJob($time, $schedules, $deliveries, $reports, $settings, $this->createMock(LoggerInterface::class), $lock);
		$m = new \ReflectionMethod($job, 'run');
		$m->invoke($job, null);
		// No exception → no poisoned schedule; last_period advanced via update assert above.
	}

	public function testJobRerunNeverRemailsSentDelivery(): void
	{
		$sched = new ReportSched();
		$sched->setId(3);
		$sched->setSiteId(5);
		$sched->setCadence('weekly');
		$sched->setRecipient('ops@example.org');
		$sched->setLastPeriod('2000-W01');
		$sched->setActive(1);

		$schedules = $this->createMock(ReportSchedMapper::class);
		$schedules->method('findActive')->willReturn([$sched]);
		$schedules->expects($this->once())->method('update');

		$dlv = new ReportDelivery();
		$dlv->setId(9);
		$dlv->setStatus('sent');
		$deliveries = $this->createMock(ReportDeliveryMapper::class);
		$deliveries->method('findForSchedPeriod')->willReturn($dlv);

		$reports = $this->createMock(ReportService::class);
		$reports->method('scheduleOwner')->willReturn('boss');
		$reports->method('generate')->willThrowException(
			$this->uniqueViolation()
		);
		$reports->expects($this->once())->method('ensureDeliveryFile')->willReturn($dlv);
		// 'sent' deliveries must NOT be re-mailed — spec: retry only failed leg.
		$reports->expects($this->never())->method('sendDelivery');

		$lock = $this->createMock(ILockingProvider::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-05T12:00:00Z'));

		$job = new ReportScheduleJob($time, $schedules, $deliveries, $reports, $this->createMock(SettingsService::class), $this->createMock(LoggerInterface::class), $lock);
		$m = new \ReflectionMethod($job, 'run');
		$m->invoke($job, null);
	}
}
