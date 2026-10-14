<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\Webhook;
use OCA\TourCheck\Db\WebhookDelivery;
use OCA\TourCheck\Db\WebhookDeliveryMapper;
use OCA\TourCheck\Db\WebhookMapper;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Webhooks (F10): HTTPS-only endpoints (HMAC over http is signed
 * plaintext), the secret is snapshotted onto every queued delivery so
 * rotation never breaks in-flight retries, emit dedupes on the unique
 * (webhook, event_key), and retry is bounded — failed lands as
 * dead-letter, not an infinite loop.
 */
final class WebhookServiceTest extends TestCase
{
	private WebhookMapper&MockObject $webhooks;
	private WebhookDeliveryMapper&MockObject $deliveries;
	private AccessControlService&MockObject $acl;
	private IClientService&MockObject $http;
	private WebhookService $svc;
	private \DateTime $now;

	protected function setUp(): void
	{
		parent::setUp();
		$this->now = new \DateTime('2026-10-20T12:00:00Z');
		$this->webhooks = $this->createMock(WebhookMapper::class);
		$this->deliveries = $this->createMock(WebhookDeliveryMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->http = $this->createMock(IClientService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => clone $this->now);
		$this->svc = new WebhookService(
			$this->webhooks, $this->deliveries, $this->acl,
			$this->http, $time, $this->createMock(LoggerInterface::class),
		);
	}

	private function endpoint(int $id = 5, string $events = '["alert_fired"]', string $secret = 's3cr3t'): Webhook
	{
		$w = new Webhook();
		$w->setId($id);
		$w->setUrl('https://ops.example.test/hook');
		$w->setSecret($secret);
		$w->setEventsJson($events);
		$w->setActive(1);
		return $w;
	}

	public function testCreateRejectsHttpUrl(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->createEndpoint('http://ops.example.test/hook', ['alert_fired']);
	}

	public function testCreateRejectsEmptyEventSet(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->createEndpoint('https://ops.example.test/hook', ['nonsense']);
	}

	public function testCreateGeneratesSecretWhenAbsent(): void
	{
		$this->webhooks->expects($this->once())->method('insert')
			->with($this->callback(static fn (Webhook $w) => is_string($w->getSecret()) && strlen((string)$w->getSecret()) >= 32))
			->willReturnArgument(0);
		$this->svc->createEndpoint('https://ops.example.test/hook', ['alert_fired']);
	}

	public function testEmitSnapshotsSecretOntoDelivery(): void
	{
		$this->webhooks->method('findAll')->with(true)->willReturn([$this->endpoint()]);
		$this->deliveries->expects($this->once())->method('insert')
			->with($this->callback(static fn (WebhookDelivery $d) => $d->getSecret() === 's3cr3t'
				&& $d->getWebhookId() === 5 && $d->getStatus() === 'pending' && (int)$d->getAttempt() === 0));
		$this->svc->emit('alert_42', 'alert_fired', ['alert_id' => 42]);
	}

	public function testEmitSkipsUnsubscribedEndpoint(): void
	{
		$this->webhooks->method('findAll')->willReturn([$this->endpoint(5, '["task_done"]')]);
		$this->deliveries->expects($this->never())->method('insert');
		$this->svc->emit('alert_42', 'alert_fired', []);
	}

	public function testEmitDedupesOnUniqueCollision(): void
	{
		$this->webhooks->method('findAll')->willReturn([$this->endpoint()]);
		$e = $this->createMock(\OCP\DB\Exception::class);
		$e->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->deliveries->method('insert')->willThrowException($e);
		$this->svc->emit('alert_42', 'alert_fired', []); // must not throw
		$this->addToAssertionCount(1);
	}

	public function testSweepSignsWithDeliverySecretSnapshot(): void
	{
		$d = new WebhookDelivery();
		$d->setId(11);
		$d->setWebhookId(5);
		$d->setEventKey('alert_42');
		$d->setPayloadJson('{"event":"alert_fired"}');
		$d->setSecret('old-secret'); // rotated endpoint has a different secret
		$d->setStatus('pending');
		$d->setAttempt(0);
		$this->deliveries->method('findDue')->willReturn([$d]);
		$this->webhooks->method('find')->willReturn($this->endpoint(5, '["alert_fired"]', 'new-secret'));

		$client = $this->createMock(IClient::class);
		$resp = $this->createMock(IResponse::class);
		$resp->method('getStatusCode')->willReturn(200);
		$this->http->method('newClient')->willReturn($client);
		$expectedSig = 'sha256=' . hash_hmac('sha256', '{"event":"alert_fired"}', 'old-secret');
		$client->expects($this->once())->method('post')
			->with('https://ops.example.test/hook', $this->callback(
				static fn (array $o) => $o['headers']['X-TourCheck-Signature'] === $expectedSig
			))
			->willReturn($resp);
		$out = $this->svc->sweepDeliveries();
		$this->assertSame(['sent' => 1, 'failed' => 0], $out);
	}

	public function testSweepCancelsDeliveriesForInactiveEndpoint(): void
	{
		$d = new WebhookDelivery();
		$d->setWebhookId(5);
		$d->setStatus('pending');
		$this->deliveries->method('findDue')->willReturn([$d]);
		$w = $this->endpoint();
		$w->setActive(0);
		$this->webhooks->method('find')->willReturn($w);
		$this->http->expects($this->never())->method('newClient');
		$this->assertSame(['sent' => 0, 'failed' => 0], $this->svc->sweepDeliveries());
		$this->assertSame('cancelled', $d->getStatus());
	}

	public function testSweepSchedulesRetryThenDeadLetters(): void
	{
		$this->http->method('newClient')->willThrowException(new \RuntimeException('connect fail'));
		// First failure → retry scheduled, still pending
		$d = new WebhookDelivery();
		$d->setWebhookId(5);
		$d->setStatus('pending');
		$d->setAttempt(0);
		$this->deliveries->method('findDue')->willReturn([$d]);
		$this->webhooks->method('find')->willReturn($this->endpoint());
		$out = $this->svc->sweepDeliveries();
		$this->assertSame(['sent' => 0, 'failed' => 0], $out);
		$this->assertSame('pending', $d->getStatus());
		$this->assertNotNull($d->getNextRetryAt());

		// Final attempt → failed, no further retry
		$d2 = new WebhookDelivery();
		$d2->setWebhookId(5);
		$d2->setStatus('pending');
		$d2->setAttempt(99); // beyond backoff table
		$this->deliveries = $this->createMock(WebhookDeliveryMapper::class);
		$this->deliveries->method('findDue')->willReturn([$d2]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(clone $this->now);
		$svc = new WebhookService(
			$this->webhooks, $this->deliveries, $this->acl,
			$this->http, $time, $this->createMock(LoggerInterface::class),
		);
		$out = $svc->sweepDeliveries();
		$this->assertSame(['sent' => 0, 'failed' => 1], $out);
		$this->assertSame('failed', $d2->getStatus());
		$this->assertNull($d2->getNextRetryAt());
	}
}
