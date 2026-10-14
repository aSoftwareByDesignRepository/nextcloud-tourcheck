<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Webhook;
use OCA\TourCheck\Db\WebhookDelivery;
use OCA\TourCheck\Db\WebhookDeliveryMapper;
use OCA\TourCheck\Db\WebhookMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Outbound webhooks — emit() fans an event out to one delivery row per
 * subscribed active webhook (unique (webhook,event) = the dedupe claim).
 * sweep() delivers pending rows with bounded backoff; terminal 'failed'
 * rows surface on the admin screen — never silently dropped.
 */
class WebhookService
{
	public const EVENTS = ['alert_fired', 'alert_escalated', 'incident_opened', 'round_missed', 'coverage_gap'];
	private const BACKOFF_S = [60, 300, 900]; // attempts 1m/5m/15m then failed
	private const TIMEOUT_S = 5;

	public function __construct(
		private readonly WebhookMapper $webhooks,
		private readonly WebhookDeliveryMapper $deliveries,
		private readonly AccessControlService $acl,
		private readonly IClientService $http,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/** Fan-out — called inside the producing flow; dupes collapse on the unique key. */
	public function emit(string $eventKey, string $type, array $payload): void
	{
		foreach ($this->webhooks->findAll(true) as $wh) {
			$subs = json_decode((string)$wh->getEventsJson(), true) ?? [];
			if (!in_array($type, $subs, true)) {
				continue;
			}
			$qb2 = new WebhookDelivery();
			$qb2->setWebhookId((int)$wh->getId());
			$qb2->setEventKey(mb_substr($eventKey, 0, 64));
			$qb2->setPayloadJson(json_encode(['event' => $type] + $payload));
			$qb2->setSecret((string)$wh->getSecret());
			$qb2->setStatus('pending');
			$qb2->setAttempt(0);
			$qb2->setCreatedAt($this->timeFactory->getDateTime());
			try {
				$this->deliveries->insert($qb2);
			} catch (\OCP\DB\Exception $e) {
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// same event to same endpoint already queued — dedupe
			}
		}
	}

	/** Cron sweep — delivers due rows; returns counts for observability. */
	public function sweepDeliveries(): array
	{
		$now = $this->timeFactory->getDateTime();
		$sent = $failed = 0;
		foreach ($this->deliveries->findDue($now, 50) as $d) {
			$wh = $this->webhooks->find((int)$d->getWebhookId());
			if ($wh === null || (int)$wh->getActive() !== 1) {
				$d->setStatus('cancelled');
				$this->deliveries->update($d);
				continue;
			}
			$body = (string)$d->getPayloadJson();
			try {
				$client = $this->http->newClient();
				$resp = $client->post((string)$wh->getUrl(), [
					'timeout' => self::TIMEOUT_S,
					'headers' => [
						'Content-Type' => 'application/json',
						'X-TourCheck-Signature' => 'sha256=' . hash_hmac('sha256', $body, (string)$d->getSecret()),
						'X-TourCheck-Event' => $d->getEventKey(),
					],
					'body' => $body,
				]);
				$code = $resp->getStatusCode();
				$d->setResponseCode($code);
				if ($code >= 200 && $code < 300) {
					$d->setStatus('sent');
				} else {
					$this->retryOrFail($d, $now);
				}
				if ($d->getStatus() === 'sent') {
					$sent++;
				}
			} catch (\Throwable $e) {
				$this->retryOrFail($d, $now);
				$this->logger->warning('tourcheck: webhook delivery failed', [
					'webhook' => (int)$d->getWebhookId(), 'event' => $d->getEventKey(), 'e' => $e->getMessage(),
				]);
			}
			$d->setAttempt((int)$d->getAttempt() + 1);
			$this->deliveries->update($d);
			if ($d->getStatus() === 'failed') {
				$failed++;
			}
		}
		return ['sent' => $sent, 'failed' => $failed];
	}

	private function retryOrFail(WebhookDelivery $d, \DateTimeInterface $now): void
	{
		$attempt = (int)$d->getAttempt();
		if ($attempt >= count(self::BACKOFF_S)) {
			$d->setStatus('failed'); // bounded retry — dead-letter visible in admin
			$d->setNextRetryAt(null);
			return;
		}
		$d->setNextRetryAt((new \DateTime())->setTimestamp($now->getTimestamp() + self::BACKOFF_S[$attempt]));
	}

	/** @return list<Webhook> admin list */
	public function listEndpoints(): array
	{
		$this->acl->assertAppAdmin();
		return $this->webhooks->findAll();
	}

	/** @return Webhook the created row (secret in plaintext only via getSecret) */
	public function createEndpoint(string $url, array $events, ?string $secret = null): Webhook
	{
		$this->acl->assertAppAdmin();
		$fields = [];
		if (!preg_match('#^https://#i', $url)) {
			$fields['url'] = 'invalid'; // HMAC over http = signed plaintext on the wire
		}
		$events = array_values(array_filter($events, static fn($e) => in_array($e, self::EVENTS, true)));
		if ($events === []) {
			$fields['events'] = 'required';
		}
		if ($fields !== []) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid webhook.', $fields);
		}
		$wh = new Webhook();
		$wh->setUrl(mb_substr(trim($url), 0, 190));
		$wh->setSecret($secret !== null && $secret !== '' ? $secret : bin2hex(random_bytes(24)));
		$wh->setEventsJson(json_encode($events));
		$wh->setActive(1);
		$wh->setCreatedAt($this->timeFactory->getDateTime());
		return $this->webhooks->insert($wh);
	}

	/** Rotate the HMAC secret — queued deliveries carry their own snapshot,
	 * so in-flight retries keep signing with the OLD secret until their
	 * final attempt while new deliveries use the new one (US-F10-01 AC-3). */
	public function rotateSecret(int $id): Webhook
	{
		$this->acl->assertAppAdmin();
		$wh = $this->webhooks->find($id);
		if ($wh === null) {
			throw new NotFoundException();
		}
		$wh->setSecret(bin2hex(random_bytes(24)));
		$this->webhooks->update($wh);
		return $wh;
	}

	public function deleteEndpoint(int $id): void
	{
		$this->acl->assertAppAdmin();
		if ($this->webhooks->find($id) === null) {
			throw new NotFoundException();
		}
		$this->webhooks->deleteById($id);
	}

	/** @return list<WebhookDelivery> */
	public function recentDeliveries(int $webhookId): array
	{
		$this->acl->assertAppAdmin();
		return $this->deliveries->findRecent($webhookId);
	}
}
