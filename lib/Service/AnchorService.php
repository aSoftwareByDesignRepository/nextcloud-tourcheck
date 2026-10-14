<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Anchor;
use OCA\TourCheck\Db\AnchorMapper;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * External anchors — the completeness proof. A hash chain alone proves the
 * remaining rows are intact; only an external timestamp proves what existed
 * at time T (truncation at the tail is undetectable otherwise).
 *
 * Triggers (spec): round close · every 200 entries · nightly · pre-bundle.
 * The capture path never blocks on TSA — request rows go pending and the
 * sender job delivers them. Only claim bundles may wait, capped at 10 s,
 * and visibly degrade to UNANCHORED on failure.
 */
class AnchorService
{
	private const MAX_ATTEMPTS = 8;

	public function __construct(
		private readonly AnchorMapper $anchors,
		private readonly JournalEntryMapper $journal,
		private readonly TsaClient $tsa,
		private readonly SettingsService $settings,
		private readonly NotificationService $notifications,
		private readonly SiteService $sites,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Record a pending anchor for the current chain head. Empty days anchor
	 * too: the site seed (seq 0) is timestamped so "the site had no journal
	 * entries on this day" is itself externally provable.
	 */
	public function requestAnchor(int $siteId, string $trigger): ?Anchor
	{
		$head = $this->journal->findHead($siteId);
		$a = new Anchor();
		$a->setSiteId($siteId);
		$a->setSeq($head === null ? 0 : (int)$head->getSeq());
		$a->setHeadHash($head === null ? $this->settings->siteChainSeed($siteId) : (string)$head->getEntryHash());
		$a->setTriggerKind($trigger);
		$a->setStatus('pending');
		$a->setAttempts(0);
		$a->setRequestedAt($this->timeFactory->getDateTime());
		return $this->anchors->insert($a);
	}

	/** Anchor every site head if the journal grew ≥ N entries since the last anchor. */
	public function requestPeriodicIfDue(int $siteId): ?Anchor
	{
		$last = $this->anchors->findLatestAnchored($siteId);
		$since = $last === null ? $this->journal->countForSite($siteId)
			: $this->journal->countSinceSeq($siteId, (int)$last->getSeq());
		if ($since >= Constants::ANCHOR_EVERY_N) {
			return $this->requestAnchor($siteId, 'periodic');
		}
		return null;
	}

	/** Deliver pending anchors (cron). Exponential-ish backoff via attempts. */
	public function processPending(int $limit = 50): int
	{
		$url = $this->settings->getTsaUrl();
		if ($url === '') {
			return 0; // TSA not configured — anchors stay pending (visible)
		}
		$done = 0;
		foreach ($this->anchors->findPending($limit) as $anchor) {
			$done += $this->deliver($anchor, $url) ? 1 : 0;
		}
		return $done;
	}

	/** Send one anchor NOW (also used by the pre-bundle synchronous path). */
	public function deliver(Anchor $anchor, ?string $url = null): bool
	{
		$url ??= $this->settings->getTsaUrl();
		if ($url === '') {
			return false;
		}
		try {
			$ca = $this->settings->getTsaCaBundle();
			$raw = $this->tsa->request($url, (string)$anchor->getHeadHash());
			$token = $this->tsa->validate($raw, (string)$anchor->getHeadHash(), $ca === '' ? null : $ca);
			$anchor->setTsaToken($token);
			$anchor->setStatus('anchored');
			$anchor->setAnchoredAt($this->timeFactory->getDateTime());
			$anchor->setError(null);
			$this->anchors->update($anchor);
			return true;
		} catch (\Throwable $e) {
			$attempts = (int)$anchor->getAttempts() + 1;
			$anchor->setAttempts($attempts);
			$anchor->setError(mb_substr($e->getMessage(), 0, 400));
			// Past the retry budget the anchor is honestly UNANCHORED — the UI
			// and bundles show it; nothing pretends to be anchored.
			if ($attempts >= self::MAX_ATTEMPTS) {
				$anchor->setStatus('unanchored');
				$this->logger->error('tourcheck: anchor permanently failed', [
					'anchorId' => $anchor->getId(), 'siteId' => $anchor->getSiteId(), 'error' => $e->getMessage(),
				]);
				// Planners/supervisors must see this — an unanchored chain head
				// is a compliance gap, not a log line (spec §12).
				$site = $this->sites->findById((int)$anchor->getSiteId());
				if ($site !== null) {
					$this->notifications->notifySite(
						(int)$anchor->getSiteId(),
						'anchor_failed:' . $anchor->getId(),
						'anchor_failed',
						'External timestamping failed permanently — the chain head stays UNANCHORED.',
						$site,
					);
				}
			}
			$this->anchors->update($anchor);
			return false;
		}
	}

	/**
	 * Pre-bundle synchronous anchor, capped by the caller (~10 s). Returns
	 * the anchor state the bundle must display: 'anchored' | 'unanchored'.
	 */
	public function anchorForBundle(int $siteId): string
	{
		$anchor = $this->requestAnchor($siteId, 'bundle');
		return $anchor !== null && $this->deliver($anchor) ? 'anchored' : 'unanchored';
	}

	/** @return list<Anchor> */
	public function listForSite(int $siteId, int $limit = 50): array
	{
		return $this->anchors->findForSite($siteId, $limit);
	}

	public function latestState(int $siteId): ?string
	{
		return $this->anchors->findLatest($siteId)?->getStatus();
	}
}
