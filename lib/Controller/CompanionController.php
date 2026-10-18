<?php

declare(strict_types=1);

namespace OCA\TourCheck\Controller;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\IncidentService;
use OCA\TourCheck\Service\LicenseService;
use OCA\TourCheck\Service\PhotoService;
use OCA\TourCheck\Service\PositionService;
use OCA\TourCheck\Service\RateLimitService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\StationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Companion API — the native app's only surface.
 *
 * Auth split (spec §6):
 *  - Worker endpoints: Nextcloud app-password session → Family A licensing.
 *  - Station endpoints: Bearer station secret → Family C licensing.
 * License walls respond 402 with fixed codes the client maps to
 * @check/onboarding walls (no_seat / license_required / no_device).
 */
class CompanionController extends Controller
{
	use ApiJsonTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $acl,
		private readonly EventService $events,
		private readonly RoundService $rounds,
		private readonly IncidentService $incidents,
		private readonly PositionService $positions,
		private readonly PhotoService $photos,
		private readonly SiteService $sites,
		private readonly StationService $stations,
		private readonly \OCA\TourCheck\Service\TokenService $tokens,
		private readonly LicenseService $license,
		private readonly RateLimitService $rateLimit,
		private readonly \Psr\Log\LoggerInterface $logger,
		private readonly \OCA\TourCheck\Db\TourPlanMapper $plans,
		private readonly \OCA\TourCheck\Integration\PoolVehicleService $poolVehicles,
		private readonly \OCA\TourCheck\Service\ChainService $chain,
		private readonly \OCA\TourCheck\Service\KeyService $keyService,
		private readonly \OCA\TourCheck\Service\ManDownService $manDown,
		private readonly \OCA\TourCheck\Service\AlertService $alerts,
		private readonly \OCA\TourCheck\Service\TaskService $tasks,
		private readonly \OCA\TourCheck\Service\LogbookService $logbook,
		private readonly \OCA\TourCheck\Service\CheckpointService $checkpoints,
	) {
		parent::__construct($appName, $request);
	}

	private function workerUid(): string
	{
		// Identity resolution lives in AccessControlService::currentUid —
		// an explicit Basic credential is the actor, not the ambient session
		// (a stale companion cookie must never shadow the request credential
		// and attribute writes to the wrong user).
		return $this->acl->requireUid();
	}

	private function stationFromBearer(): \OCA\TourCheck\Db\Station
	{
		$header = (string)$this->request->getHeader('Authorization');
		$this->rateLimit->hitStationAuth((string)$this->request->getRemoteAddress());
		if (!str_starts_with($header, 'Bearer ')) {
			throw new NotFoundException();
		}
		return $this->stations->authenticate(substr($header, 7));
	}

	/** Worker entitlement + capabilities envelope (@check/onboarding bootstrap). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function entitlement(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			if (!$this->acl->hasAppAccess($uid)) {
				throw new NotFoundException();
			}
			$envelope = $this->license->buildEnvelope();
			$mobileOk = $this->license->isMobilePlanActive();
			return $this->ok([
				'serverTime' => (new \DateTime())->format('c'),
				'licensing' => $envelope,
				'licenseAccess' => $mobileOk ? 'ok' : 'required',
				'vendorPublicKeyB64' => \OCA\TourCheck\Config\VendorPublicKey::publicKeyB64(),
				'capabilities' => [
					'tourcheck.companionApi' => 1,
					'minClient' => 1,
					'stations' => true,
					'offlineQueue' => true,
					// Companion-spec feature flags — nfc/geo checkpoints exist
					// server-side; hardware support stays a client capability.
					'nfc' => true,
					'geo' => true,
				],
				'user' => ['uid' => $uid],
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rounds(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			if (!$this->acl->hasAppAccess($uid)) {
				throw new NotFoundException();
			}
			$rounds = $this->rounds->todayForUser($uid);
			// NH-15b: read-only pool-vehicle badge — absent MC install simply
			// yields null; the client hides the chip, never errors.
			$badge = $this->poolVehicles->badgeFor($uid, new \DateTimeImmutable());
			$out = [];
			foreach ($rounds as $r) {
				$cps = array_map(function ($c) {
					$cp = $this->checkpoints->getAny((int)$c->getCheckpointId());
					return [
						'id' => (int)$c->getId(),
						'checkpoint_id' => (int)$c->getCheckpointId(),
						'name' => $c->getNameSnap(),
						'earliest' => $c->getEarliest()?->format('c'),
						'due' => $c->getDue()?->format('c'),
						'latest' => $c->getLatest()?->format('c'),
						'status' => $c->getStatus(),
						'profile' => $c->getProfileJson() !== null ? json_decode((string)$c->getProfileJson(), true) : null,
						// Tagless kinds: the client renders a "Confirm here"
						// flow instead of a scan prompt (F1/F-optional BLE).
						'cp_kind' => $cp === null ? 'tag' : ($cp->getCpKind() ?? 'tag'),
						'geo' => $cp !== null && $cp->getGeoLat() !== null ? [
							'lat' => (float)$cp->getGeoLat(),
							'lon' => (float)$cp->getGeoLon(),
							'radius' => (int)($cp->getGeoRadius() ?? 100),
						] : null,
						'min_dwell_s' => $cp?->getMinDwellS() === null ? null : (int)$cp->getMinDwellS(),
					];
				}, $this->rounds->checkpointsOf((int)$r->getId()));
				$site = $this->sites->findById((int)$r->getSiteId());
				$out[] = [
					'id' => (int)$r->getId(),
					'site_id' => (int)$r->getSiteId(),
					'status' => $r->getStatus(),
					'scheduled_start' => $r->getScheduledStart()->format('c'),
					'window_end' => $r->getWindowEnd()->format('c'),
					// NH-09: the client only streams positions when the site
					// admin opted in — the flag travels with the round.
					'live_position' => $site !== null ? (int)$site->getLivePosition() : 0,
					'site_name' => $site !== null ? $site->getName() : null,
					// NH-14: Objekt-Hinweise + address drive the briefing card
					// and the navigate intent on the companion.
					'site_briefing' => $site !== null ? $site->getBriefing() : null,
					'site_address' => $site !== null ? $site->getAddress() : null,
					// NH-07: interval advertised so the companion can surface
					// the dead-man expectation honestly (display only).
					'checkin_interval_min' => $r->getPlanId() !== null && ($plan = $this->plans->find((int)$r->getPlanId())) !== null ? $plan->getCheckinIntervalMin() : null,
					// F4: device-side pre-alarm config + the worker's own
					// position-consent state — the app needs both to run its
					// consent-gated tracking loop and man-down watch.
					'mandown' => $this->manDown->configFor((int)$r->getSiteId()),
					'position_consent' => $this->positions->hasConsent($uid, (int)$r->getSiteId()),
					'checkpoints' => $cps,
				];
			}
			return $this->ok(['rounds' => $out, 'pool_vehicle' => $badge]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * NH-14: briefing card acknowledged — journaled once per (round, actor)
	 * as check-kind evidence that Objekt-Hinweise were shown.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function briefingShown(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$b = $this->request->getParams();
			$roundId = $this->strictId($b['round_id'] ?? null, 'round_id');
			$round = $this->rounds->findById($roundId);
			if ($round === null || $this->acl->siteRole($uid, (int)$round->getSiteId()) === null) {
				throw new NotFoundException();
			}
			$exists = $this->chain->existsForRound((int)$round->getSiteId(), $roundId, Constants::KIND_CHECK, 'briefing_shown', $uid);
			if (!$exists) {
				$this->chain->append((int)$round->getSiteId(), [
					'roundId' => $roundId,
					'kind' => Constants::KIND_CHECK,
					'actorUid' => $uid,
					'body' => 'briefing_shown',
					'payloadRef' => 'round:' . $roundId,
					'payloadFields' => ['round' => $roundId, 'actor' => $uid],
				]);
			}
			return $this->ok(['journaled' => !$exists], $exists ? Http::STATUS_OK : Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * NH-12 Übungsrunde: a synthetic demo round — deliberately NO database
	 * writes (the evidence chain must never carry practice data). The
	 * companion runs the full checkpoint flow locally and discards events.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function practiceRound(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			if (!$this->acl->hasAppAccess($uid)) {
				throw new NotFoundException();
			}
			$now = new \DateTimeImmutable();
			return $this->ok([
				'practice' => true,
				'round' => [
					'id' => 0,
					'site_id' => 0,
					'status' => 'active',
					'scheduled_start' => $now->format('c'),
					'window_end' => $now->modify('+2 hours')->format('c'),
					// Labels are placeholders — the companion renders
					// localized practice copy when `practice` is set (the
					// companion API has no user locale context).
					'site_name' => 'Practice site',
					'checkpoints' => [[
						'id' => 0,
						'checkpoint_id' => 0,
						'name' => 'Practice checkpoint',
						'earliest' => null,
						'due' => $now->modify('+1 hour')->format('c'),
						'latest' => null,
						'status' => 'pending',
						'profile' => ['items' => [
							['key' => 'demo_ok', 'kind' => 'bool', 'label' => 'Practice item checked', 'required' => true],
						]],
					]],
				],
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Token → checkpoint resolve (companion pre-binds scans offline too). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function resolveToken(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$this->rateLimit->hitTokenResolve((string)$this->request->getRemoteAddress());
			$b = $this->request->getParams();
			$token = (string)($b['token'] ?? '');
			[$cp, $t] = $this->tokens->resolve(
				$token,
				(string)$this->request->getRemoteAddress(),
				isset($b['tag_uid']) ? (string)$b['tag_uid'] : null,
			);
			$this->acl->assertCanViewSite((int)$cp->getSiteId());
			return $this->ok([
				'checkpoint' => [
					'id' => (int)$cp->getId(),
					'site_id' => (int)$cp->getSiteId(),
					'name' => $cp->getName(),
					'kind' => $t->getKind(),
					'profile' => $cp->getProfileJson() !== null ? json_decode((string)$cp->getProfileJson(), true) : null,
					// NH-02: geo-stamp config — the client asks for a
					// foreground fix only when this is non-null.
					'geo' => $cp->getGeoLat() !== null ? [
						'lat' => (float)$cp->getGeoLat(),
						'lon' => (float)$cp->getGeoLon(),
						'radius' => (int)($cp->getGeoRadius() ?? 100),
					] : null,
				],
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Batch event ingest — the offline queue drains here. Per-item idempotent. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function submitEvents(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$this->rateLimit->hitEventSync($uid);
			$b = $this->request->getParams();
			$batch = $b['events'] ?? null;
			if (!is_array($batch) || count($batch) > 100) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'events must be a list of ≤100.', ['events' => 'invalid']);
			}
			// GA-15: event-count cap per device (uid), not just request count.
			$this->rateLimit->hitEventIngest($uid, count($batch));
			$results = [];
			foreach ($batch as $i => $ev) {
				if (!is_array($ev)) {
					$results[] = ['index' => $i, 'client_event_id' => null, 'ok' => false, 'error' => 'invalid'];
					continue;
				}
				try {
					// method is SERVER-DERIVED (TH-01): 'auto' makes ingest bind it
					// to the resolved token kind; the client's claim is only logged.
					$r = $this->events->ingest([
						'token' => (string)($ev['token'] ?? ''),
						'client_event_id' => (string)($ev['client_event_id'] ?? ''),
						'client_ts' => $ev['client_ts'] ?? null,
						'values' => $ev['values'] ?? null,
						'note' => $ev['note'] ?? null,
						'geo' => $ev['geo'] ?? null,
						'auto_incident' => $ev['auto_incident'] ?? null,
						'photo_fileid' => isset($ev['photo_fileid']) ? $this->strictId($ev['photo_fileid'], 'photo_fileid') : null,
						'round_id' => isset($ev['round_id']) ? $this->strictId($ev['round_id'], 'round_id') : null,
						'method' => 'auto',
						'method_claim' => isset($ev['method']) ? (string)$ev['method'] : null,
						'actor_uid' => $uid,
						'ip' => (string)$this->request->getRemoteAddress(),
						'tag_uid' => isset($ev['tag_uid']) ? (string)$ev['tag_uid'] : null,
					]);
					$results[] = [
						'index' => $i,
						'client_event_id' => (string)$ev['client_event_id'],
						'ok' => true,
						'replayed' => $r['replayed'],
						'mismatch' => $r['mismatch'] ?? false,
						'attached' => $r['attached'],
						'event_id' => (int)$r['event']->getId(),
						'progress' => $r['progress'],
					];
				} catch (\Throwable $itemError) {
					// Evidence ingest must never fail silently — the client sees
					// the wire code; the real exception belongs in the log.
					if (!$itemError instanceof ValidationException && !$itemError instanceof \OCA\TourCheck\Exception\DomainException) {
						$this->logger->error('tourcheck: companion event ingest failed', [
							'exception' => $itemError,
							'client_event_id' => isset($ev['client_event_id']) ? (string)$ev['client_event_id'] : null,
						]);
					}
					$results[] = [
						'index' => $i,
						'client_event_id' => isset($ev['client_event_id']) ? (string)$ev['client_event_id'] : null,
						'ok' => false,
						'error' => $itemError instanceof ValidationException ? $itemError->errorCode : ($itemError instanceof \OCA\TourCheck\Exception\DomainException ? $itemError->errorCode : 'failed'),
						'fields' => $itemError instanceof ValidationException ? $itemError->fields : new \stdClass(),
					];
				}
			}
			return $this->ok(['results' => $results]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Photo upload — base64 body → staged temp file → PhotoService re-encode
	 * (EXIF/XMP stripped, decompression capped). Returns the numeric id the
	 * client then references as photo_fileid on events/incidents.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadPhoto(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$b = $this->request->getParams();
			$siteId = $this->strictId($b['site_id'] ?? null, 'site_id');
			$this->acl->assertCanViewSite($siteId);
			$b64 = (string)($b['data_b64'] ?? '');
			// base64 inflates ~4/3 — cap the wire form so a fat body never
			// reaches the decoder (10 MB binary ≈ 13.7 MB base64).
			if ($b64 === '' || strlen($b64) > 14_000_000) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Photo too large (max 10 MB).', ['photo' => 'too_large']);
			}
			$raw = base64_decode($b64, true);
			if ($raw === false || $raw === '') {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid photo data.', ['photo' => 'invalid']);
			}
			$tmp = tempnam(sys_get_temp_dir(), 'tck_photo_');
			if ($tmp === false || file_put_contents($tmp, $raw) === false) {
				throw new DomainException('photo_write_failed', 'Could not stage the photo.', 500);
			}
			try {
				$id = $this->photos->store($siteId, $tmp);
			} finally {
				@unlink($tmp);
			}
			return $this->ok(['photo_id' => $id], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createIncident(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$b = $this->request->getParams();
			$siteId = $this->strictId($b['site_id'] ?? null, 'site_id');
			$inc = $this->incidents->create($siteId, $b, $uid);
			return $this->ok($inc->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** NH-15c: voice-note upload — same staging/cap dance as photos. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadAudio(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$b = $this->request->getParams();
			$siteId = $this->strictId($b['site_id'] ?? null, 'site_id');
			$this->acl->assertCanViewSite($siteId);
			$b64 = (string)($b['data_b64'] ?? '');
			if ($b64 === '' || strlen($b64) > 7_000_000) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Voice note too large (max 5 MB).', ['audio' => 'too_large']);
			}
			$raw = base64_decode($b64, true);
			if ($raw === false || $raw === '') {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid audio data.', ['audio' => 'invalid']);
			}
			$tmp = tempnam(sys_get_temp_dir(), 'tck_audio_');
			if ($tmp === false || file_put_contents($tmp, $raw) === false) {
				throw new DomainException('audio_write_failed', 'Could not stage the voice note.', 500);
			}
			try {
				$id = $this->photos->storeAudio($siteId, $tmp);
			} finally {
				@unlink($tmp);
			}
			return $this->ok(['audio_id' => $id], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function position(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$b = $this->request->getParams();
			$lat = filter_var($b['lat'] ?? null, FILTER_VALIDATE_FLOAT);
			$lon = filter_var($b['lon'] ?? null, FILTER_VALIDATE_FLOAT);
			if ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid coordinates.', ['lat' => 'invalid', 'lon' => 'invalid']);
			}
			$this->positions->ingest(
				$this->strictId($b['round_id'] ?? null, 'round_id'),
				$uid,
				(float)$lat,
				(float)$lon,
				isset($b['accuracy_m']) && is_numeric($b['accuracy_m']) ? max(0, (int)$b['accuracy_m']) : null,
				$b['client_ts'] ?? null,
			);
			return $this->ok(['stored' => true], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Man-down heartbeat (F4) — any "I'm responsive" signal from the
	 * device resets the inactivity clock. Rate-limited like positions.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function heartbeat(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$b = $this->request->getParams();
			$this->manDown->heartbeat($this->strictId($b['round_id'] ?? null, 'round_id'), $uid);
			return $this->ok(['stored' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Worker alarm — kind=sos (manual) or kind=mandown (device pre-alarm
	 * expired unanswered). Both converge on an alert; replays dedupe.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function alarm(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$b = $this->request->getParams();
			$roundId = $this->strictId($b['round_id'] ?? null, 'round_id');
			$kind = (string)($b['kind'] ?? 'mandown');
			if ($kind === 'sos') {
				$round = $this->rounds->findById($roundId);
				if ($round === null) {
					throw new NotFoundException();
				}
				$this->acl->assertCanViewSite((int)$round->getSiteId());
				// deterministic id — an SOS retried after an ambiguous
				// timeout must not double-fire (NN-04 family)
				$a = $this->alerts->fire(Constants::ALERT_SOS, (int)$round->getSiteId(), $uid, 'critical',
					['source' => 'manual'],
					(string)($b['client_event_id'] ?? '') !== '' ? (string)$b['client_event_id'] : sprintf('sos:%d:%s:%s', $roundId, $uid, (new \DateTime())->format('YmdH')),
					$roundId);
				return $this->ok(['alertId' => (int)$a->getId()], Http::STATUS_CREATED);
			}
			if ($kind !== 'mandown') {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown alarm kind.', ['kind' => 'invalid']);
			}
			$r = $this->manDown->triggerAlarm($roundId, $uid, 'device');
			return $this->ok($r, isset($r['already']) && $r['already'] ? Http::STATUS_OK : Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Position consent — grant/revoke; effect is immediate on ingest. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function positionConsent(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$b = $this->request->getParams();
			$siteId = $this->strictId($b['site_id'] ?? null, 'site_id');
			// explicit bool parse — form-encoded 'false' is a string
			$granted = ($b['granted'] ?? null) === '1' || ($b['granted'] ?? null) === 'true' || ($b['granted'] ?? null) === true;
			if ($granted) {
				$this->positions->grantConsent($uid, $siteId);
			} else {
				$this->positions->revokeConsent($uid, $siteId);
			}
			return $this->ok(['consent' => $this->positions->hasConsent($uid, $siteId)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Worker task list — open tasks assigned to me across my sites. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workerTasks(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			return $this->ok(['tasks' => array_map(static fn ($t) => $t->jsonSerialize(), $this->tasks->listMine($uid))]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function completeWorkerTask(int $id): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$b = $this->request->getParams();
			$t = $this->tasks->complete($id, $uid, isset($b['note']) ? (string)$b['note'] : null);
			return $this->ok($t->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Logbook entries the worker must see/ack at shift start. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workerLogbook(int $siteId): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$rows = $this->logbook->unackedFor($siteId, $uid);
			return $this->ok(['entries' => array_map(static fn ($l) => $l->jsonSerialize(), $rows)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ackWorkerLogbook(int $id): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$this->logbook->ack($id, $uid);
			return $this->ok(['acked' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Tagless confirm — geo (GPS radius) and beacon (BLE uuid) checkpoints
	 * carry no physical token, so the client confirms by checkpoint_id and
	 * the server enforces kind + radius/uuid + qualification rules.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function confirmTagless(): JSONResponse
	{
		try {
			$uid = $this->workerUid();
			$this->license->requireMobileLicense();
			$b = $this->request->getParams();
			$cpId = $this->strictId($b['checkpoint_id'] ?? null, 'checkpoint_id');
			$cp = $this->checkpoints->getAny($cpId);
			if ($cp === null || (int)$cp->getActive() !== 1) {
				throw new NotFoundException();
			}
			$this->acl->assertCanViewSite((int)$cp->getSiteId());
			$cpKind = $cp->getCpKind() === null ? 'tag' : (string)$cp->getCpKind();
			if (!in_array($cpKind, ['geo', 'beacon'], true)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'This checkpoint needs a physical tag.', ['checkpoint_id' => 'invalid']);
			}
			$r = $this->events->ingest([
				'_checkpoint' => $cp,
				'client_event_id' => (string)($b['client_event_id'] ?? ''),
				'client_ts' => $b['client_ts'] ?? null,
				'method' => $cpKind === 'geo' ? Constants::METHOD_GPS : Constants::METHOD_BLE,
				'actor_uid' => $uid,
				'geo' => $b['geo'] ?? null,
				'beacon_uuid' => $b['beacon_uuid'] ?? null,
				'values' => $b['values'] ?? null,
				'note' => $b['note'] ?? null,
				'auto_incident' => $b['auto_incident'] ?? null,
				'photo_fileid' => isset($b['photo_fileid']) ? $this->strictId($b['photo_fileid'], 'photo_fileid') : null,
				'round_id' => isset($b['round_id']) ? $this->strictId($b['round_id'], 'round_id') : null,
				'ip' => (string)$this->request->getRemoteAddress(),
			]);
			return $this->ok([
				'event' => $r['event']->jsonSerialize(),
				'replayed' => $r['replayed'],
				'mismatch' => $r['mismatch'] ?? false,
				'attached' => $r['attached'],
				'progress' => $r['progress'],
			], $r['replayed'] ? Http::STATUS_OK : Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Station endpoints (Bearer) ───────────────────────────────────────

	/** Station bootstrap: members + entitlement (Family C). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'tck_station')]
	public function stationMembers(): JSONResponse
	{
		try {
			$station = $this->stationFromBearer();
			$this->license->requireStationPlan();
			$members = array_map(static fn ($m) => [
				'id' => (int)$m->getId(),
				'name' => $m->getDisplayName(),
				// PIN state for the grid badge — never the hash.
				'locked' => $m->getLockoutUntil() !== null && $m->getLockoutUntil() > new \DateTime(),
			], $this->stations->listMembers((int)$station->getId()));
			return $this->ok([
				'serverTime' => (new \DateTime())->format('c'),
				'station' => ['id' => (int)$station->getId(), 'label' => $station->getLabel(), 'site_id' => (int)$station->getSiteId()],
				'pin_length' => $this->stations->pinLength(),
				'members' => $members,
				'licensing' => $this->license->buildEnvelope(),
				'licenseAccess' => 'ok',
				'vendorPublicKeyB64' => \OCA\TourCheck\Config\VendorPublicKey::publicKeyB64(),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Kiosk confirm: member + PIN → scan-equivalent event (method=station). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'tck_station_pin')]
	public function stationConfirm(): JSONResponse
	{
		try {
			$station = $this->stationFromBearer();
			$this->license->requireStationPlan();
			$b = $this->request->getParams();
			$member = $this->stations->verifyMemberPin($station, $this->strictId($b['member_id'] ?? null, 'member_id'), (string)($b['pin'] ?? ''));
			// The station's BOUND checkpoint is where the confirm counts — a
			// client-supplied token could otherwise point at a foreign site (GA-8).
			$cp = $this->stations->checkpointFor($station);
			if ($cp === null) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'This station has no checkpoint bound.', []);
			}
			// Deterministic id per (station, member, checkpoint, round) — a
			// double-tap or retry replays instead of duplicating (NN-01).
			$roundId = isset($b['round_id']) ? $this->strictId($b['round_id'], 'round_id') : null;
			[$attachRound] = $this->rounds->attach((int)$cp->getSiteId(), (int)$cp->getId(), new \DateTimeImmutable(), $roundId);
			$clientEventId = (string)($b['client_event_id'] ?? '');
			if ($clientEventId === '') {
				$clientEventId = 'stc_' . (int)$station->getId() . '_' . (int)$member->getId()
					. '_' . (int)$cp->getId() . '_' . (int)($attachRound?->getId());
			}
			// NH-06: finger signature — through the same evidence pipeline as
			// photos (EXIF-strip, MIME sniff, size cap), station's site scope.
			// Replays of an already-stored client_event_id skip the upload so
			// a retried confirm never mints a duplicate file.
			$signatureFileid = null;
			$sigB64 = (string)($b['signature_b64'] ?? '');
			if ($sigB64 !== '' && $this->events->findByClientEventId($clientEventId) === null) {
				if (strlen($sigB64) > 1_500_000) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Signature too large (max 1 MB).', ['signature' => 'too_large']);
				}
				$raw = base64_decode($sigB64, true);
				if ($raw === false || $raw === '') {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid signature data.', ['signature' => 'invalid']);
				}
				$tmp = tempnam(sys_get_temp_dir(), 'tck_sig_');
				if ($tmp === false || file_put_contents($tmp, $raw) === false) {
					throw new DomainException('signature_write_failed', 'Could not stage the signature.', 500);
				}
				try {
					$signatureFileid = $this->photos->store((int)$cp->getSiteId(), $tmp);
				} finally {
					@unlink($tmp);
				}
			}
			$r = $this->events->ingest([
				'_checkpoint' => $cp,
				'client_event_id' => $clientEventId,
				'client_ts' => $b['client_ts'] ?? null,
				'method' => Constants::METHOD_STATION,
				'actor_uid' => null,
				'station_member_id' => (int)$member->getId(),
				'station_id' => (int)$station->getId(),
				'round_id' => $roundId,
				'signature_fileid' => $signatureFileid,
			]);
			return $this->ok([
				'event' => $r['event']->jsonSerialize(),
				'replayed' => $r['replayed'],
				'mismatch' => $r['mismatch'] ?? false,
				'attached' => $r['attached'],
				'progress' => $r['progress'],
				'member' => ['id' => (int)$member->getId(), 'name' => $member->getDisplayName()],
			], $r['replayed'] ? Http::STATUS_OK : Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * NH-10 kiosk key board: list this station's site keys. The station
	 * token scopes the site — no cross-site leakage by construction.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'tck_station')]
	public function stationKeys(): JSONResponse
	{
		try {
			$station = $this->stationFromBearer();
			$this->license->requireStationPlan();
			return $this->ok(array_map(static fn ($k) => $k->jsonSerialize(), $this->keyService->listForStation($station)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * NH-10 kiosk take/return: same member+PIN verification as a sign-off
	 * (lockout budget burns identically — a key tap is not a cheaper probe).
	 * Custody identity is the verified member, never a client-supplied name.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'tck_station_pin')]
	public function stationKeyAction(): JSONResponse
	{
		try {
			$station = $this->stationFromBearer();
			$this->license->requireStationPlan();
			$b = $this->request->getParams();
			$member = $this->stations->verifyMemberPin($station, $this->strictId($b['member_id'] ?? null, 'member_id'), (string)($b['pin'] ?? ''));
			$keyId = $this->strictId($b['key_id'] ?? null, 'key_id');
			$action = (string)($b['action'] ?? '');
			if (!in_array($action, ['take', 'return'], true)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown key action.', ['action' => 'invalid']);
			}
			$k = $action === 'take'
				? $this->keyService->takeForStation($station, $keyId, (int)$member->getId(), $b['note'] ?? null)
				: $this->keyService->returnForStation($station, $keyId, (int)$member->getId(), $b['note'] ?? null);
			return $this->ok($k->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Station pairing: 6-digit code → station secret (single-use). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'tck_pairing')]
	public function pairStation(): JSONResponse
	{
		try {
			$this->license->requireStationPlan();
			$this->rateLimit->hitPairing((string)$this->request->getRemoteAddress());
			$b = $this->request->getParams();
			[$station, $secret] = $this->stations->redeemPairingCode((string)($b['code'] ?? ''));
			return $this->ok([
				'station' => ['id' => (int)$station->getId(), 'label' => $station->getLabel(), 'site_id' => (int)$station->getSiteId()],
				'secret' => $secret,
				'server_url' => (string)$this->request->getServerProtocol() . '://' . $this->request->getServerHost(),
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
