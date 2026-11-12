<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\LogbookMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\CheckpointService;
use OCA\TourCheck\Service\EntryMailService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\PhotoService;
use OCA\TourCheck\Service\RoundService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * F1/F3/F4/F5 contract tests: "wichtig" flag parsing (form-encoded
 * "false" must never flag), ad-hoc round validation, customer-mail
 * recipient resolution and the mail-failure soft path.
 */
final class ImportantFlagMailDigestTest extends TestCase
{
	/** "false" as a string is truthy in PHP — the classic checkbox trap. */
	public function testImportantRejectsTruthyStrings(): void
	{
		$svc = $this->incidentService();
		$inc = new Incident();
		$inc->setId(5);
		$inc->setSiteId(1);
		$inc->setStatus('open');
		$inc->setRevision(1);
		$mapper = $svc[1];
		$mapper->method('find')->willReturn($inc);
		$mapper->method('findForUpdate')->willReturn($inc);

		$this->expectException(ValidationException::class);
		$svc[0]->update(5, ['important' => 'yes'], 1);
	}

	public function testImportantStringFalseDoesNotFlag(): void
	{
		$svc = $this->incidentService();
		$inc = new Incident();
		$inc->setId(5);
		$inc->setSiteId(1);
		$inc->setStatus('open');
		$inc->setImportant(1);
		$inc->setRevision(1);
		$mapper = $svc[1];
		$mapper->method('find')->willReturn($inc);
		$mapper->method('findForUpdate')->willReturn($inc);
		$mapper->method('update')->willReturnArgument(0);

		$out = $svc[0]->update(5, ['important' => 'false'], 1);
		self::assertSame(0, (int)$out->getImportant());
	}

	public function testImportantTrueFlags(): void
	{
		$svc = $this->incidentService();
		$inc = new Incident();
		$inc->setId(5);
		$inc->setSiteId(1);
		$inc->setStatus('open');
		$inc->setRevision(1);
		$mapper = $svc[1];
		$mapper->method('find')->willReturn($inc);
		$mapper->method('findForUpdate')->willReturn($inc);
		$mapper->method('update')->willReturnArgument(0);

		$out = $svc[0]->update(5, ['important' => true], 1);
		self::assertSame(1, (int)$out->getImportant());
	}

	/** @return array{0: \OCA\TourCheck\Service\IncidentService, 1: IncidentMapper&MockObject} */
	private function incidentService(): array
	{
		$db = $this->createMock(IDBConnection::class);
		$mapper = $this->createMock(IncidentMapper::class);
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanSuperviseSite')->willReturn('super1');
		$sites = $this->createMock(\OCA\TourCheck\Service\SiteService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());
		$svc = new \OCA\TourCheck\Service\IncidentService(
			$mapper,
			$this->createMock(\OCA\TourCheck\Db\CorrectiveMapper::class),
			$this->createMock(RoundMapper::class),
			$this->createMock(\OCA\TourCheck\Db\CheckpointMapper::class),
			$acl,
			$sites,
			$this->createMock(ChainService::class),
			$this->createMock(PhotoService::class),
			$time,
			$db,
			$this->createMock(\OCA\TourCheck\Service\NotificationService::class),
			$this->createMock(LoggerInterface::class),
			null,
		);
		return [$svc, $mapper];
	}

	public function testAdhocRejectsForeignCheckpoint(): void
	{
		$svc = $this->roundService();
		$cp = new Checkpoint();
		$cp->setId(9);
		$cp->setSiteId(2); // different site — IDOR guard
		$cp->setActive(1);
		$svc[1]->method('getAny')->willReturn($cp);
		$time = $this->createMock(ITimeFactory::class);

		try {
			$svc[0]->createAdhoc(1, new \DateTime('+2 hours'), 60, [9]);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('checkpoint_ids', $e->fields);
		}
	}

	public function testAdhocRejectsArchivedCheckpoint(): void
	{
		$svc = $this->roundService();
		$cp = new Checkpoint();
		$cp->setId(9);
		$cp->setSiteId(1);
		$cp->setActive(0);
		$svc[1]->method('getAny')->willReturn($cp);

		try {
			$svc[0]->createAdhoc(1, new \DateTime('+2 hours'), 60, [9]);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('checkpoint_ids', $e->fields);
		}
	}

	public function testAdhocRejectsEmptySelection(): void
	{
		[$svc] = $this->roundService();
		try {
			$svc->createAdhoc(1, new \DateTime('+2 hours'), 60, []);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('required', $e->fields['checkpoint_ids'] ?? null);
		}
	}

