<?php

declare(strict_types=1);

namespace OCA\TourCheck\Controller;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\CheckpointService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\NotificationService;
use OCA\TourCheck\Service\RateLimitService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Util;
use OCP\IRequest;

/**
 * Worker web capture — the scan-first browser path (UX-2).
 * GET /capture/scan/{token} lands from a printed QR: the page shows the
 * confirm action immediately (one tap) or the corrective path (UX-1).
 */
class CaptureController extends Controller
{
	use ApiJsonTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $acl,
		private readonly EventService $events,
		private readonly TokenService $tokens,
		private readonly RoundService $rounds,
		private readonly SiteService $sites,
		private readonly CheckpointService $checkpoints,
		private readonly RateLimitService $rateLimit,
		private readonly NotificationService $notifications,
		private readonly ?\OCA\TourCheck\Service\IncidentService $incidents = null,
	) {
		parent::__construct($appName, $request);
	}

	/** The one round the worker is in right now (UX-2: no picking). */
	private function currentRoundData(string $uid): ?array
	{
		$siteIds = $this->acl->visibleSiteIds($uid);
		if ($siteIds === []) {
			return null;
		}
		foreach ($this->rounds->todayFor($siteIds, $this->acl->isAppAdmin($uid)) as $round) {
			if (!in_array($round->getStatus(), [Constants::ROUND_ACTIVE, Constants::ROUND_SCHEDULED], true)) {
				continue;
			}
			$done = 0;
			$items = $this->rounds->checkpointsOf((int)$round->getId());
			foreach ($items as $it) {
				if ($it->getStatus() === 'done' || $it->getStatus() === 'attested') {
					$done++;
				}
			}
			$site = $this->sites->findById((int)$round->getSiteId());
			return [
				'id' => (int)$round->getId(),
				'siteId' => (int)$round->getSiteId(),
				'siteName' => $site?->getName() ?? '',
				'doneCount' => $done,
				'totalCount' => count($items),
				'canClose' => $done > 0 && $done < count($items),
			];
		}
		return null;
	}

	/** Site the "Ask a supervisor" action reports to (UX-1): active round first, unambiguous single site next — null means multi-site with no round, where the fallback link takes over. */
	private function signalSiteId(string $uid, ?array $active): ?int
	{
		if ($active !== null) {
			return (int)$active['siteId'];
		}
		$ids = $this->acl->visibleSiteIds($uid);
		return count($ids) === 1 ? (int)$ids[0] : null;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function today(): TemplateResponse
	{
		$this->acl->assertAppAccess();
		$uid = $this->acl->requireUid();
		Util::addTranslations(Constants::APP_ID);
		$active = $this->currentRoundData($uid);
		return new TemplateResponse(Constants::APP_ID, 'capture', [
			'activeRound' => $active,
			'signalSiteId' => $this->signalSiteId($uid, $active),
			'prefillToken' => '',
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function scan(string $token): TemplateResponse
	{
		$this->acl->assertAppAccess();
		$uid = $this->acl->requireUid();
		$active = $this->currentRoundData($uid);
		$response = new TemplateResponse(Constants::APP_ID, 'capture', [
			'activeRound' => $active,
			'signalSiteId' => $this->signalSiteId($uid, $active),
			'prefillToken' => $token,
		]);
		$response->addHeader('X-Frame-Options', 'DENY');
		return $response;
	}

	/**
	 * POST /api/capture/resolve — preview only, no ingest.
	 * Returns the checkpoint name + the corrective action on failure (UX-1).
	 */
	#[NoAdminRequired]
	public function resolve(): JSONResponse
	{
		try {
			$this->acl->assertAppAccess();
			$ip = (string)$this->request->getRemoteAddress();
			$this->rateLimit->hitTokenResolve($ip);
			$token = (string)$this->request->getParam('token', '');
			[$cp] = $this->tokens->resolve($token, $ip);
			// Membership: the worker must be allowed on the checkpoint's site.
			$this->acl->assertCanViewSite((int)$cp->getSiteId());
			return $this->ok([
				'checkpointName' => $cp->getName(),
				'checkpointId' => (int)$cp->getId(),
			]);
		} catch (NotFoundException $e) {
			// Client renders the corrective action on NOT_FOUND (UX-1).
			return $this->notFound();
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * POST /api/capture/signal — worker's "something is wrong" button.
	 * Deduped notification to the site's supervisors + chained journal entry.
	 */
	#[NoAdminRequired]
	public function signal(): JSONResponse
	{
		try {
			$uid = $this->acl->assertAppAccess();
			$b = $this->request->getParams();
			$siteId = $this->strictId($b['site_id'] ?? null, 'site_id');
			$this->acl->assertCanViewSite($siteId);
			$site = $this->sites->findById($siteId);
			$signal = preg_replace('/[^a-z0-9_:-]/i', '', (string)($b['signal'] ?? 'help_request'));
			$isPanic = $signal === 'panic';
			$this->events->journalSignal($siteId, ($isPanic ? 'panic' : 'help_request') . ':' . $signal, [
				'actor_uid' => $uid,
				'client_event_id' => 'sig_' . bin2hex(random_bytes(8)),
			]);
			if ($isPanic && $this->incidents !== null) {
				// NH-13: panic is a conscious emergency — mint a critical
				// incident + escalate, not just a help signal. Deduped via a
				// deterministic client id so a held/re-tapped SOS cannot
				// stack incidents.
				try {
					$roundId = null;
					if (isset($b['round_id']) && $b['round_id'] !== '') {
						$r = $this->rounds->findById((int)$b['round_id']);
						if ($r !== null && (int)$r->getSiteId() === $siteId) {
							$roundId = (int)$r->getId();
						}
					}
					$this->incidents->createSystem($siteId, [
						'client_incident_id' => 'panic-' . $siteId . '-' . $uid . '-' . gmdate('YmdH'),
						'category' => 'safety',
						'severity' => 'critical',
						'title' => 'PANIC: ' . $uid . ' at ' . ($site?->getName() ?? 'site ' . $siteId),
						'note' => 'Emergency action triggered by ' . $uid . ' at ' . ($site?->getName() ?? 'site ' . $siteId) . '.',
						'round_id' => $roundId,
					]);
				} catch (\Throwable) {
					// Incident creation must never mask the alert — the
					// notification below is the escalation of record.
				}
			}
			$sent = $this->notifications->notifySite(
				$siteId,
				($isPanic ? 'panic:' : 'help:') . $siteId . ':' . $uid . ':' . gmdate('YmdH'),
				$isPanic ? 'panic_alert' : 'help_request',
				$uid . ' signalled "' . $signal . '" at ' . ($site?->getName() ?? 'site ' . $siteId) . '.',
				$site,
				['actor' => $uid, 'signal' => $signal],
			);
			return $this->ok(['notified' => $sent]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** POST /api/capture/confirm — session-authenticated ingest. */
	#[NoAdminRequired]
	public function confirm(): JSONResponse
	{
		try {
			$uid = $this->acl->assertAppAccess();
			$b = $this->request->getParams();
			$ip = (string)$this->request->getRemoteAddress();
			$this->rateLimit->hitTokenResolve($ip);
			// Idempotent web confirm: deterministic client_event_id per
			// (worker, checkpoint, round) — a double-tap replays, never orphans.
			$clientEventId = (string)($b['client_event_id'] ?? '');
			$roundId = isset($b['round_id']) ? $this->strictId($b['round_id'], 'round_id') : null;
			if ($clientEventId === '') {
				[$cp] = $this->tokens->resolve((string)($b['token'] ?? ''), $ip);
				[$attachRound] = $this->rounds->attach((int)$cp->getSiteId(), (int)$cp->getId(), new \DateTimeImmutable(), $roundId);
				$roundId = $attachRound?->getId();
				$clientEventId = 'web_' . $uid . '_' . (int)$cp->getId() . '_' . (int)$roundId;
			}
			$result = $this->events->ingest([
				'token' => (string)($b['token'] ?? ''),
				'round_id' => $roundId,
				'client_event_id' => $clientEventId,
				'client_ts' => $b['client_ts'] ?? null,
				'values' => $b['values'] ?? null,
				'note' => $b['note'] ?? null,
				'geo' => $b['geo'] ?? null,
				'auto_incident' => $b['auto_incident'] ?? null,
				'photo_fileid' => isset($b['photo_fileid']) ? $this->strictId($b['photo_fileid'], 'photo_fileid') : null,
				'method' => Constants::METHOD_SCAN_QR,
				'actor_uid' => $uid,
				'ip' => $ip,
			]);
			$progress = 'Checkpoint confirmed';
			if ($result['event']->getRoundId() !== null) {
				$roundData = null;
				try { $roundData = $this->currentRoundData($uid); } catch (\Throwable) { $roundData = null; }
				if ($roundData !== null) {
					$progress = $roundData['doneCount'] . ' / ' . $roundData['totalCount'] . ' — ' . $roundData['siteName'];
				}
			}
			return $this->ok([
				'event' => $result['event']->jsonSerialize(),
				'replayed' => $result['replayed'],
				'mismatch' => $result['mismatch'] ?? false,
				'attached' => $result['attached'],
				'progress' => $progress,
			], $result['replayed'] ? Http::STATUS_OK : Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** POST /api/capture/close — worker finishes a round early (UX-3). */
	#[NoAdminRequired]
	public function close(): JSONResponse
	{
		try {
			$this->acl->assertAppAccess();
			$roundId = (int)$this->request->getParam('round_id', 0);
			$round = $this->rounds->closeEarly($roundId);
			return $this->ok(['round' => $round->jsonSerialize()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
