<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbalException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Event ingest — the evidence path. One transaction per event:
 *   lock round  →  insert check_event  →  append journal  →  update round_cp
 *   →  recomputeRoundStatus
 * Lock order round → chain-head (never reverse; ChainService holds the only
 * other lock). Idempotent on client_event_id — a retried sync returns the
 * stored event with replayed=true, never a duplicate (NN-01).
 *
 * method / actor_* / server_received_at are SERVER-DERIVED ONLY (TH-01):
 * any client-supplied value is ignored and journaled as a `method_lie` signal.
 */
class EventService
{
	public function __construct(
		private readonly CheckEventMapper $events,
		private readonly TokenService $tokens,
		private readonly RoundService $rounds,
		private readonly ChainService $chain,
		private readonly SettingsService $settings,
		private readonly AccessControlService $acl,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly ?AnchorService $anchors = null,
		private readonly ?PhotoService $photos = null,
		private readonly ?IncidentService $incidents = null,
		private readonly ?NotificationService $notifications = null,
		private readonly ?SiteService $sites = null,
		private readonly ?TokenAnomalyService $anomaly = null,
		private readonly ?\OCA\TourCheck\Db\WorkerQualMapper $workerQuals = null,
	) {
	}

	public static function eventPayloadFields(CheckEvent $e): array
	{
		return [
			'id' => (int)$e->getId(),
			'round_id' => (int)($e->getRoundId() ?? 0),
			'round_cp_id' => (int)($e->getRoundCpId() ?? 0),
			'checkpoint_id' => (int)($e->getCheckpointId() ?? 0),
			'actor_uid' => (string)($e->getActorUid() ?? ''),
			'station_member_id' => (int)($e->getStationMemberId() ?? 0),
			'method' => (string)$e->getMethod(),
			'server_received_at' => ChainService::ts($e->getServerReceivedAt()),
			'client_ts' => $e->getClientTs() === null ? '' : ChainService::ts($e->getClientTs()),
			'values_json' => (string)($e->getValuesJson() ?? ''),
			'note' => (string)($e->getNote() ?? ''),
			'photo_fileid' => (int)($e->getPhotoFileid() ?? 0),
			'geo_lat' => $e->getGeoLat() === null ? '' : (string)$e->getGeoLat(),
			'geo_lon' => $e->getGeoLon() === null ? '' : (string)$e->getGeoLon(),
			'geo_acc' => (int)($e->getGeoAcc() ?? 0),
			'late_sync' => (int)$e->getLateSync(),
			'flagged_early' => (int)$e->getFlaggedEarly(),
		];
	}

