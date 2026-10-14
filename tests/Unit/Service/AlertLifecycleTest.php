<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Alert;
use OCA\TourCheck\Db\AlertDeliveryMapper;
use OCA\TourCheck\Db\AlertMapper;
use OCA\TourCheck\Db\EscalationLeg;
use OCA\TourCheck\Db\EscalationLegMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\AlertService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\NotificationService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Alert lifecycle (F2/F4): fire is idempotent on client_event_id AND
 * adopts the winner on a unique-key race; ack/resolve are terminal-gated;
 * false-alarm self-resolve belongs to the affected worker only; the
 * escalation sweep claims each leg via tck_alert_delivery so concurrent
 * sweeps can never double-dispatch (NN-04).
 */
final class AlertLifecycleTest extends TestCase
{
	private AlertMapper&MockObject $alerts;
	private AlertDeliveryMapper&MockObject $deliveries;
	private EscalationLegMapper&MockObject $legs;
	private AccessControlService&MockObject $acl;
	private NotificationService&MockObject $notify;
	private EventService&MockObject $events;
	private WebhookService&MockObject $webhooks;
	private SiteService&MockObject $sites;
	private AlertService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->alerts = $this->createMock(AlertMapper::class);
		$this->deliveries = $this->createMock(AlertDeliveryMapper::class);
		$this->legs = $this->createMock(EscalationLegMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->notify = $this->createMock(NotificationService::class);
		$this->events = $this->createMock(EventService::class);
		$this->webhooks = $this->createMock(WebhookService::class);
		$this->sites = $this->createMock(SiteService::class);
		$site = new \OCA\TourCheck\Db\Site();
		$site->setId(3);
		$site->setName('HQ');
		$this->sites->method('findById')->willReturn($site);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);
		$this->svc = new AlertService(
			$this->alerts, $this->deliveries, $this->legs, $this->acl,
			$this->notify, $this->sites, $this->events,
			$this->webhooks, $this->createMock(IMailer::class),
			$this->createMock(IUserManager::class), $time,
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function alert(string $state = 'fired', ?string $worker = 'w1'): Alert
	{
		$a = new Alert();
		$a->setId(42);
		$a->setSiteId(3);
		$a->setType(Constants::ALERT_MANDOWN);
		$a->setWorkerUid($worker);
		$a->setState($state);
		$a->setSeverity('critical');
		$a->setEscalatedLeg(0);
		$a->setFiredAt(clone $this->now);
		return $a;
	}

	public function testFireReplaysStoredRowOnSameClientEventId(): void
	{
		$existing = $this->alert();
		$this->alerts->method('findByClientEventId')->with('cev_1')->willReturn($existing);
		$this->alerts->expects($this->never())->method('insert');
		$this->assertSame($existing, $this->svc->fire(Constants::ALERT_MANDOWN, 3, 'w1', 'critical', [], 'cev_1'));
	}

	public function testFireAdoptsWinnerOnUniqueRace(): void
	{
		$winner = $this->alert();
		$this->alerts->method('findByClientEventId')->willReturnOnConsecutiveCalls(null, $winner);
		$e = $this->createMock(\OCP\DB\Exception::class);
		$e->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->alerts->method('insert')->willThrowException($e);
		$this->events->expects($this->never())->method('journalSignal');
		$this->assertSame($winner, $this->svc->fire(Constants::ALERT_MANDOWN, 3, 'w1', 'critical', [], 'cev_1'));
	}

	public function testFireRejectsUnknownSeverityToHigh(): void
	{
		$this->alerts->method('findByClientEventId')->willReturn(null);
		$this->alerts->expects($this->once())->method('insert')
			->with($this->callback(static fn (Alert $a) => $a->getSeverity() === 'high'))
			->willReturnArgument(0);
		$this->svc->fire(Constants::ALERT_SOS, 3, 'w1', 'bogus', [], '');
	}

	public function testAckOnResolvedThrows(): void
	{
		$this->alerts->method('findForUpdate')->willReturn($this->alert('resolved'));
		$this->expectException(ValidationException::class);
		$this->svc->ack(42, 'sup1');
	}

	public function testAckIsIdempotent(): void
	{
		$a = $this->alert('acked');
		$a->setAckedAt(clone $this->now);
		$this->alerts->method('findForUpdate')->willReturn($a);
		$this->alerts->expects($this->never())->method('update');
		$this->assertSame($a, $this->svc->ack(42, 'sup1'));
	}

	public function testResolveNeedsNote(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->resolve(42, 'sup1', 'short');
	}

	public function testFalseAlarmResolveIsWorkerOnly(): void
	{
		// attacker uid is not the alert's worker → uniform 404, no state read
		$this->alerts->method('findForUpdate')->willReturn($this->alert('fired', 'victim'));
		$this->expectException(NotFoundException::class);
		$this->svc->resolve(42, 'attacker', 'I am fine, false alarm', true);
	}

	public function testFalseAlarmByWorkerResolves(): void
	{
		$a = $this->alert('fired', 'w1');
		$this->alerts->method('findForUpdate')->willReturn($a);
		$this->alerts->expects($this->once())->method('update')
			->with($this->callback(static fn (Alert $x) => $x->getState() === 'resolved_false_alarm'));
		$this->svc->resolve(42, 'w1', 'I am fine, false alarm', true);
	}

	public function testSweepClaimsEachLegOnce(): void
	{
		$a = $this->alert();
		$a->setFiredAt((clone $this->now)->modify('-10 minutes'));
		$this->alerts->method('findOpen')->willReturn([$a]);
		$leg = new EscalationLeg();
		$leg->setSiteId(3);
		$leg->setLegNo(1);
		$leg->setDelayMin(0);
		$leg->setChannel('nc_notify');
		$leg->setTargetJson('{"role":"supervisor"}');
		$this->legs->method('findForSite')->willReturn([$leg]);
		// leg already claimed → no dispatch, no markSent
		$this->deliveries->method('claim')->willReturn(false);
		$this->notify->expects($this->never())->method('notifySite');
		$this->deliveries->expects($this->never())->method('markSent');
		$this->assertSame(0, $this->svc->sweepEscalations());
	}

	public function testSweepDispatchesClaimedDueLeg(): void
	{
		$a = $this->alert();
		$a->setFiredAt((clone $this->now)->modify('-10 minutes'));
		$this->alerts->method('findOpen')->willReturn([$a]);
		$leg = new EscalationLeg();
		$leg->setSiteId(3);
		$leg->setLegNo(1);
		$leg->setDelayMin(5);
		$leg->setChannel('nc_notify');
		$leg->setTargetJson('{"role":"supervisor"}');
		$this->legs->method('findForSite')->willReturn([$leg]);
		$this->deliveries->method('claim')->willReturn(true);
		$this->notify->expects($this->once())->method('notifySite');
		$this->deliveries->expects($this->once())->method('markSent')->with(42, 1, $this->anything());
		$this->assertSame(1, $this->svc->sweepEscalations());
	}

	public function testSweepSkipsLegNotYetDue(): void
	{
		$a = $this->alert(); // fired now; leg delay 60min → not due
		$this->alerts->method('findOpen')->willReturn([$a]);
		$leg = new EscalationLeg();
		$leg->setSiteId(3);
		$leg->setLegNo(1);
		$leg->setDelayMin(60);
		$leg->setChannel('nc_notify');
		$leg->setTargetJson('{}');
		$this->legs->method('findForSite')->willReturn([$leg]);
		$this->deliveries->expects($this->never())->method('claim');
		$this->assertSame(0, $this->svc->sweepEscalations());
	}

	public function testSweepKeepsLegPendingOnDispatchFailure(): void
	{
		$a = $this->alert();
		$a->setFiredAt((clone $this->now)->modify('-10 minutes'));
		$this->alerts->method('findOpen')->willReturn([$a]);
		$leg = new EscalationLeg();
		$leg->setSiteId(3);
		$leg->setLegNo(1);
		$leg->setDelayMin(0);
		$leg->setChannel('nc_notify');
		$leg->setTargetJson('{}');
		$this->legs->method('findForSite')->willReturn([$leg]);
		$this->deliveries->method('claim')->willReturn(true);
		$this->notify->method('notifySite')->willThrowException(new \RuntimeException('broker down'));
		$this->deliveries->expects($this->once())->method('bumpAttempt')->with(42, 1);
		$this->deliveries->expects($this->never())->method('markSent');
		$this->assertSame(0, $this->svc->sweepEscalations());
	}
}