	public function testAdhocRejectsStartInPast(): void
	{
		[$svc, $cps] = $this->roundService();
		$cp = new Checkpoint();
		$cp->setId(9);
		$cp->setSiteId(1);
		$cp->setActive(1);
		$cps->method('getAny')->willReturn($cp);
		try {
			$svc->createAdhoc(1, new \DateTime('-3 hours'), 60, [9]);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('in_past', $e->fields['starts_at'] ?? null);
		}
	}

	/** @return array{0: RoundService, 1: CheckpointService&MockObject} */
	private function roundService(): array
	{
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanManageSite')->willReturn('planner1');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());
		$cps = $this->createMock(CheckpointService::class);
		$svc = new RoundService(
			$this->createMock(RoundMapper::class),
			$this->createMock(RoundCheckpointMapper::class),
			$this->createMock(CheckEventMapper::class),
			$acl,
			$this->createMock(ChainService::class),
			$time,
			$this->createMock(IDBConnection::class),
			$cps,
			null,
		);
		return [$svc, $cps];
	}

	public function testSendIncidentRejectsInvalidRecipient(): void
	{
		[$svc] = $this->entryMail();
		$this->expectException(ValidationException::class);
		try {
			$svc->sendIncident(7, 'not-an-email');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('recipient', $e->fields);
			throw $e;
		}
	}

	public function testSendIncidentMailFailureIsSoft(): void
	{
		[$svc, $mailer] = $this->entryMail();
		$msg = $this->createMock(\OCP\Mail\IMessage::class);
		$mailer->method('createMessage')->willReturn($msg);
		$mailer->method('send')->willThrowException(new \RuntimeException('smtp down'));
		$out = $svc->sendIncident(7, null);
		self::assertFalse($out['sent']);
		self::assertNull($out['sent_to']);
	}

	public function testAdhocHappyPathPersistsRoundAndStops(): void
	{
		[$svc, $cps] = $this->roundService();
		$cp = new Checkpoint();
		$cp->setId(9);
		$cp->setSiteId(1);
		$cp->setName('Gate');
		$cp->setActive(1);
		$cps->method('getAny')->willReturn($cp);
		// reach into the service's private mappers via reflection for assertions
		$rounds = $this->mapperOf($svc, 'rounds');
		$rcps = $this->mapperOf($svc, 'roundCps');
		$rounds->expects($this->once())->method('insert')->willReturnCallback(function (Round $r) {
			self::assertNull($r->getPlanId());
			self::assertSame(1, (int)$r->getAdhoc());
			self::assertSame('scheduled', $r->getStatus());
			$r->setId(42);
			return $r;
		});
		$rcps->expects($this->once())->method('insert');
		$round = $svc->createAdhoc(1, new \DateTime('+2 hours'), 60, [9]);
		self::assertSame(42, (int)$round->getId());
	}

	public function testAdhocClampsDurationToMax(): void
	{
		[$svc, $cps] = $this->roundService();
		$cp = new Checkpoint();
		$cp->setId(9);
		$cp->setSiteId(1);
		$cp->setName('Gate');
		$cp->setActive(1);
		$cps->method('getAny')->willReturn($cp);
		$rounds = $this->mapperOf($svc, 'rounds');
		$rounds->expects($this->once())->method('insert')->willReturnCallback(function (Round $r) {
			// 720-minute ceiling — an unbounded duration is a data-integrity bug
			$diff = $r->getWindowEnd()->getTimestamp() - $r->getScheduledStart()->getTimestamp();
			self::assertSame(720 * 60, $diff);
			$r->setId(7);
			return $r;
		});
		$svc->createAdhoc(1, new \DateTime('+2 hours'), 99999, [9]);
	}

	private function mapperOf(object $svc, string $prop): MockObject
	{
		$r = new \ReflectionProperty($svc, $prop);
		return $r->getValue($svc);
	}

	public function testMarkStartedOnceDelegatesToConditionalUpdate(): void
	{
		[$svc] = $this->roundService();
		$rounds = $this->mapperOf($svc, 'rounds');
		$ts = new \DateTime('2026-11-12 08:15');
		$rounds->expects($this->once())->method('markStarted')
			->with(7, $ts, 'worker1')->willReturn(true);
		$svc->markStartedOnce(7, $ts, 'worker1');
	}

