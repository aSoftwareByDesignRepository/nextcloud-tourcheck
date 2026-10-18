<?php

declare(strict_types=1);

namespace OCA\TourCheck\Integration;

use OCA\ArbeitszeitCheck\Public\ExternalStampDraftFacade;
use OCA\TourCheck\Db\CheckEvent;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * F16: TourCheck → ArbeitszeitCheck first-scan stamp proposal.
 *
 * A round's first attached evidence is the worker's shift-start signal —
 * we propose it as a PENDING draft stamp in AZC, where only the affected
 * worker confirms or dismisses it. Evidence is never counted time by
 * itself; no proposal auto-mints a ledger entry.
 *
 * Fail-closed by construction: AZC absent/disabled, facade missing/too
 * old, or any error → the push is dropped and logged, never an error
 * surfaced to the scan path. Idempotent on the 'round:{id}:start'
 * sourceRef — a post-commit double-fire or ingest lock race converges
 * to the one stored draft.
 *
 * Data minimization: only actor uid + timestamp + a stable source ref
 * cross the seam — no site, checkpoint, or client metadata.
 */
class ArbeitszeitStampPushService
{
	private const AZC_APP_ID = 'arbeitszeitcheck';
	private const SOURCE_APP = 'tourcheck';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool
	{
		try {
			return $this->appManager->isEnabledForUser(self::AZC_APP_ID)
				&& class_exists(ExternalStampDraftFacade::class)
				&& ExternalStampDraftFacade::FACADE_VERSION >= 1;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Propose a pending stamp for the round's first evidence. Never throws —
	 * the evidence transaction already committed and must not be affected.
	 *
	 * @return bool true when AZC accepted (or deduped) the proposal
	 */
	public function proposeFirstScan(CheckEvent $event): bool
	{
		$actorUid = (string)($event->getActorUid() ?? '');
		$roundId = (int)($event->getRoundId() ?? 0);
		if ($actorUid === '' || $roundId <= 0 || !$this->isAvailable()) {
			return false;
		}
		// The scan's own timestamp is the evidence claim; server_received_at
		// is the fallback for scans without a client_ts. The facade rejects
		// impossible timestamps (pre-2000, future beyond skew grace).
		$ts = $event->getClientTs() ?? $event->getServerReceivedAt();
		try {
			$facade = \OCP\Server::get(ExternalStampDraftFacade::class);
			$result = $facade->propose(
				$actorUid,
				(int)$ts->format('U'),
				null,
				self::SOURCE_APP,
				'round:' . $roundId . ':start',
			);
			return $result !== null;
		} catch (\Throwable $e) {
			$this->logger->info('tourcheck → arbeitszeitcheck stamp proposal failed', ['exception' => $e->getMessage()]);
			return false;
		}
	}
}
