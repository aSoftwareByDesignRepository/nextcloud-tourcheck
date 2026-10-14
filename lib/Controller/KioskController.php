<?php

declare(strict_types=1);

namespace OCA\TourCheck\Controller;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\LicenseService;
use OCA\TourCheck\Service\RateLimitService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\StationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Util;
use OCP\IRequest;

/**
 * Web kiosk surface — a shared browser tab bound to a station token.
 * Member grid → PIN → confirm → reset. NO persistent identity: after every
 * confirm the kiosk returns to the member grid (UX-4).
 */
class KioskController extends Controller
{
	use ApiJsonTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StationService $stations,
		private readonly EventService $events,
		private readonly LicenseService $license,
		private readonly RateLimitService $rateLimit,
		private readonly RoundService $rounds,
		private readonly \OCA\TourCheck\Service\PhotoService $photos,
	) {
		parent::__construct($appName, $request);
	}

	private function stationTokenFromRequest(): string
	{
		$token = (string)$this->request->getParam('station_token', '');
		$header = (string)$this->request->getHeader('Authorization');
		if ($token === '' && str_starts_with($header, 'Bearer ')) {
			$token = substr($header, 7);
		}
		return $token;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function home(): TemplateResponse
	{
		try {
			$station = $this->stations->authenticate($this->stationTokenFromRequest());
			$this->license->requireStationPlan();
		} catch (NotFoundException) {
			$r = new TemplateResponse('core', '403', [], 'guest');
			$r->setStatus(Http::STATUS_FORBIDDEN);
			return $r;
		} catch (DomainException $e) {
			return new TemplateResponse(Constants::APP_ID, 'kiosk-license', ['reason' => $e->errorCode], 'guest');
		}
		$members = [];
		foreach ($this->stations->listMembers((int)$station->getId()) as $m) {
			$name = (string)$m->getDisplayName();
			$parts = preg_split('/\s+/', trim($name)) ?: [];
			$initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr($parts[count($parts) - 1] ?? '', 0, 1));
			$members[] = ['id' => (int)$m->getId(), 'displayName' => $name, 'initials' => $initials];
		}
		Util::addTranslations(Constants::APP_ID);
		$response = new TemplateResponse(Constants::APP_ID, 'kiosk', [
			'stationName' => $station->getLabel(),
			'members' => $members,
			'pinLength' => $this->stations->pinLength(),
		]);
		$response->addHeader('X-Frame-Options', 'DENY');
		return $response;
	}

	/**
	 * POST /api/kiosk/confirm — station_token + member_id + pin.
	 * The station's bound checkpoint is where the confirm counts.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'tck_station_pin')]
	public function confirm(): JSONResponse
	{
		try {
			$station = $this->stations->authenticate($this->stationTokenFromRequest());
			$this->license->requireStationPlan();
			$b = $this->request->getParams();
			$member = $this->stations->verifyMemberPin($station, $this->strictId($b['member_id'] ?? null, 'member_id'), (string)($b['pin'] ?? ''));
			$cp = $this->stations->checkpointFor($station);
			if ($cp === null) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'This station has no checkpoint bound. Bind one in site settings.', []);
			}
			// Idempotent kiosk confirm: deterministic client_event_id per
			// (station, member, checkpoint, round) — a double-tap or retry
			// replays instead of writing a duplicate/orphan event.
			$roundId = isset($b['round_id']) ? $this->strictId($b['round_id'], 'round_id') : null;
			[$attachRound] = $this->rounds->attach((int)$cp->getSiteId(), (int)$cp->getId(), new \DateTimeImmutable(), $roundId);
			$clientEventId = 'kiosk_' . (int)$station->getId() . '_' . (int)$member->getId()
				. '_' . (int)$cp->getId() . '_' . (int)($attachRound?->getId());
			// NH-06: optional finger signature — identical pipeline to the
			// companion station path (b64 cap, strict decode, staged file,
			// EXIF-strip via PhotoService). A replayed confirm skips the
			// upload so a retry can never mint a second signature file.
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
				'client_event_id' => $clientEventId,
				'method' => Constants::METHOD_STATION,
				'actor_uid' => null,
				'station_member_id' => (int)$member->getId(),
				'station_id' => (int)$station->getId(),
				'_checkpoint' => $cp,
				'round_id' => $roundId,
				'signature_fileid' => $signatureFileid,
			]);
			return $this->ok([
				'message' => 'Confirmed',
				'progress' => $r['progress'],
				'member' => ['id' => (int)$member->getId(), 'name' => $member->getDisplayName()],
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