	/**
	 * Ingest a scanned event.
	 *
	 * @param array{token:string, client_event_id:string, client_ts?:?string,
	 *   values?:?array, photo_fileid?:?int, note?:?string, round_id?:?int,
	 *   auto_incident?:?bool,
	 *   method_claim?:?string, actor_uid?:?string, station_member_id?:?int,
	 *   station_id?:?int, method:string} $in
	 * @return array{event: CheckEvent, replayed: bool, attached: bool, progress: ?array{done:int,total:int}}
	 */
	public function ingest(array $in): array
	{
		$clientEventId = trim((string)($in['client_event_id'] ?? ''));
		if ($clientEventId === '' || strlen($clientEventId) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $clientEventId)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'client_event_id required.', ['client_event_id' => 'invalid']);
		}
		$existing = $this->events->findByClientEventId($clientEventId);
		if ($existing !== null) {
			// Session-actor replay must still be site-scoped — replaying someone
			// else's event id must not reveal it (uniform 404).
			if (($in['actor_uid'] ?? null) !== null) {
				$this->acl->assertCanViewSite((int)$existing->getSiteId());
			}
			$mismatch = !$this->replayMatches($existing, $in);
			if ($mismatch) {
				// GA-15: same id, different payload → the stored row wins, but
				// the divergence itself is evidence (one replay_mismatch signal).
				$this->journalSignal((int)$existing->getSiteId(), 'replay_mismatch', [
					'actor_uid' => $in['actor_uid'] ?? null,
					'client_event_id' => $clientEventId,
				]);
			}
			return ['event' => $existing, 'replayed' => true, 'mismatch' => $mismatch, 'attached' => $existing->getRoundId() !== null, 'progress' => null];
		}

		// Tokenless paths (attestation, station confirm) pass a resolved
		// checkpoint via _checkpoint — token-kind lie checks don't apply.
		if (isset($in['_checkpoint']) && $in['_checkpoint'] instanceof Checkpoint) {
			$cp = $in['_checkpoint'];
			$tokenKind = $in['_tokenKind'] ?? null;
		} else {
			[$cp, $token] = $this->tokens->resolve((string)$in['token'], $in['ip'] ?? null, isset($in['tag_uid']) ? (string)$in['tag_uid'] : null);
			$tokenKind = $token->getKind();
		}
		$siteId = (int)$cp->getSiteId();
		// Site scope: a session actor may only write evidence on sites they
		// belong to (station members authenticate via bearer, actor_uid null).
		if (($in['actor_uid'] ?? null) !== null) {
			$this->acl->assertCanViewSite($siteId);
		}
		$method = (string)$in['method'];
		if ($method === 'auto') {
			// Server-derived: the token kind decides — the client 'method' field
			// is an untrusted claim; a differing claim journals one method_lie.
			$method = match ($tokenKind) {
				'nfc' => Constants::METHOD_NFC,
				'manual' => Constants::METHOD_MANUAL,
				default => Constants::METHOD_SCAN_QR,
			};
			$claim = trim((string)($in['method_claim'] ?? ''));
			if ($claim !== '' && $claim !== $method) {
				$this->journalSignal($siteId, 'method_lie:' . mb_substr(preg_replace('/[^a-z0-9_-]/i', '', $claim), 0, 32) . '_as_' . $method, $in);
			}
		}
		if (!in_array($method, [Constants::METHOD_SCAN_QR, Constants::METHOD_NFC, Constants::METHOD_STATION, Constants::METHOD_ATTEST, Constants::METHOD_MANUAL, Constants::METHOD_GPS, Constants::METHOD_BLE], true)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown capture method.', ['method' => 'invalid']);
		}
		// Tagless checkpoint kinds are enforced server-side — the companion
		// cannot claim 'gps'/'ble' on a tag checkpoint or vice versa.
		$cpKind = $cp->getCpKind() === null ? 'tag' : (string)$cp->getCpKind();
		if ($cpKind === 'geo' && $method !== Constants::METHOD_GPS && $method !== Constants::METHOD_MANUAL && $method !== Constants::METHOD_ATTEST) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'This checkpoint confirms by GPS radius.', ['method' => 'mismatch']);
		}
		if ($cpKind === 'beacon' && $method !== Constants::METHOD_BLE && $method !== Constants::METHOD_MANUAL && $method !== Constants::METHOD_ATTEST) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'This checkpoint confirms by beacon.', ['method' => 'mismatch']);
		}
		if ($method === Constants::METHOD_BLE) {
			// The claimed beacon UUID must match the configured one — BLE
			// proximity evidence is the uuid+claim pair (the device attests
			// it was physically near that beacon).
			$claimed = strtolower(trim((string)($in['beacon_uuid'] ?? '')));
			$expected = strtolower(trim((string)($cp->getBeaconUuid() ?? '')));
			if ($expected === '' || $claimed === '' || !hash_equals($expected, $claimed)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Beacon does not match this checkpoint.', ['beacon' => 'mismatch']);
			}
		}
		if ($method === Constants::METHOD_GPS) {
			// GPS-radius confirm: the position claim is REQUIRED and must land
			// inside the configured radius (accuracy widens the bound, capped
			// at 200 m — a worse fix can never confirm a checkpoint).
			$geo = $in['geo'] ?? null;
			if (!is_array($geo)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'A GPS position is required for this checkpoint.', ['geo' => 'required']);
			}
			$gLat = filter_var($geo['lat'] ?? null, FILTER_VALIDATE_FLOAT);
			$gLon = filter_var($geo['lon'] ?? null, FILTER_VALIDATE_FLOAT);
			$gAcc = isset($geo['accuracy_m']) && is_numeric($geo['accuracy_m']) ? (int)$geo['accuracy_m'] : 99999;
			if ($gLat === false || $gLon === false || $gAcc > 200) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'GPS fix too imprecise — move and retry.', ['geo' => 'accuracy']);
			}
			$radius = (int)($cp->getGeoRadius() ?? 100);
			$dist = \OCA\TourCheck\Support\GeoMath::distance((float)$gLat, (float)$gLon, (float)$cp->getGeoLat(), (float)$cp->getGeoLon());
			if ($dist > $radius + $gAcc) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Too far from the checkpoint.', ['geo' => 'out_of_range']);
			}
		}
		// Qualification gate — a checkpoint may require a valid qual
		// (§F-optional qualification gating). Applies to session actors only;
		// station members are vouched by the station binding.
		$reqQual = trim((string)($cp->getRequiredQual() ?? ''));
		if ($reqQual !== '' && ($in['actor_uid'] ?? null) !== null && $this->workerQuals !== null) {
			if (!$this->workerQuals->hasQual((string)$in['actor_uid'], $reqQual, $this->timeFactory->getDateTime())) {
				$this->journalSignal($siteId, 'qual_missing:' . mb_substr(preg_replace('/[^a-z0-9_-]/i', '', $reqQual), 0, 48), [
					'actor_uid' => $in['actor_uid'], 'checkpoint_id' => (int)$cp->getId(),
				]);
				throw new ValidationException(Constants::ERR_VALIDATION, 'Required qualification missing or expired.', ['qualification' => 'missing']);
			}
		}
		// TH-01: method lies — client claims NFC but token is QR etc.
		if ($tokenKind === 'qr' && $method === Constants::METHOD_NFC) {
			$this->journalSignal($siteId, 'method_lie:nfc_on_qr_token', $in);
			throw new ValidationException(Constants::ERR_VALIDATION, 'NFC claim on a non-NFC checkpoint.', ['method' => 'mismatch']);
		}
		if ($tokenKind === 'nfc' && $method === Constants::METHOD_SCAN_QR) {
			$this->journalSignal($siteId, 'method_lie:qr_on_nfc_token', $in);
			throw new ValidationException(Constants::ERR_VALIDATION, 'QR claim on an NFC-only checkpoint.', ['method' => 'mismatch']);
		}

		$now = $this->timeFactory->getDateTime();
		$clientTs = isset($in['client_ts']) && $in['client_ts'] !== null && $in['client_ts'] !== ''
			? $this->parseClientTs((string)$in['client_ts'])
			: null;
		$attachTs = $clientTs ?? $now;
		$lateDays = $this->settings->getLateSyncDays();

		[$round, $rcp] = $this->rounds->attach($siteId, (int)$cp->getId(), $attachTs, $in['round_id'] ?? null);
		$lateSync = 0;
		if ($round !== null && $now > $round->getWindowEnd()) {
			if ($now->getTimestamp() - $round->getWindowEnd()->getTimestamp() > $lateDays * 86400) {
				// Outside the late-sync bound — evidence stays orphan but is journaled.
				$round = null;
				$rcp = null;
			} else {
				$lateSync = 1;
			}
		}

		$values = $in['values'] ?? null;
		$this->validateValues($rcp?->getProfileJson(), $values);
		// GA-13/MH-18: a failed required checklist item cannot silently
		// confirm — it needs a note on the event or the worker's explicit
		// incident report (auto_incident). Everything else is a 422 with a
		// corrective action (GA-19), never a swallowed failure.
		$failedRequired = $this->failedRequiredItems($rcp?->getProfileJson(), $values);
		$autoIncident = in_array($in['auto_incident'] ?? null, [true, 1, '1', 'true'], true);
		if ($failedRequired !== [] && !$autoIncident && trim((string)($in['note'] ?? '')) === '') {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				'Failed required item needs a note or an incident report.',
				['values' => 'failed_required:' . implode(',', $failedRequired)]
			);
		}

		$event = new CheckEvent();
		$event->setSiteId($siteId);
		$event->setCheckpointId((int)$cp->getId());
		$event->setClientEventId($clientEventId);
		$event->setActorUid($in['actor_uid'] ?? null);
		$event->setStationMemberId($in['station_member_id'] ?? null);
		$event->setStationId($in['station_id'] ?? null);
		$event->setMethod($method);
		if (isset($in['attestation_reason'])) {
			$event->setAttesterUid($in['actor_uid'] ?? null);
			$event->setAttestationReason(mb_substr((string)$in['attestation_reason'], 0, 1000));
		}
		$event->setServerReceivedAt($now);
		$event->setClientTs($clientTs === null ? null : \DateTime::createFromInterface($clientTs));
		$event->setValuesJson($values === null ? null : json_encode($values));
		$photoFileid = isset($in['photo_fileid']) && $in['photo_fileid'] !== null && $in['photo_fileid'] !== ''
			? (int)$in['photo_fileid'] : null;
		// NH-06: kiosk finger-signature — same photo pipeline, dedicated
		// column so an audit can tell signature from scene evidence.
		$signatureFileid = isset($in['signature_fileid']) && $in['signature_fileid'] !== null && $in['signature_fileid'] !== ''
			? (int)$in['signature_fileid'] : null;
		foreach ([['photo_fileid', $photoFileid], ['signature_fileid', $signatureFileid]] as [$field, $fileid]) {
			if ($fileid !== null && $fileid > 0
				&& $this->photos !== null && !$this->photos->exists($siteId, $fileid)) {
			// Cross-site photo reference — evidence may only bind photos
			// that belong to THIS site (same rule as IncidentService).
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid photo reference.', [$field => 'invalid']);
			}
		}
		$event->setPhotoFileid($photoFileid);
		$event->setSignatureFileid($signatureFileid);
		// NH-02: geo-stamp only when the checkpoint opts in. A geo claim on a
		// non-geo checkpoint is rejected — minimization is enforced, not
		// silently dropped (same rule as unknown values_json keys).
		[$geoLat, $geoLon, $geoAcc] = $this->normalizeGeo($in['geo'] ?? null, $cp->getGeoLat() !== null);
		$event->setGeoLat($geoLat);
		$event->setGeoLon($geoLon);
		$event->setGeoAcc($geoAcc);
		$event->setNote(isset($in['note']) && trim((string)$in['note']) !== '' ? mb_substr((string)$in['note'], 0, 2000) : null);
		$event->setLateSync($lateSync);
		$event->setOrphan($round === null ? 1 : 0);
		$event->setTombstoned(0);
		$event->setCreatedAt($now);

		$roundTransition = null;
		$this->db->beginTransaction();
		try {
			if ($round !== null) {
				$round = $this->rounds->lockRound((int)$round->getId());
				if (in_array($round->getStatus(), [Constants::ROUND_COMPLETED, Constants::ROUND_SKIPPED], true)) {
					$round = null;
					$rcp = null;
					$event->setOrphan(1);
					$lateSync = 0;
					$event->setLateSync(0);
				}
			}
			if ($round !== null && $rcp !== null) {
				$event->setRoundId((int)$round->getId());
				$event->setRoundCpId((int)$rcp->getId());
				$earliest = $rcp->getEarliest();
				if ($earliest !== null && $attachTs < $earliest) {
					$event->setFlaggedEarly(1);
				}
			}
			$event = $this->events->insert($event);
			$kind = $method === Constants::METHOD_ATTEST ? Constants::KIND_ATTEST : Constants::KIND_CHECK;
			$reason = $method === Constants::METHOD_ATTEST ? trim((string)$event->getAttestationReason()) : '';
			$this->chain->append($siteId, [
				'roundId' => $event->getRoundId(),
				'kind' => $kind,
				'actorUid' => $event->getActorUid(),
				'memberId' => $event->getStationMemberId(),
				'body' => $reason !== '' ? $method . ': ' . $reason : $method,
				'payloadRef' => 'check_event:' . $event->getId(),
				'payloadFields' => self::eventPayloadFields($event),
			]);
			if ($rcp !== null) {
				$rcp->setStatus($method === Constants::METHOD_ATTEST ? 'attested' : 'done');
				$rcp->setEventId((int)$event->getId());
				$this->updateRoundCp($rcp);
				$roundTransition = $this->rounds->recomputeStatus((int)$round->getId());
			}
			// Anchor trigger: a round that just closed gets a pending anchor in
			// the SAME transaction — evidence and its anchor request are atomic.
			if ($round !== null && $this->anchors !== null) {
				$fresh = $this->rounds->findById((int)$round->getId());
				if ($fresh !== null && $fresh->getStatus() === Constants::ROUND_COMPLETED) {
					$this->anchors->requestAnchor($siteId, 'round_close');
				}
			}
			$this->db->commit();
		} catch (DbalException $e) {
			$this->db->rollBack();
			if ($e->getReason() === DbalException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$stored = $this->events->findByClientEventId($clientEventId);
				if ($stored !== null) {
					$mismatch = !$this->replayMatches($stored, $in);
					if ($mismatch) {
						$this->journalSignal((int)$stored->getSiteId(), 'replay_mismatch', [
							'actor_uid' => $in['actor_uid'] ?? null,
							'client_event_id' => $clientEventId,
						]);
					}
					return ['event' => $stored, 'replayed' => true, 'mismatch' => $mismatch, 'attached' => $stored->getRoundId() !== null, 'progress' => null];
				}
			}
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$progress = null;
		if ($round !== null) {
			$cps = $this->rounds->checkpointsOf((int)$round->getId());
			$done = 0;
			foreach ($cps as $c) {
				if ($c->getStatus() === 'done' || $c->getStatus() === 'attested') {
					$done++;
				}
			}
			$progress = ['done' => $done, 'total' => count($cps)];
		}
		// GA-3: per-site valid-token anomalies — scans before their window are
		// evidence but also a misuse signal; >20/5min emits token_anomaly_burst.
		// Post-commit so anomaly bookkeeping can never roll evidence back.
		if ($this->anomaly !== null && (int)$event->getFlaggedEarly() === 1) {
			$this->anomaly->recordSiteHit($siteId, 'out_of_window');
		}
		// NH-05: out-of-band numeric values mint an auto-incident + alert.
		// Post-commit (NN-17: no outbound calls inside the evidence txn) and
		// replay-safe — the early replay return above never reaches this.
		if ($rcp !== null && $values !== null) {
			$this->thresholdCheck($siteId, (int)$cp->getId(), $event, $rcp->getProfileJson(), $values, $round?->getId());
		}
		// GA-13: the worker chose "report incident" for failed required items —
		// mint it post-commit, deduped by req-{client_event_id}.
		if ($autoIncident && $failedRequired !== []) {
			$this->requiredFailureIncident($siteId, (int)$cp->getId(), $event, $failedRequired, $round?->getId());
		}
		// NH-04 Flow: a terminal transition that just committed is a
		// workflow trigger — emit it exactly once (replay path above returns
		// earlier, so a retried scan can never re-fire the trigger).
		if ($roundTransition !== null && $round !== null) {
			$this->rounds->emitRoundTransition($roundTransition, (int)$round->getId());
		}
		return ['event' => $event, 'replayed' => false, 'mismatch' => false, 'attached' => $round !== null, 'progress' => $progress];
	}

	/**
	 * Replay fingerprint: does a repeated client_event_id carry the same
	 * evidence fields that were originally stored? Compared server-side so a
	 * replayed id can never overwrite — divergence only journals a signal.
	 */
	private function replayMatches(CheckEvent $e, array $in): bool
	{
		$m = (string)($in['method'] ?? '');
		if ($m === 'auto') {
			// 'auto' is server-derived — the comparable client input is the
			// claim it sent; a differing claim is the mismatch.
			$claim = trim((string)($in['method_claim'] ?? ''));
			if ($claim !== '' && $e->getMethod() !== $claim) {
				return false;
			}
		} elseif ($m !== '' && $e->getMethod() !== $m) {
			return false;
		}
		$actor = $in['actor_uid'] ?? null;
		if (($e->getActorUid() ?? null) !== $actor) {
			return false;
		}
		if ((int)($e->getStationMemberId() ?? 0) !== (int)($in['station_member_id'] ?? 0)) {
			return false;
		}
		if ((int)($e->getStationId() ?? 0) !== (int)($in['station_id'] ?? 0)) {
			return false;
		}
		if (isset($in['_checkpoint']) && $in['_checkpoint'] instanceof Checkpoint
			&& (int)$e->getCheckpointId() !== (int)$in['_checkpoint']->getId()) {
			return false;
		}
		if (isset($in['round_id']) && $in['round_id'] !== null
			&& (int)$e->getRoundId() !== (int)$in['round_id']) {
			return false;
		}
		$incomingTs = isset($in['client_ts']) && $in['client_ts'] !== null && $in['client_ts'] !== ''
			? $this->parseClientTsQuiet((string)$in['client_ts']) : null;
		$storedTs = $e->getClientTs() === null ? null
			: $e->getClientTs()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
		$incomingTsStr = $incomingTs?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
		if ($storedTs !== $incomingTsStr) {
			return false;
		}
		if (self::canonicalValues($e->getValuesJson()) !== self::canonicalValues(isset($in['values']) ? json_encode($in['values']) : null)) {
			return false;
		}
		[$gLat, $gLon, $gAcc] = $this->normalizeGeoQuiet($in['geo'] ?? null);
		if (!$this->geoEquals($e->getGeoLat(), $e->getGeoLon(), $e->getGeoAcc(), $gLat, $gLon, $gAcc)) {
			return false;
		}
		$storedNote = trim((string)($e->getNote() ?? ''));
		$incomingNote = isset($in['note']) ? trim((string)$in['note']) : '';
		return $storedNote === mb_substr($incomingNote, 0, 2000) || ($storedNote === '' && $incomingNote === '');
	}

	/**
	 * @return array{0:?float,1:?float,2:?int}
	 */
	private function normalizeGeo(mixed $geo, bool $checkpointAllows): array
	{
		if ($geo === null) {
			return [null, null, null];
		}
		if (!$checkpointAllows || !is_array($geo)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Geo-stamp not allowed for this checkpoint.', ['geo' => 'invalid']);
		}
		$lat = filter_var($geo['lat'] ?? null, FILTER_VALIDATE_FLOAT);
		$lon = filter_var($geo['lon'] ?? null, FILTER_VALIDATE_FLOAT);
		if ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid coordinates.', ['geo' => 'invalid']);
		}
		$acc = isset($geo['accuracy_m']) && is_numeric($geo['accuracy_m'])
			? max(0, min(100_000, (int)$geo['accuracy_m']))
			: null;
		return [(float)$lat, (float)$lon, $acc];
	}

	/** Replay-compare normalization — never throws; garbage → no stamp. */
	private function normalizeGeoQuiet(mixed $geo): array
	{
		try {
			return $this->normalizeGeo($geo, true);
		} catch (\Throwable) {
			return [null, null, null];
		}
	}

	private function geoEquals(?float $lat, ?float $lon, ?int $acc, ?float $iLat, ?float $iLon, ?int $iAcc): bool
	{
		if (($lat === null) !== ($iLat === null) || ($lon === null) !== ($iLon === null)) {
			return false;
		}
		if ($lat !== null && abs($lat - $iLat) > 1e-9) {
			return false;
		}
		if ($lon !== null && abs($lon - $iLon) > 1e-9) {
			return false;
		}
		return (int)($acc ?? 0) === (int)($iAcc ?? 0);
	}

	private function parseClientTsQuiet(string $raw): ?\DateTimeInterface
	{
		try {
			$ts = $this->parseClientTs($raw);
			return \DateTime::createFromInterface($ts);
		} catch (\Throwable) {
			return null;
		}
	}

	/** Canonicalize values JSON: deep key sort — reserialized order is not evidence. */
	private static function canonicalValues(?string $json): string
	{
		if ($json === null || $json === '') {
			return '';
		}
		$v = json_decode($json, true);
		if (!is_array($v)) {
			return $json;
		}
		$sort = static function (mixed &$x) use (&$sort): void {
			if (is_array($x)) {
				if (array_is_list($x)) {
					foreach ($x as &$item) {
						$sort($item);
					}
				} else {
					ksort($x);
					foreach ($x as &$item) {
						$sort($item);
					}
				}
			}
		};
		$sort($v);
		return (string)json_encode($v);
	}

	private function updateRoundCp(\OCA\TourCheck\Db\RoundCheckpoint $rcp): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('tck_round_cp')
			->set('status', $qb->createNamedParameter($rcp->getStatus()))
			->set('event_id', $qb->createNamedParameter($rcp->getEventId()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($rcp->getId())));
		$qb->executeStatement();
	}

	private function parseClientTs(string $raw): \DateTimeInterface
	{
		$dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $raw);
		if ($dt === false) {
			$dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $raw);
		}
		if ($dt === false) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'client_ts must be ISO-8601.', ['client_ts' => 'invalid']);
		}
		// Clock-skew cap: >24h in the future is a lie → flag but cap.
		$max = $this->timeFactory->getDateTime()->modify('+1 day');
		return $dt > $max ? \DateTimeImmutable::createFromInterface($max) : $dt;
	}

	/**
	 * values_json must conform to the checkpoint profile snapshot — no
	 * free-form key injection into evidence (TH: schema-bound values).
	 */
	private function validateValues(?string $profileJson, mixed $values): void
	{
		if ($values === null) {
			return;
		}
		if (!is_array($values)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'values must be an object.', ['values' => 'invalid']);
		}
		// Spec cap: values_json ≤ 64 KB serialized — no blob stuffing into evidence.
		if (strlen((string)json_encode($values)) > 65536) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'values payload too large.', ['values' => 'too_large']);
		}
		$profile = $profileJson !== null ? json_decode($profileJson, true) : null;
		$allowed = [];
		$required = [];
		if (is_array($profile['items'] ?? null)) {
			foreach ($profile['items'] as $item) {
				$allowed[$item['key']] = $item['kind'] ?? 'bool';
				if (in_array($item['required'] ?? false, [true, 1, '1', 'true'], true)) {
					$required[] = $item['key'];
				}
			}
		}
		foreach ($values as $key => $v) {
			if ($allowed !== [] && !isset($allowed[$key])) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown checklist key.', ['values' => 'unknown:' . $key]);
			}
			if (isset($allowed[$key])) {
				$kind = $allowed[$key];
				$ok = match ($kind) {
					'bool' => is_bool($v) || $v === 0 || $v === 1,
					'number' => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)),
					'photo' => is_int($v) && $v > 0,
					default => is_string($v) && strlen($v) <= 1000,
				};
				if (!$ok) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Checklist value has the wrong type.', ['values' => 'type:' . $key]);
				}
			}
		}
		foreach ($required as $key) {
			if (!array_key_exists($key, $values)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Required checklist item missing.', ['values' => 'required:' . $key]);
			}
		}
	}

	/**
	 * GA-13/MH-18: required bool items answered falsy are "failed" checks.
	 * Number-out-of-band is covered by the NH-05 auto-incident instead;
	 * text/photo/signature items have no fail state — presence is enforced
	 * by validateValues above.
	 * @return list<string> failed item keys
	 */
	private function failedRequiredItems(?string $profileJson, mixed $values): array
	{
		if (!is_array($values)) {
			return [];
		}
		$profile = $profileJson !== null ? json_decode($profileJson, true) : null;
		if (!is_array($profile['items'] ?? null)) {
			return [];
		}
		$failed = [];
		foreach ($profile['items'] as $item) {
			if (($item['kind'] ?? 'bool') !== 'bool'
				|| !in_array($item['required'] ?? false, [true, 1, '1', 'true'], true)) {
				continue;
			}
			$key = (string)($item['key'] ?? '');
			if ($key !== '' && array_key_exists($key, $values) && !$values[$key]) {
				$failed[] = $key;
			}
		}
		return $failed;
	}

	/**
	 * GA-13/MH-18: worker explicitly chose "report incident" for failed
	 * required items — mint one deduped incident (req-{client_event_id})
	 * + supervisor notification. Post-commit, replay-safe; a failed alert
	 * journals a signal instead of rolling evidence back.
	 * @param list<string> $failed
	 */
	private function requiredFailureIncident(int $siteId, int $checkpointId, CheckEvent $event, array $failed, ?int $roundId): void
	{
		if ($this->incidents === null) {
			return;
		}
		$labels = implode(', ', $failed);
		try {
			$this->incidents->createSystem($siteId, [
				'client_incident_id' => 'req-' . mb_substr((string)$event->getClientEventId(), 0, 56),
				'category' => 'checklist',
				'severity' => 'medium',
				'title' => 'Required check failed: ' . $labels,
				'note' => 'Auto-incident: worker reported failed required item(s): ' . $labels,
				'round_id' => $roundId,
				'checkpoint_id' => $checkpointId,
				'event_id' => (int)$event->getId(),
			]);
			if ($this->notifications !== null && $this->sites !== null) {
				$site = $this->sites->findById((int)$siteId);
				if ($site !== null) {
					$this->notifications->notifySite(
						$siteId,
						'reqfail:' . (int)$event->getId(),
						'checklist_failure',
						'Required check failed: ' . $labels,
						$site,
						['detail' => $labels]
					);
				}
			}
		} catch (\Throwable $e) {
			// Evidence already committed — a failed alert must never roll it
			// back, but silence is also forbidden: journal + log.
			$this->logger->warning('tourcheck: required-failure incident failed', ['event' => (int)$event->getId(), 'e' => $e->getMessage()]);
			$this->journalSignal($siteId, 'checklist_incident_failed', ['client_event_id' => (string)$event->getClientEventId()]);
		}
	}

	/**
	 * NH-05: profile number items with min/max bounds — a submitted value
	 * outside the band mints one auto-incident (category 'threshold',
	 * severity 'medium', deduped by thr-{client_event_id}) and notifies the
	 * site supervisors. Failures are journaled as signals, never swallowed.
	 */
	private function thresholdCheck(int $siteId, int $checkpointId, CheckEvent $event, ?string $profileJson, array $values, ?int $roundId): void
	{
		if ($this->incidents === null) {
			return;
		}
		$profile = $profileJson !== null ? json_decode($profileJson, true) : null;
		if (!is_array($profile['items'] ?? null)) {
			return;
		}
		$breaches = [];
		foreach ($profile['items'] as $item) {
			if (($item['kind'] ?? '') !== 'number' || (!isset($item['min']) && !isset($item['max']))) {
				continue;
			}
			$key = (string)$item['key'];
			if (!array_key_exists($key, $values) || !is_numeric($values[$key])) {
				continue;
			}
			$v = (float)$values[$key];
			if ((isset($item['min']) && $v < (float)$item['min']) || (isset($item['max']) && $v > (float)$item['max'])) {
				$band = isset($item['min'], $item['max'])
					? $item['min'] . '–' . $item['max']
					: (isset($item['min']) ? 'min ' . $item['min'] : 'max ' . $item['max']);
				$unit = isset($item['unit']) ? ' ' . $item['unit'] : '';
				$breaches[] = $key . '=' . $v . ' (' . $band . $unit . ')';
			}
		}
		if ($breaches === []) {
			return;
		}
		try {
			$inc = $this->incidents->createSystem($siteId, [
				'client_incident_id' => 'thr-' . mb_substr((string)$event->getClientEventId(), 0, 56),
				'category' => 'threshold',
				'severity' => 'medium',
				'title' => 'Threshold: ' . implode(', ', $breaches),
				'note' => 'Auto-incident: checklist value(s) out of band: ' . implode(', ', $breaches),
				'round_id' => $roundId,
				'checkpoint_id' => $checkpointId,
				'event_id' => (int)$event->getId(),
			]);
			if ($this->notifications !== null && $this->sites !== null) {
				$site = $this->sites->findById((int)$siteId);
				if ($site !== null) {
					$this->notifications->notifySite(
						$siteId,
						'threshold:' . (int)$event->getId(),
						'threshold_alert',
						'Checklist value out of band: ' . implode(', ', $breaches),
						$site,
						['detail' => implode(', ', $breaches)]
					);
				}
			}
		} catch (\Throwable $e) {
			// Evidence already committed — a failed alert must never roll it
			// back, but silence is also forbidden: journal + log.
			$this->logger->warning('tourcheck: threshold alert failed', ['event' => (int)$event->getId(), 'e' => $e->getMessage()]);
			$this->journalSignal($siteId, 'threshold_alert_failed', ['client_event_id' => (string)$event->getClientEventId()]);
		}
	}

	/** Signal-only journal entry (method lies, invalid-token storms). */
	public function journalSignal(int $siteId, string $body, array $context = []): void
	{
		try {
			$this->chain->append($siteId, [
				'kind' => Constants::KIND_ADMIN,
				'actorUid' => $context['actor_uid'] ?? null,
				'body' => $body,
				'payloadRef' => 'signal',
				'payloadFields' => ['signal' => $body, 'client_event_id' => (string)($context['client_event_id'] ?? '')],
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('tourcheck: signal journal failed', ['body' => $body, 'exception' => $e->getMessage()]);
		}
	}

	/** @return list<CheckEvent> */
	public function findByClientEventId(string $clientEventId): ?CheckEvent
	{
		return $this->events->findByClientEventId($clientEventId);
	}

}
