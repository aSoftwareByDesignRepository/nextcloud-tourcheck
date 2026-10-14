<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\LiveLink;
use OCA\TourCheck\Db\LiveLinkMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Public live object page — the "no portal for the customer" feature.
 * Opaque 192-bit path token (hashed at rest), read-only payload, no-store +
 * noindex headers set by the controller, IP-throttled by RateLimitService.
 * Revocable + optional expiry. Never mutates anything.
 */
class LivePageService
{
	public function __construct(
		private readonly LiveLinkMapper $links,
		private readonly RoundService $rounds,
		private readonly SiteService $sites,
		private readonly AccessControlService $acl,
		private readonly IncidentMapper $incidents,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/** @return array{0: LiveLink, 1: string} raw token shown once */
	public function create(int $siteId, string $label, ?string $expiresAt): array
	{
		$this->acl->assertCanManageSite($siteId);
		$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$link = new LiveLink();
		$link->setSiteId($siteId);
		$link->setTokenHash(hash('sha256', $token));
		$link->setLabel(trim($label) !== '' ? mb_substr(trim($label), 0, 190) : null);
		if ($expiresAt !== null && $expiresAt !== '') {
			$d = \DateTime::createFromFormat('Y-m-d', $expiresAt);
			if ($d === false) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Expiry must be YYYY-MM-DD.', ['expires_at' => 'invalid']);
			}
			$link->setExpiresAt($d->setTime(23, 59, 59));
		}
		$link->setCreatedAt($this->timeFactory->getDateTime());
		$link = $this->links->insert($link);
		return [$link, $token];
	}

	/** @return list<LiveLink> */
	public function listForSite(int $siteId): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->links->findForSite($siteId);
	}

	public function revoke(int $linkId): void
	{
		$link = $this->links->find($linkId);
		if ($link === null) {
			throw new NotFoundException();
		}
		$this->acl->assertCanManageSite((int)$link->getSiteId());
		$link->setRevokedAt($this->timeFactory->getDateTime());
		$this->links->update($link);
	}

	/** Resolve token → site payload (uniform failure: revoked/expired/unknown look alike). */
	public function resolve(string $token): array
	{
		if (strlen($token) < 40 || strlen($token) > 128) {
			throw new NotFoundException();
		}
		$link = $this->links->findByTokenHash(hash('sha256', $token));
		$now = $this->timeFactory->getDateTime();
		if ($link === null || $link->getRevokedAt() !== null
			|| ($link->getExpiresAt() !== null && $link->getExpiresAt() < $now)) {
			throw new NotFoundException();
		}
		$site = $this->sites->findById((int)$link->getSiteId());
		if ($site === null || (int)$site->getActive() !== 1) {
			throw new NotFoundException();
		}
		$rounds = $this->rounds->todayFor([(int)$site->getId()], false);
		$done = 0; $missed = 0; $rows = [];
		$lastCompleted = null;
		foreach ($rounds as $r) {
			$status = (string)$r->getStatus();
			// Round statuses are scheduled|active|completed|missed|skipped —
			// 'done'/'attested' are checkpoint-level states, never round states.
			if ($status === Constants::ROUND_COMPLETED) { $done++; }
			if ($status === Constants::ROUND_MISSED) { $missed++; }
			$completedAt = $r->getCompletedAt();
			if ($completedAt !== null && ($lastCompleted === null || $completedAt > $lastCompleted)) {
				$lastCompleted = $completedAt;
			}
			if (count($rows) < 20) {
				$rows[] = [
					'windowLabel' => $r->getScheduledStart()->format('H:i')
						. '–' . $r->getWindowEnd()->format('H:i'),
					'status' => $status,
				];
			}
		}
		$payload = [
			'siteName' => $site->getName(),
			'updatedLabel' => $now->format('H:i'),
			'doneToday' => $done,
			'missedToday' => $missed,
			'openIncidents' => $this->incidents->countOpenForSite((int)$site->getId()),
			'lastCompletedLabel' => $lastCompleted?->format('H:i'),
			'rounds' => $rows,
		];
		return ['link' => $link, 'site' => $site, 'payload' => $payload];
	}
}
