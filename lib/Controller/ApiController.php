<?php

declare(strict_types=1);

namespace OCA\TourCheck\Controller;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\AnchorService;
use OCA\TourCheck\Service\AttestationService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\CheckpointService;
use OCA\TourCheck\Service\ClaimBundleService;
use OCA\TourCheck\Service\DashboardService;
use OCA\TourCheck\Service\IncidentService;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Service\LicenseService;
use OCA\TourCheck\Service\LivePageService;
use OCA\TourCheck\Service\PlanService;
use OCA\TourCheck\Service\RateLimitService;
use OCA\TourCheck\Service\ReportService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SettingsSectionCatalog;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteMemberService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\StationService;
use OCA\TourCheck\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

class ApiController extends Controller
{
	use ApiJsonTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly CheckpointService $checkpoints,
		private readonly PlanService $plans,
		private readonly SiteMemberService $members,
		private readonly RoundService $rounds,
		private readonly IncidentService $incidents,
		private readonly AttestationService $attestations,
		private readonly StationService $stations,
		private readonly ReportService $reports,
		private readonly ClaimBundleService $bundles,
		private readonly LivePageService $liveLinks,
		private readonly AnchorService $anchors,
		private readonly ChainService $chain,
		private readonly JournalEntryMapper $journal,
		private readonly DashboardService $dashboard,
		private readonly SettingsService $settings,
		private readonly LicenseService $license,
		private readonly TemplateService $templates,
		private readonly IUserManager $users,
		private readonly IGroupManager $groups,
		private readonly \OCA\TourCheck\Db\ReportDeliveryMapper $deliveries,
		private readonly \OCA\TourCheck\Service\DriveSegmentService $driveSegments,
		private readonly \OCA\TourCheck\Service\KeyService $keyService,
		private readonly \OCA\TourCheck\Service\ErasureService $erasure,
		private readonly \OCA\TourCheck\Service\TokenService $tokens,
		private readonly \OCA\TourCheck\Service\QrSheetService $qr,
		private readonly \OCP\IURLGenerator $urlGenerator,
		private readonly RateLimitService $rateLimit,
	) {
		parent::__construct($appName, $request);
	}

	private function body(): array
	{
		$params = $this->request->getParams();
		unset($params['_route']);
		return $params;
	}

	// ── Dashboard ────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function dashboard(): JSONResponse
	{
		try {
			return $this->ok($this->dashboard->home());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Sites ────────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listSites(): JSONResponse
	{
		try {
			return $this->ok(array_map(static fn ($s) => $s->jsonSerialize(), $this->sites->listForCurrentUser()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createSite(): JSONResponse
	{
		try {
			$b = $this->body();
			$site = $this->sites->create((string)($b['name'] ?? ''), $b['address'] ?? null, $b['tz'] ?? null, $b['briefing'] ?? null);
			return $this->ok($site->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function getSite(int $siteId): JSONResponse
	{
		try {
			$site = $this->sites->getVisible($siteId);
			$role = $this->acl->siteRole($this->acl->assertAppAccess(), $siteId);
			return $this->ok($site->jsonSerialize() + ['my_role' => $role]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateSite(int $siteId): JSONResponse
	{
		try {
			$b = $this->body();
			return $this->ok($this->sites->update($siteId, $b, $this->revisionPrecondition($b))->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function archiveSite(int $siteId): JSONResponse
	{
		try {
			$this->sites->archive($siteId);
			return $this->ok(['archived' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function applyTemplate(int $siteId, string $templateKey): JSONResponse
	{
		try {
			$this->templates->apply($siteId, $templateKey);
			return $this->ok(['applied' => $templateKey]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Checkpoints ──────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listCheckpoints(int $siteId): JSONResponse
	{
		try {
			$out = [];
			foreach ($this->checkpoints->listForSite($siteId, true) as $cp) {
				$out[] = $cp->jsonSerialize();
			}
			return $this->ok($out);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createCheckpoint(int $siteId): JSONResponse
	{
		try {
			[$cp, $raw] = $this->checkpoints->create($siteId, $this->body());
			return $this->ok(['checkpoint' => $cp->jsonSerialize(), 'token' => $raw], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateCheckpoint(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			$siteId = $this->strictId($b['site_id'] ?? null, 'site_id');
			return $this->ok($this->checkpoints->update($siteId, $id, $b, $this->revisionPrecondition($b))->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function archiveCheckpoint(int $id): JSONResponse
	{
		try {
			$siteId = $this->strictId($this->request->getParam('site_id'), 'site_id');
			$this->checkpoints->archive($siteId, $id);
			return $this->ok(['archived' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function rotateCheckpointToken(int $id): JSONResponse
	{
		try {
			$siteId = $this->strictId($this->request->getParam('site_id'), 'site_id');
			[$raw] = $this->checkpoints->rotateToken($siteId, $id);
			return $this->ok(['token' => $raw]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * NH-11: mint a signed NFC payload (tcks://v1.<token>.<sig>) bound to a
	 * physical tag UID. Each tag gets its own token row — a lost tag revokes
	 * alone. The payload is shown once to burn onto the tag.
	 */
	#[NoAdminRequired]
	public function signNfcTag(int $id): JSONResponse
	{
		try {
			$siteId = $this->strictId($this->request->getParam('site_id'), 'site_id');
			$this->acl->assertCanManageSite($siteId);
			$cp = $this->checkpoints->getInSite($id, $siteId);
			$tagUid = (string)$this->request->getParam('tag_uid', '');
			$payload = $this->tokens->issueSigned($cp, $tagUid);
			return $this->ok(['payload' => $payload]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function checkpointSheet(int $id): JSONResponse
	{
		try {
			$siteId = $this->strictId($this->request->getParam('site_id'), 'site_id');
			$cp = $this->checkpoints->getInSite($id, $siteId);
			$this->acl->assertCanManageSite($siteId);
			// Printable QR payload — the token itself stays hashed server-side;
			// a new one is minted for the sheet when none is printable.
			[$raw] = $this->checkpoints->rotateToken($siteId, $id);
			return new JSONResponse(['success' => true, 'data' => ['token' => $raw, 'payload' => \OCA\TourCheck\Service\TokenService::URL_PREFIX . $raw, 'checkpoint' => $cp->jsonSerialize()]]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Plans ────────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listPlans(int $siteId): JSONResponse
	{
		try {
			return $this->ok(array_map(static fn ($p) => $p->jsonSerialize(), $this->plans->listForSite($siteId)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createPlan(int $siteId): JSONResponse
	{
		try {
			$plan = $this->plans->create($siteId, $this->body());
			return $this->ok($plan->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function getPlan(int $id): JSONResponse
	{
		try {
			return $this->ok($this->plans->get($id)->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updatePlan(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			return $this->ok($this->plans->update($id, $b, $this->revisionPrecondition($b))->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function activatePlan(int $id): JSONResponse
	{
		try {
			return $this->ok($this->plans->activate($id)->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deactivatePlan(int $id): JSONResponse
	{
		try {
			return $this->ok($this->plans->deactivate($id)->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Site members ─────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listMembers(int $siteId): JSONResponse
	{
		try {
			$out = [];
			foreach ($this->members->listForSite($siteId) as $m) {
				$u = $this->users->get($m->getUid());
				$out[] = $m->jsonSerialize() + ['display_name' => $u?->getDisplayName() ?? $m->getUid()];
			}
			return $this->ok($out);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function setMember(int $siteId): JSONResponse
	{
		try {
			$b = $this->body();
			$m = $this->members->setRole($siteId, (string)($b['uid'] ?? ''), (string)($b['role'] ?? ''));
			return $this->ok($m->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function removeMember(int $siteId, string $uid): JSONResponse
	{
		try {
			$this->members->remove($siteId, $uid);
			return $this->ok(['removed' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Rounds / journal / attest ────────────────────────────────────────

	#[NoAdminRequired]
	public function listRounds(): JSONResponse
	{
		try {
			$siteId = $this->request->getParam('site_id');
			$status = $this->request->getParam('status');
			if ($siteId !== null && $siteId !== '') {
				$rounds = $this->rounds->listForSite((int)$siteId, $status, 200, 0);
			} else {
				// Scope to the caller's sites — unrestricted is an
				// admin/planner privilege only (fail-closed in the service).
				$rounds = $this->rounds->todayForUser($this->acl->assertAppAccess());
			}
			return $this->ok(array_map(static fn ($r) => $r->jsonSerialize(), $rounds));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function getRound(int $id): JSONResponse
	{
		try {
			$round = $this->rounds->getVisible($id);
			$cps = array_map(static fn ($c) => $c->jsonSerialize(), $this->rounds->checkpointsOf($id));
			return $this->ok(['round' => $round->jsonSerialize(), 'checkpoints' => $cps]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** POST /api/rounds/{id}/skip — supervisor cancels a not-yet-finished round. */
	#[NoAdminRequired]
	public function skipRound(int $id): JSONResponse
	{
		try {
			$reason = (string)($this->body()['reason'] ?? '');
			$round = $this->rounds->skip($id, $reason);
			return $this->ok(['round' => $round->jsonSerialize()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** POST /api/sites/{siteId}/erase — DSR/Art. 17 tombstone of one uid's PII. */
	#[NoAdminRequired]
	public function eraseUid(int $siteId): JSONResponse
	{
		try {
			$targetUid = trim((string)($this->body()['uid'] ?? ''));
			if ($targetUid === '' || mb_strlen($targetUid) > 64) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'User ID required.', ['uid' => 'required']);
			}
			$actor = $this->acl->assertCanManageSite($siteId);
			$rows = $this->erasure->eraseUid($siteId, $targetUid, $actor);
			return $this->ok(['rows' => $rows]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function attestEvent(): JSONResponse
	{
		try {
			$b = $this->body();
			$siteId = $this->strictId($b['site_id'] ?? null, 'site_id');
			$cpId = $this->strictId($b['checkpoint_id'] ?? null, 'checkpoint_id');
			$r = $this->attestations->attest(
				$siteId,
				$cpId,
				(string)($b['reason'] ?? ''),
				isset($b['client_event_id']) ? (string)$b['client_event_id'] : null,
				isset($b['client_ts']) ? (string)$b['client_ts'] : null,
				isset($b['round_id']) ? $this->strictId($b['round_id'], 'round_id') : null,
			);
			return $this->ok(['event' => $r['event']->jsonSerialize(), 'replayed' => $r['replayed'], 'attached' => $r['attached']]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function manualJournal(): JSONResponse
	{
		try {
			$b = $this->body();
			$siteId = $this->strictId($b['site_id'] ?? null, 'site_id');
			$body = trim((string)($b['body'] ?? ''));
			if ($body === '' || mb_strlen($body) > 4000) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Entry text required.', ['body' => 'required']);
			}
			$uid = $this->acl->assertCanSuperviseSite($siteId);
			$entry = $this->chain->append($siteId, [
				'kind' => Constants::KIND_MANUAL,
				'actorUid' => $uid,
				'body' => $body,
				'payloadRef' => 'manual',
				'payloadFields' => ['body' => $body],
			]);
			return $this->ok($entry->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listJournal(int $siteId): JSONResponse
	{
		try {
			$this->acl->assertCanViewSite($siteId);
			$kind = $this->request->getParam('kind');
			$roundId = $this->request->getParam('round_id');
			$limit = min(200, max(1, (int)$this->request->getParam('limit', '50')));
			$offset = max(0, (int)$this->request->getParam('offset', '0'));
			$rows = $this->journal->findPage($siteId, $kind, $roundId !== null ? (int)$roundId : null, $limit, $offset);
			return $this->ok(array_map(static fn ($e) => $e->jsonSerialize(), $rows));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Incidents ────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listIncidents(): JSONResponse
	{
		try {
			$status = $this->request->getParam('status');
			$siteId = $this->request->getParam('site_id');
			if ($siteId !== null && $siteId !== '') {
				$list = $this->incidents->listForSite((int)$siteId, $status, 200, 0);
			} else {
				$list = $this->incidents->listVisible($status);
			}
			return $this->ok(array_map(static fn ($i) => $i->jsonSerialize(), $list));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createIncident(): JSONResponse
	{
		try {
			$b = $this->body();
			$siteId = $this->strictId($b['site_id'] ?? null, 'site_id');
			$inc = $this->incidents->create($siteId, $b);
			return $this->ok($inc->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function getIncident(int $id): JSONResponse
	{
		try {
			$inc = $this->incidents->get($id);
			$actions = array_map(static fn ($c) => $c->jsonSerialize(), $this->incidents->correctivesOf($id));
			return $this->ok(['incident' => $inc->jsonSerialize(), 'actions' => $actions]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateIncident(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			return $this->ok($this->incidents->update($id, $b, $this->revisionPrecondition($b))->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createCorrective(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			$c = $this->incidents->addCorrective($id, (string)($b['text'] ?? ''), $b['assignee_uid'] ?? null, $b['due_at'] ?? null);
			return $this->ok($c->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateCorrective(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			return $this->ok($this->incidents->updateCorrective($id, $b, $this->revisionPrecondition($b))->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function verifyCorrective(int $id): JSONResponse
	{
		try {
			return $this->ok($this->incidents->verifyCorrective($id)->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Stations ─────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listStations(int $siteId): JSONResponse
	{
		try {
			return $this->ok(array_map(static fn ($s) => $s->jsonSerialize(), $this->stations->listForSite($siteId)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createStation(int $siteId): JSONResponse
	{
		try {
			$b = $this->body();
			[$s, $secret] = $this->stations->create($siteId, (string)($b['label'] ?? ''));
			return $this->ok(['station' => $s->jsonSerialize(), 'secret' => $secret], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function bindStationCheckpoint(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			$cp = isset($b['checkpoint_id']) && $b['checkpoint_id'] !== null && $b['checkpoint_id'] !== ''
				? $this->strictId($b['checkpoint_id'], 'checkpoint_id')
				: null;
			$st = $this->stations->bindCheckpoint($id, $this->strictId($b['site_id'] ?? null, 'site_id'), $cp);
			return $this->ok(['station' => $st->jsonSerialize()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function revokeStation(int $id): JSONResponse
	{
		try {
			$this->stations->revoke($id);
			return $this->ok(['revoked' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function rotateStationToken(int $id): JSONResponse
	{
		try {
			[$secret] = $this->stations->rotateSecret($id);
			return $this->ok(['secret' => $secret]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function pairingCode(int $id): JSONResponse
	{
		try {
			$code = $this->stations->issuePairingCode($id);
			// NH-12 pairing card: one scan carries server URL + code — the
			// companion never asks for the address. Inline SVG from the
			// same-origin generator (no third-party QR service ever).
			$payload = json_encode([
				'tck_pair' => 1,
				'url' => $this->urlGenerator->getAbsoluteURL('/'),
				'code' => $code,
			], JSON_UNESCAPED_SLASHES);
			return $this->ok([
				'code' => $code,
				'ttl_seconds' => 600,
				'svg' => $this->qr->svg((string)$payload),
				'payload' => $payload,
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listStationMembers(int $id): JSONResponse
	{
		try {
			return $this->ok(array_map(static fn ($m) => $m->jsonSerialize() + ['pin_hash' => '***'], $this->stations->listMembersForManager($id)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function upsertStationMember(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			$m = $this->stations->upsertMember($id, isset($b['member_id']) ? $this->strictId($b['member_id'], 'member_id') : null, (string)($b['display_name'] ?? ''), $b['pin'] ?? null);
			return $this->ok($m->jsonSerialize() + ['pin_hash' => '***']);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deactivateMember(int $id): JSONResponse
	{
		try {
			$this->stations->deactivateMember($id);
			return $this->ok(['deactivated' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function resetMemberPin(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			$this->stations->resetMemberPin($id, (string)($b['pin'] ?? ''));
			return $this->ok(['reset' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Reports / bundles / exports ──────────────────────────────────────

	#[NoAdminRequired]
	public function generateReport(int $siteId): JSONResponse
	{
		try {
			$b = $this->body();
			$uid = $this->acl->assertAppAccess();
			$audience = (string)($b['audience'] ?? 'internal');
			if (!in_array($audience, ['internal', 'client'], true)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown audience.', ['audience' => 'invalid']);
			}
			$r = $this->reports->generate($siteId, (string)($b['period'] ?? ''), 'manual', null, $uid, $audience);
			return $this->ok(['delivery' => $r['delivery']->jsonSerialize(), 'path' => $r['path']], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Per-delivery portal sharing toggle — planner scope, client-audience only. */
	#[NoAdminRequired]
	public function setReportClientVisible(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			$dlv = $this->reports->setClientVisible($id, $this->bodyBool($b, 'client_visible'));
			return $this->ok($dlv->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listReports(int $siteId): JSONResponse
	{
		try {
			return $this->ok(array_map(static fn ($d) => $d->jsonSerialize(), $this->reports->listDeliveries($siteId)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function downloadReport(int $id): JSONResponse|DataDownloadResponse
	{
		try {
			$this->acl->assertAppAccess();
			$dlv = $this->deliveries->find($id);
			if ($dlv === null) {
				throw new NotFoundException();
			}
			$this->acl->assertCanViewSite((int)$dlv->getSiteId());
			// Appdata, not the requester's Files — every site viewer may download.
			$file = $this->reports->deliveryFile($dlv);
			if ($file === null) {
				throw new NotFoundException();
			}
			return new DataDownloadResponse($file->getContent(), 'tourcheck-report-' . $dlv->getPeriod() . '.pdf', 'application/pdf');
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function claimBundle(int $siteId): JSONResponse
	{
		try {
			$b = $this->body();
			$uid = $this->acl->assertAppAccess();
			$r = $this->bundles->build($siteId, (string)($b['period'] ?? ''), $uid);
			return $this->ok($r, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listSchedules(int $siteId): JSONResponse
	{
		try {
			return $this->ok(array_map(static fn ($s) => $s->jsonSerialize(), $this->reports->listSchedules($siteId)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function upsertSchedule(int $siteId): JSONResponse
	{
		try {
			$b = $this->body();
			$s = $this->reports->upsertSchedule($siteId, (string)($b['cadence'] ?? ''), (string)($b['recipient'] ?? ''), $this->bodyBool($b, 'active', true), (string)($b['audience'] ?? 'internal'));
			return $this->ok($s->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * NH-15 / suite flange F11: versioned drive-segments read facade.
	 * Round-to-round legs only (completed → next started, same worker, same
	 * day, different sites), allow-listed fields, generic purpose —
	 * MobilityCheck turns them into confirmable Fahrtenbuch drafts.
	 * Planner-scope: segments are org-level data, not per-site.
	 */
	#[NoAdminRequired]
	public function driveSegments(string $from = '', string $to = ''): JSONResponse
	{
		try {
			$uid = $this->acl->assertAppAccess();
			if (!$this->acl->canSupervise($uid)) {
				throw new NotFoundException();
			}
			return $this->ok(['version' => 1, 'segments' => $this->driveSegments->segments($from, $to !== '' ? $to : $from)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * NH-10 Schlüsselregister: list keys + ledger for a site.
	 */
	#[NoAdminRequired]
	public function keys(int $siteId): JSONResponse
	{
		try {
			$this->acl->assertCanViewSite($siteId);
			return $this->ok([
				'keys' => array_map(static fn ($k) => $k->jsonSerialize(), $this->keyService->listForSite($siteId)),
				'log' => array_map(static fn ($l) => $l->jsonSerialize(), $this->keyService->logForSite($siteId)),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createKey(int $siteId): JSONResponse
	{
		try {
			$b = $this->body();
			$k = $this->keyService->create($siteId, (string)($b['label'] ?? ''));
			return $this->ok($k->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function takeKey(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			$holderUid = null;
			$holderMemberId = null;
			if (isset($b['holder_uid']) && $b['holder_uid'] !== '') {
				$holderUid = trim((string)$b['holder_uid']);
			} elseif (isset($b['holder_member_id']) && $b['holder_member_id'] !== '') {
				$holderMemberId = $this->strictId($b['holder_member_id'], 'holder_member_id');
			}
			$k = $this->keyService->take($id, $holderUid, $holderMemberId, $b['note'] ?? null);
			return $this->ok($k->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function returnKey(int $id): JSONResponse
	{
		try {
			$b = $this->body();
			$k = $this->keyService->return($id, $b['note'] ?? null);
			return $this->ok($k->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function exportCsv(int $siteId): JSONResponse|StreamResponse
	{
		try {
			$period = (string)$this->request->getParam('period', date('Y-m'));
			$format = (string)$this->request->getParam('format', 'csv');
			if (!in_array($format, ['csv', 'ods'], true)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown export format.', ['format' => 'invalid']);
			}
			if ($format === 'ods') {
				$ods = $this->reports->buildOds($siteId, $period);
				$fp = fopen('php://temp', 'rb+');
				fwrite($fp, $ods);
				rewind($fp);
				$res = new StreamResponse($fp);
				$res->setHeaders([
					'Content-Type' => 'application/vnd.oasis.opendocument.spreadsheet',
					'Content-Disposition' => 'attachment; filename="tourcheck-' . $siteId . '-' . $period . '.ods"',
				]);
				return $res;
			}
			$csv = $this->reports->buildCsv($siteId, $period);
			$fp = fopen('php://temp', 'rb+');
			fwrite($fp, $csv);
			rewind($fp);
			$res = new StreamResponse($fp);
			$res->setHeaders([
				'Content-Type' => 'text/csv; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="tourcheck-' . $siteId . '-' . $period . '.csv"',
			]);
			return $res;
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Live links ───────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listLiveLinks(int $siteId): JSONResponse
	{
		try {
			return $this->ok(array_map(static fn ($ll) => $ll->jsonSerialize(), $this->liveLinks->listForSite($siteId)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createLiveLink(int $siteId): JSONResponse
	{
		try {
			$b = $this->body();
			[$link, $token] = $this->liveLinks->create($siteId, (string)($b['label'] ?? ''), $b['expires_at'] ?? null);
			return $this->ok(['link' => $link->jsonSerialize(), 'token' => $token], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function revokeLiveLink(int $id): JSONResponse
	{
		try {
			$this->liveLinks->revoke($id);
			return $this->ok(['revoked' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Admin: search / settings / license / verify / anchor ─────────────

	#[NoAdminRequired]
	public function searchUsers(): JSONResponse
	{
		try {
			// User enumeration is a manage privilege — the picker only serves
			// member/settings surfaces; a worker must not enumerate users.
			$uid = $this->acl->assertAppAccess();
			if (!$this->acl->canManageAnySite($uid)) {
				throw new NotFoundException();
			}
			$q = (string)$this->request->getParam('q', '');
			if (strlen($q) < 2) {
				return $this->ok([]);
			}
			$out = [];
			foreach ($this->users->search($q, 20) as $u) {
				if ($this->acl->hasAppAccess($u->getUID())) {
					$out[] = ['uid' => $u->getUID(), 'display_name' => $u->getDisplayName()];
				}
			}
			return $this->ok($out);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function searchGroups(): JSONResponse
	{
		try {
			$this->acl->assertAppAdmin();
			$q = (string)$this->request->getParam('q', '');
			$out = [];
			foreach ($this->groups->search($q, 20) as $g) {
				$out[] = ['gid' => $g->getGID(), 'display_name' => $g->getDisplayName()];
			}
			return $this->ok($out);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function getSettings(): JSONResponse
	{
		try {
			$this->acl->assertAppAdmin();
			return $this->ok($this->settings->snapshot() + ['license' => $this->license->getLicenseSummary()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function saveSettings(): JSONResponse
	{
		try {
			$uid = $this->acl->assertAppAdmin();
			$b = $this->body();
			// Form wire shape: comma strings → arrays; checkbox → access_mode.
			if (isset($b['access_open'])) {
				$b['access_mode'] = ($b['access_open'] === '1' || $b['access_open'] === 'true' || $b['access_open'] === true) ? 'open' : 'restricted';
			}
			foreach (['allowed_groups', 'allowed_users', 'app_admins'] as $listKey) {
				if (isset($b[$listKey]) && is_string($b[$listKey])) {
					$b[$listKey] = array_values(array_filter(array_map('trim', explode(',', $b[$listKey])), static fn ($v) => $v !== ''));
				}
			}
			if (isset($b['default_timezone'])) {
				$tz = trim((string)$b['default_timezone']);
				if ($tz === '' || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown timezone.', ['default_timezone' => 'invalid']);
				}
				$this->settings->setDefaultTimezone($tz);
			}
			if (isset($b['access_mode']) || isset($b['allowed_groups']) || isset($b['allowed_users'])) {
				$groups = is_array($b['allowed_groups'] ?? null) ? $b['allowed_groups'] : [];
				$users = is_array($b['allowed_users'] ?? null) ? $b['allowed_users'] : [];
				try {
					foreach ($groups as $gid) {
						$this->acl->assertValidGid((string)$gid);
					}
				} catch (ValidationException $e) {
					throw new ValidationException($e->errorCode, $e->getMessage(), ['allowed_groups' => 'invalid']);
				}
				try {
					foreach ($users as $u) {
						$this->acl->assertValidUid((string)$u);
					}
				} catch (ValidationException $e) {
					throw new ValidationException($e->errorCode, $e->getMessage(), ['allowed_users' => 'invalid']);
				}
				$this->settings->setAccess((string)($b['access_mode'] ?? 'open'), $groups, $users);
			}
			if (isset($b['app_admins']) && is_array($b['app_admins'])) {
				try {
					foreach ($b['app_admins'] as $u) {
						$this->acl->assertValidUid((string)$u);
					}
				} catch (ValidationException $e) {
					throw new ValidationException($e->errorCode, $e->getMessage(), ['app_admins' => 'invalid']);
				}
				$this->settings->setAppAdmins($b['app_admins']);
			}
			if (isset($b['tsa_url'])) {
				$url = trim((string)$b['tsa_url']);
				if ($url !== '' && !str_starts_with($url, 'https://')) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'TSA URL must be HTTPS.', ['tsa_url' => 'invalid']);
				}
				$this->settings->setTsaUrl($url);
			}
			if (isset($b['tsa_ca_bundle'])) {
				$p = trim((string)$b['tsa_ca_bundle']);
				if ($p !== '' && (!str_starts_with($p, '/') || !is_readable($p))) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'TSA CA bundle must be a readable absolute path.', ['tsa_ca_bundle' => 'invalid']);
				}
				$this->settings->setTsaCaBundle($p);
			}
			foreach (['late_sync_days', 'position_retention_hours', 'journal_retention_years', 'pin_length', 'pin_lockout_attempts', 'pin_lockout_minutes'] as $k) {
				if (isset($b[$k]) && (!is_numeric($b[$k]) || (int)$b[$k] < 0)) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid value.', [$k => 'invalid']);
				}
				if (isset($b[$k])) {
					$this->settings->setNumeric($k, (int)$b[$k]);
				}
			}
			if (isset($b['brand_name']) || isset($b['brand_color'])) {
				$this->settings->setBranding((string)($b['brand_name'] ?? ''), (string)($b['brand_color'] ?? ''));
			}
			return $this->ok($this->settings->snapshot());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function applyLicense(): JSONResponse
	{
		try {
			$this->acl->assertAppAdmin();
			$b = $this->body();
			$key = (string)($b['license_key'] ?? '');
			if (!$this->license->applyLicenseKey($key)) {
				return $this->fail('LICENSE_INVALID', $this->license->getLastApplyErrorCode() !== '' ? $this->license->getLastApplyErrorCode() : 'License key rejected.', ['license' => 'invalid'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			return $this->ok(['license' => $this->license->getLicenseSummary()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function clearLicense(): JSONResponse
	{
		try {
			$this->acl->assertAppAdmin();
			$this->license->clearLicense();
			return $this->ok(['cleared' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function verifyChain(int $siteId): JSONResponse
	{
		try {
			$uid = $this->acl->assertCanSuperviseSite($siteId);
			$this->rateLimit->hitVerifyChain($siteId, $uid);
			return $this->ok($this->chain->verify($siteId));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function anchorNow(int $siteId): JSONResponse
	{
		try {
			$this->acl->assertCanManageSite($siteId);
			$a = $this->anchors->requestAnchor($siteId, 'manual');
			if ($a === null) {
				return $this->fail('no_entries', 'Nothing to anchor yet.', [], Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			$delivered = $this->anchors->deliver($a);
			return $this->ok(['anchor' => $a->jsonSerialize(), 'delivered' => $delivered]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