	public function testDigestDisabledStillStampsCronRun(): void
	{
		// A site with flagged items + the firing hour: if the disabled gate
		// is ever removed, notifySite MUST observe it (mutation-killer).
		$inc = new Incident();
		$inc->setId(3);
		$inc->setSiteId(1);
		$inc->setImportant(1);
		$inc->setCreatedAt(new \DateTime('-1 hours'));
		[$job, $settings, $notifs] = $this->digestJob(true, [$inc]);
		$settings->method('isDigestEnabled')->willReturn(false);
		$settings->method('getDigestHour')->willReturn((int)(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('G'));
		$settings->expects($this->once())->method('markCronRun');
		$notifs->expects($this->never())->method('notifySite');
		$this->runJob($job);
	}

	public function testDigestWrongHourNoNotify(): void
	{
		// Same armed world — removing the hour gate must be observable.
		$inc = new Incident();
		$inc->setId(3);
		$inc->setSiteId(1);
		$inc->setImportant(1);
		$inc->setCreatedAt(new \DateTime('-1 hours'));
		[$job, $settings, $notifs] = $this->digestJob(true, [$inc]);
		$settings->method('isDigestEnabled')->willReturn(true);
		$settings->method('getDigestHour')->willReturn(((int)(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('G') + 12) % 24);
		$notifs->expects($this->never())->method('notifySite');
		$this->runJob($job);
	}

	public function testDigestEmptyItemsSkipsSite(): void
	{
		[$job, $settings, $notifs, $extra] = $this->digestJob(true);
		$settings->method('isDigestEnabled')->willReturn(true);
		$settings->method('getDigestHour')->willReturn((int)(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('G'));
		$notifs->expects($this->never())->method('notifySite');
		$this->runJob($job);
	}

	public function testDigestSendsDedupedNotificationAndMail(): void
	{
		$inc = new Incident();
		$inc->setId(3);
		$inc->setSiteId(1);
		$inc->setSeverity('high');
		$inc->setTitle('Door open');
		$inc->setImportant(1);
		$inc->setCreatedAt(new \DateTime('-2 hours'));
		$inc->setCreatedBy('worker1');
		[$job, $settings, $notifs, $extra] = $this->digestJob(true, [$inc]);
		$settings->method('isDigestEnabled')->willReturn(true);
		$settings->method('isDigestEmailEnabled')->willReturn(true);
		$settings->method('getDigestHour')->willReturn((int)(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('G'));
		$notifs->expects($this->once())->method('notifySite')
			->with(1, $this->stringContains('digest:1:'), 'important_digest', $this->anything(), $this->anything(), $this->anything())
			->willReturn(true);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getEMailAddress')->willReturn('super@example.com');
		$user->method('getDisplayName')->willReturn('Super');
		$extra['users']->method('get')->willReturn($user);
		$msg = $this->createMock(\OCP\Mail\IMessage::class);
		$msg->expects($this->once())->method('setTo')->with(['super@example.com' => 'Super']);
		$extra['mailer']->method('createMessage')->willReturn($msg);
		$extra['mailer']->expects($this->once())->method('send')->with($msg);
		$this->runJob($job);
	}

	public function testDigestMailSkippedWhenNotifyDeduped(): void
	{
		$inc = new Incident();
		$inc->setSiteId(1);
		$inc->setImportant(1);
		$inc->setCreatedAt(new \DateTime('-1 hours'));
		[$job, $settings, $notifs, $extra] = $this->digestJob(true, [$inc]);
		$settings->method('isDigestEnabled')->willReturn(true);
		$settings->method('isDigestEmailEnabled')->willReturn(true);
		$settings->method('getDigestHour')->willReturn((int)(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('G'));
		$notifs->method('notifySite')->willReturn(false); // dedupe hit — already sent
		// A supervisor WITH an address: if the mail leg ever ignores the
		// dedupe outcome, createMessage is invoked (mutation-killer).
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getEMailAddress')->willReturn('super@example.com');
		$user->method('getDisplayName')->willReturn('Super');
		$extra['users']->method('get')->willReturn($user);
		$extra['mailer']->expects($this->never())->method('createMessage');
		$this->runJob($job);
	}

	/** @return array{0: \OCA\TourCheck\Cron\ImportantDigestJob, 1: \OCA\TourCheck\Service\SettingsService&MockObject, 2: \OCA\TourCheck\Service\NotificationService&MockObject, 3: array} */
	private function digestJob(bool $withSite = false, array $incItems = []): array
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());
		$settings = $this->createMock(\OCA\TourCheck\Service\SettingsService::class);
		$settings->method('getDefaultTimezone')->willReturn('UTC');
		$sites = $this->createMock(SiteMapper::class);
		$site = null;
		if ($withSite) {
			$site = new Site();
			$site->setId(1);
			$site->setName('Gate A');
			$site->setActive(1);
		}
		$sites->method('findAll')->willReturn($site === null ? [] : [$site]);
		$incidents = $this->createMock(IncidentMapper::class);
		$incidents->method('findImportantSince')->willReturn($incItems);
		$logbook = $this->createMock(LogbookMapper::class);
		$logbook->method('findImportantSince')->willReturn([]);
		$notifs = $this->createMock(\OCA\TourCheck\Service\NotificationService::class);
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('supervisorRecipients')->willReturn(['super1']);
		$users = $this->createMock(\OCP\IUserManager::class);
		$mailer = $this->createMock(IMailer::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s, $p = []) => vsprintf($s, (array)$p) ?: $s);
		$lock = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$job = new \OCA\TourCheck\Cron\ImportantDigestJob(
			$time, $settings, $sites, $incidents, $logbook, $notifs, $acl, $users, $mailer, $l10n, $lock,
			$this->createMock(LoggerInterface::class),
		);
		return [$job, $settings, $notifs, ['incidents' => $incidents, 'users' => $users, 'mailer' => $mailer]];
	}

	private function runJob(object $job): void
	{
		$m = new \ReflectionMethod($job, 'run');
		$m->invoke($job, null);
	}

	public function testSendIncidentConsultsRateLimiter(): void
	{
		[$svc] = $this->entryMail();
		$rl = $this->mapperOf($svc, 'rateLimit');
		$rl->expects($this->once())->method('check')
			->with('entrymail', '1|super1', 10, 600);
		$msg = $this->createMock(\OCP\Mail\IMessage::class);
		$mailer = $this->mapperOf($svc, 'mailer');
		$mailer->method('createMessage')->willReturn($msg);
		$out = $svc->sendIncident(7, null);
		self::assertTrue($out['sent']);
	}

	public function testSendIncidentHappyPathMailsClientContact(): void
	{
		[$svc, $mailer] = $this->entryMail();
		$msg = $this->createMock(\OCP\Mail\IMessage::class);
		$msg->expects($this->once())->method('setTo')->with(['kunde@example.com' => 'kunde@example.com']);
		$mailer->method('createMessage')->willReturn($msg);
		$mailer->expects($this->once())->method('send')->with($msg);
		$out = $svc->sendIncident(7, null);
		self::assertTrue($out['sent']);
		self::assertSame('kunde@example.com', $out['sent_to']);
	}

	public function testSendIncidentAttachesPhoto(): void
	{
		[$svc, $mailer, $photos] = $this->entryMail(true);
		$file = $this->createMock(\OCP\Files\SimpleFS\ISimpleFile::class);
		$file->method('getContent')->willReturn('jpeg-bytes');
		$photos->method('read')->willReturn($file);
		$att = $this->createMock(\OCP\Mail\IAttachment::class);
		$mailer->expects($this->once())->method('createAttachment')->with('jpeg-bytes', 'incident-7.jpg', 'image/jpeg')->willReturn($att);
		$msg = $this->createMock(\OCP\Mail\IMessage::class);
		$msg->expects($this->once())->method('attach')->with($att);
		$mailer->method('createMessage')->willReturn($msg);
		$out = $svc->sendIncident(7, null);
		self::assertTrue($out['sent']);
	}

	/** @return array{0: EntryMailService, 1: IMailer&MockObject, 2: PhotoService&MockObject} */
	private function entryMail(bool $withPhoto = false): array
	{
		$site = new Site();
		$site->setId(1);
		$site->setName('Gate A');
		$site->setClientContact('kunde@example.com');
		$inc = new Incident();
		$inc->setId(7);
		$inc->setSiteId(1);
		$inc->setCategory('security');
		$inc->setSeverity('high');
		$inc->setCreatedAt(new \DateTime('2026-11-12 05:00'));
		$inc->setPhotoFileid($withPhoto ? 11 : null);
		$incidents = $this->createMock(IncidentMapper::class);
		$incidents->method('find')->willReturn($inc);
		$sites = $this->createMock(SiteMapper::class);
		$sites->method('find')->willReturn($site);
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanSuperviseSite')->willReturn('super1');
		$mailer = $this->createMock(IMailer::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s, $p = []) => vsprintf($s, (array)$p));
		$photos = $this->createMock(PhotoService::class);
		$svc = new EntryMailService(
			$incidents,
			$this->createMock(LogbookMapper::class),
			$sites,
			$photos,
			$acl,
			$this->createMock(EventService::class),
			$this->createMock(\OCA\TourCheck\Service\RateLimitService::class),
			$mailer,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
		return [$svc, $mailer, $photos];
	}
}
