<?php

declare(strict_types=1);

namespace OCA\TourCheck\Controller;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Db\SiteMemberMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\CheckpointService;
use OCA\TourCheck\Service\DashboardService;
use OCA\TourCheck\Service\IncidentService;
use OCA\TourCheck\Service\LicenseService;
use OCA\TourCheck\Service\LivePageService;
use OCA\TourCheck\Service\QrSheetService;
use OCA\TourCheck\Service\TokenService;
use OCA\TourCheck\Service\PlanService;
use OCA\TourCheck\Service\ReportService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SettingsSectionCatalog;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\StationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly DashboardService $dashboard,
		private readonly RoundService $rounds,
		private readonly CheckpointService $checkpoints,
		private readonly PlanService $plans,
		private readonly IncidentService $incidents,
		private readonly LicenseService $license,
		private readonly ReportService $reports,
		private readonly StationService $stations,
		private readonly SettingsService $settings,
		private readonly JournalEntryMapper $journal,
		private readonly TokenService $tokens,
		private readonly LivePageService $liveLinks,
		private readonly \OCA\TourCheck\Service\KeyService $keyService,
		private readonly \OCA\TourCheck\Service\AnchorService $anchors,
		private readonly \OCA\TourCheck\Service\PositionService $positions,
		private readonly \OCA\TourCheck\Service\TemplateService $templates,
		private readonly \OCA\TourCheck\Service\MaintenanceHandoffService $maintenanceHandoff,
		private readonly QrSheetService $qr,
		private readonly SiteMemberMapper $siteMembers,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	private function nav(): array
	{
		$r = 'tourcheck.page.';
		$nav = [
			['id' => 'dashboard', 'label' => $this->l->t('Dashboard'), 'icon' => 'layout-grid', 'group' => 'work', 'href' => $this->urlGenerator->linkToRoute($r . 'dashboard')],
			['id' => 'sites', 'label' => $this->l->t('Sites'), 'icon' => 'building-2', 'group' => 'work', 'href' => $this->urlGenerator->linkToRoute($r . 'sites'), 'match' => ['site']],
			['id' => 'rounds', 'label' => $this->l->t('Rounds'), 'icon' => 'route', 'group' => 'work', 'href' => $this->urlGenerator->linkToRoute($r . 'rounds'), 'match' => ['round']],
			['id' => 'journal', 'label' => $this->l->t('Journal'), 'icon' => 'clipboard-list', 'group' => 'evidence', 'href' => $this->urlGenerator->linkToRoute($r . 'journal')],
			['id' => 'incidents', 'label' => $this->l->t('Incidents'), 'icon' => 'flag', 'group' => 'evidence', 'href' => $this->urlGenerator->linkToRoute($r . 'incidents')],
			['id' => 'reports', 'label' => $this->l->t('Reports'), 'icon' => 'file-text', 'group' => 'evidence', 'href' => $this->urlGenerator->linkToRoute($r . 'reports')],
		];
		// Operations board is a supervisor surface — workers never see it.
		if ($this->acl->canSupervise($this->acl->requireUid()) || $this->acl->isAppAdmin()) {
			$nav[] = ['id' => 'ops', 'label' => $this->l->t('Operations'), 'icon' => 'map-pin', 'group' => 'work', 'href' => $this->urlGenerator->linkToRoute($r . 'ops'), 'match' => ['opsSite']];
		}
		if ($this->acl->isAppAdmin() || $this->acl->canPlan($this->acl->requireUid())) {
			$nav[] = ['id' => 'settings', 'label' => $this->l->t('Settings'), 'icon' => 'settings', 'group' => 'admin', 'href' => $this->urlGenerator->linkToRoute($r . 'settingsIndex')];
		}
		return $nav;
	}

	private ?array $scopeCache = null;

	private function scope(): array
	{
		if ($this->scopeCache !== null) {
			return $this->scopeCache;
		}
		$uid = $this->acl->requireUid();
		$sites = $this->sites->listForCurrentUser();
		$current = (int)$this->request->getParam('site', 0);
		if ($current <= 0 && $sites !== []) {
			$current = (int)$sites[0]->getId();
		}
		return $this->scopeCache = [
			'sites' => $sites,
			'multiSite' => count($sites) > 1,
			'currentSiteId' => $current,
			'isAppAdmin' => $this->acl->isAppAdmin($uid),
			'isSupervisor' => $this->acl->canSupervise($uid),
		];
	}

	private function shell(string $pageId, array $params = []): TemplateResponse
	{
		try {
			$this->acl->assertAppAccess();
		} catch (NotFoundException) {
			// Restricted must be indistinguishable from absent — a 403 here
			// would disclose the app exists to excluded users.
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		Util::addTranslations(Constants::APP_ID);
		Util::addScript(Constants::APP_ID, 'common/field-errors');
		Util::addStyle(Constants::APP_ID, 'common/field-errors');
		$response = new TemplateResponse(Constants::APP_ID, 'main', array_merge([
			'pageId' => $pageId,
			'urlGenerator' => $this->urlGenerator,
			'nav' => $this->nav(),
			'canManage' => $this->acl->isAppAdmin() || $this->acl->canPlan($this->acl->requireUid()),
		], $this->scope(), $params));
		$response->addHeader('X-Frame-Options', 'DENY');
		return $response;
	}

	/** @return array{name:string,summary:string,revision:int,active:bool} */
	private function planRow(\OCA\TourCheck\Db\TourPlan $plan): array
	{
		$rec = json_decode((string)$plan->getRecurrenceJson(), true) ?: [];
		$type = (string)($rec['type'] ?? '');
		$durMin = (int)($rec['duration_min'] ?? 0);
		$dur = $durMin >= 60 && $durMin % 60 === 0
			? $this->l->t('%d h', intdiv($durMin, 60))
			: $this->l->t('%d min', $durMin);
		$summary = match ($type) {
			'daily' => $this->l->t('Daily at %s · %s', [(string)($rec['time'] ?? ''), $dur]),
			'weekly' => $this->l->t('Weekly %s at %s · %s', [
				implode(', ', array_map(fn ($d) => $this->weekday((int)$d), (array)($rec['days'] ?? []))),
				(string)($rec['time'] ?? ''),
				$dur,
			]),
			'interval' => $this->l->t('Every %d h · %s', [(int)($rec['interval_hours'] ?? 0), $dur]),
			default => $type !== '' ? $type : $this->l->t('No schedule'),
		};
		if ($plan->getCheckinIntervalMin() !== null && (int)$plan->getCheckinIntervalMin() > 0) {
			$summary .= ' · ' . $this->l->t('check-in every %d min', (int)$plan->getCheckinIntervalMin());
		}
		return [
			'id' => (int)$plan->getId(),
			'name' => $plan->getName(),
			'summary' => $summary,
			'revision' => (int)$plan->getRevision(),
			'active' => (int)$plan->getActive() === 1,
			'checkin_interval_min' => $plan->getCheckinIntervalMin() !== null ? (int)$plan->getCheckinIntervalMin() : null,
		];
	}

	private function weekday(int $d): string
	{
		return match ($d) {
			0 => $this->l->t('Mon'), 1 => $this->l->t('Tue'), 2 => $this->l->t('Wed'),
			3 => $this->l->t('Thu'), 4 => $this->l->t('Fri'), 5 => $this->l->t('Sat'),
			default => $this->l->t('Sun'),
		};
	}

	private function siteName(int $siteId): string
	{
		foreach ($this->scope()['sites'] as $s) {
			if ((int)$s->getId() === $siteId) {
				return $s->getName();
			}
		}
		return '';
	}

	private function fmt(\DateTimeInterface $d): string
	{
		return $this->l->l('datetime', $d->getTimestamp());
	}

	/** uid → display name; unknown/deleted users fall back to the raw uid. */
	private function actorName(?string $uid): ?string
	{
		if ($uid === null || $uid === '') { return null; }
		return $this->userManager->get($uid)?->getDisplayName() ?? $uid;
	}

	private function roundRow(\OCA\TourCheck\Db\Round $r): array
	{
		return [
			'id' => (int)$r->getId(),
			'siteName' => $this->siteName((int)$r->getSiteId()),
			'windowStartLabel' => $this->fmt($r->getScheduledStart()),
			'windowEndLabel' => $this->fmt($r->getWindowEnd()),
			'status' => $r->getStatus(),
		];
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): Response
	{
		try {
			$this->acl->assertAppAccess();
		} catch (NotFoundException) {
			// No redirect for excluded users — a 303 here would disclose the
			// app exists where a missing app returns 404.
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		return new RedirectResponse($this->urlGenerator->linkToRoute('tourcheck.page.dashboard'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): Response
	{
		try {
			$this->acl->assertAppAccess();
		} catch (NotFoundException) {
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		$home = $this->dashboard->home();
		$uid = $this->acl->requireUid();
		$scope = $this->scope();

		$next = [];
		foreach ($home['rounds']['next'] as $r) {
			$next[] = [
				'id' => $r['id'],
				'siteName' => $this->siteName($r['site_id']),
				'windowStartLabel' => $this->fmt(new \DateTimeImmutable($r['start'])),
				'windowEndLabel' => $this->fmt(new \DateTimeImmutable($r['window_end'])),
				'status' => $r['status'],
			];
		}
		$open = [];
		foreach ($this->incidents->listVisible('open', 5) as $i) {
			$open[] = [
				'title' => $i->getTitle(),
				'siteName' => $this->siteName((int)$i->getSiteId()),
				'severity' => $i->getSeverity(),
			];
		}

		/* UX-5 / §12: guided first run — honest steps, only what's missing. */
		$coach = ['steps' => []];
		if ($this->acl->canPlan($uid) || $this->acl->isAppAdmin($uid)) {
			$hasSites = $scope['sites'] !== [];
			$hasCps = false;
			$hasPlan = false;
			if ($hasSites) {
				$siteId = (int)$scope['sites'][0]->getId();
				$hasCps = $this->checkpoints->listForSite($siteId) !== [];
				$hasPlan = $this->plans->listForSite($siteId) !== [];
			}
			if (!($hasSites && $hasCps && $hasPlan)) {
				$coach['steps'] = [
					['label' => $this->l->t('Create your first site'), 'done' => $hasSites, 'href' => $this->urlGenerator->linkToRoute('tourcheck.page.sites'), 'cta' => $this->l->t('New site')],
					['label' => $this->l->t('Add checkpoints in walk order and print the QR sheet'), 'done' => $hasCps, 'href' => $hasSites ? $this->urlGenerator->linkToRoute('tourcheck.page.site', ['siteId' => (int)$scope['sites'][0]->getId()]) : null, 'cta' => $this->l->t('Open site')],
					['label' => $this->l->t('Activate a plan — rounds schedule themselves'), 'done' => $hasPlan, 'href' => $hasSites ? $this->urlGenerator->linkToRoute('tourcheck.page.site', ['siteId' => (int)$scope['sites'][0]->getId()]) : null, 'cta' => $this->l->t('New plan')],
				];
			}
		}

		return $this->shell('dashboard', [
			'dashboard' => [
				'due' => $home['rounds']['upcoming'],
				'active' => $home['rounds']['active'],
				'missed' => $home['rounds']['missed'],
				'done' => (int)($home['rounds']['done'] ?? 0),
				'nextRounds' => $next,
				'openIncidents' => $open,
				/* §spec cron_stale: admin-visible banner when background jobs
				   starve — a dead cron must never fail silently. */
				'cronStale' => !empty($home['cron_stale']) && !empty($home['can_admin']),
			],
			'coach' => $coach,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function sites(): Response
	{
		return $this->shell('sites', ['defaultTz' => date_default_timezone_get()]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function site(int $siteId): Response
	{
		try {
			$site = $this->sites->getVisible($siteId);
		} catch (NotFoundException) {
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		$members = [];
		foreach ($this->siteMembers->findBySite($siteId) as $m) {
			$u = $this->userManager->get((string)$m->getUid());
			$members[] = [
				'uid' => $m->getUid(),
				'displayName' => $u?->getDisplayName() ?? $m->getUid(),
				'role' => $m->getRole(),
			];
		}
		$links = [];
		foreach ($this->liveLinks->listForSite($siteId) as $link) {
			$links[] = [
				'id' => (int)$link->getId(),
				'label' => $link->getLabel(),
				'expiresAt' => $link->getExpiresAt()?->format('Y-m-d'),
				'revoked' => $link->getRevokedAt() !== null,
				'createdAt' => $link->getCreatedAt()?->format('Y-m-d'),
			];
		}
		$cps = $this->checkpoints->listForSite($siteId);
		$kinds = $this->tokens->activeKindMap(array_map(static fn ($c) => (int)$c->getId(), $cps));
		return $this->shell('site', [
			'site' => $site,
			'pageTitle' => $site->getName(),
			'checkpoints' => $cps,
			'checkpointKinds' => $kinds,
			'plans' => array_map(fn ($p) => $this->planRow($p), $this->plans->listForSite($siteId)),
			'members' => $members,
			'liveLinks' => $links,
			'liveBase' => $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute('tourcheck.publicPage.live', ['token' => 'TCKTOKENPLACEHOLDERTCKTOKENPLACEHOLDERTCK1'])
			),
			'keys' => array_map(static fn ($k) => $k->jsonSerialize(), $this->keyService->listForSite($siteId)),
			'keyLog' => array_map(static fn ($l) => $l->jsonSerialize(), $this->keyService->logForSite($siteId)),
			'templates' => array_map(static fn ($t) => $t->jsonSerialize(), $this->templates->listAll()),
		]);
	}

	/** Printable QR sheet — mints fresh tokens; old stickers die on re-print. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function qrSheet(int $siteId): Response
	{
		try {
			$site = $this->sites->getVisible($siteId);
			$rows = $this->checkpoints->sheetTokensForSite($siteId);
		} catch (NotFoundException) {
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		$items = [];
		foreach ($rows as $row) {
			$payload = TokenService::URL_PREFIX . $row['token'];
			$items[] = [
				'name' => $row['checkpoint']->getName(),
				'instructions' => $row['checkpoint']->getInstructions(),
				'svg' => $this->qr->svg($payload),
				'payload' => $payload,
			];
		}
		return new TemplateResponse(Constants::APP_ID, 'sheet', [
			'site' => $site,
			'items' => $items,
			'printedAt' => new \DateTime(),
		], TemplateResponse::RENDER_AS_BLANK);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rounds(): Response
	{
		$scope = $this->scope();
		$rows = [];
		foreach ($this->rounds->inWindow(array_map(static fn ($s) => (int)$s->getId(), $scope['sites']), $this->acl->isAppAdmin()) as $r) {
			$rows[] = $this->roundRow($r);
		}
		// Deep links from the dashboard tiles preselect the status pill.
		$statusParam = (string)$this->request->getParam('status', '');
		$allowed = ['scheduled', 'active', 'missed', 'done', 'skipped'];
		return $this->shell('rounds', [
			'rounds' => $rows,
			'roundsStatus' => in_array($statusParam, $allowed, true) ? $statusParam : '',
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function round(int $roundId): Response
	{
		try {
			$round = $this->rounds->getVisible($roundId);
		} catch (NotFoundException) {
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		$items = [];
		$done = 0;
		$events = [];
		foreach ($this->rounds->checkpointsOf($roundId) as $rcp) {
			$cp = $this->checkpoints->getAny((int)$rcp->getCheckpointId());
			if ($rcp->getStatus() === 'done' || $rcp->getStatus() === 'attested') {
				$done++;
			}
			$items[] = [
				'checkpointName' => $cp?->getName() ?? ('#' . $rcp->getCheckpointId()),
				'status' => $rcp->getStatus(),
				'eventLabel' => $rcp->getEventId() !== null ? '' : '',
			];
		}
		$uid = $this->acl->requireUid();
		// Live-position trail — supervisor+ only (PositionService enforces
		// site-level supervise itself; missing right ⇒ no section, not an error).
		$trail = null;
		try {
			foreach ($this->positions->trailFor($roundId) as $p) {
				$trail[] = [
					'timeLabel' => $p->getServerReceivedAt() !== null ? $this->fmt($p->getServerReceivedAt()) : '',
					'actor' => $this->actorName($p->getActorUid()),
					'lat' => $p->getLat(),
					'lon' => $p->getLon(),
					'accuracy' => $p->getAccuracyM(),
				];
			}
		} catch (NotFoundException) {
			$trail = null;
		}
		return $this->shell('round', [
			'pageTitle' => $this->l->t('Round #%d', [$roundId]),
			'round' => [
				'id' => $roundId,
				'siteName' => $this->siteName((int)$round->getSiteId()),
				'windowStartLabel' => $this->fmt($round->getScheduledStart()),
				'windowEndLabel' => $this->fmt($round->getWindowEnd()),
				'status' => $round->getStatus(),
				'skipReason' => $round->getSkipReason(),
				'doneCount' => $done,
				'totalCount' => count($items),
				'canAttest' => $this->acl->canSupervise($uid),
				'canSupervise' => $this->acl->canSupervise($uid),
				'items' => $items,
			],
			'trail' => $trail,
		]);
	}

	/**
	 * Operations board + live map (F2/F5). Supervisor-only — the shell's
	 * uniform-404 keeps workers from learning the page exists.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ops(): Response
	{
		try {
			$this->acl->assertAppAccess();
			$uid = $this->acl->requireUid();
			if (!$this->acl->canSupervise($uid) && !$this->acl->isAppAdmin()) {
				throw new NotFoundException();
			}
		} catch (NotFoundException) {
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		Util::addStyle(Constants::APP_ID, 'vendor/leaflet/leaflet');
		Util::addScript(Constants::APP_ID, 'vendor/leaflet/leaflet');
		Util::addStyle(Constants::APP_ID, 'ops');
		Util::addScript(Constants::APP_ID, 'ops');
		return $this->shell('ops', [
			'canManage' => $this->acl->isAppAdmin() || $this->acl->canPlan($uid),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function opsSite(int $siteId): Response
	{
		return $this->ops();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function journal(): Response
	{
		$scope = $this->scope();
		$entries = [];
		$anchorStatus = null;
		if ($scope['currentSiteId'] > 0) {
			try {
				$this->acl->assertCanViewSite($scope['currentSiteId']);
				$anchorStatus = $this->anchors->latestState($scope['currentSiteId']);
				foreach ($this->journal->findPage($scope['currentSiteId'], null, null, 100, 0) as $e) {
					$entries[] = [
						'timeLabel' => $e->getServerTs() ? $this->fmt($e->getServerTs()) : '',
						'what' => $e->getBody(),
						'actor' => $this->actorName($e->getActorUid()) ?? ($e->getMemberId() !== null ? $this->l->t('member #%d', [(int)$e->getMemberId()]) : '—'),
						'method' => $e->getKind(),
						'flag' => '',
					];
				}
			} catch (NotFoundException) {
				// scope site not viewable — empty journal, same as no site.
			}
		}
		return $this->shell('journal', [
			'entries' => $entries,
			'siteId' => $scope['currentSiteId'],
			'anchorStatus' => $anchorStatus,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function incidents(): Response
	{
		$rows = [];
		$uid = $this->acl->requireUid();
		foreach ($this->incidents->listVisible(null, 100) as $i) {
			$siteId = (int)$i->getSiteId();
			$role = $this->acl->siteRole($uid, $siteId);
			$rows[] = [
				'id' => (int)$i->getId(),
				'title' => $i->getTitle(),
				'note' => $i->getNote(),
				'siteName' => $this->siteName($siteId),
				'siteId' => $siteId,
				'severity' => $i->getSeverity(),
				'status' => $i->getStatus(),
				'revision' => (int)$i->getRevision(),
				'canSupervise' => in_array($role, [Constants::ROLE_ADMIN, Constants::ROLE_PLANNER, Constants::ROLE_SUPERVISOR], true),
				'woRef' => $i->getWoRef(),
				'timeLabel' => $i->getCreatedAt() ? $this->fmt($i->getCreatedAt()) : '',
			];
		}
		return $this->shell('incidents', ['incidents' => $rows, 'uid' => $uid, 'mnInstalled' => $this->maintenanceHandoff->installed()]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reports(): Response
	{
		$scope = $this->scope();
		$rows = [];
		$schedules = [];
		if ($scope['currentSiteId'] > 0) {
			foreach ($this->reports->listDeliveries($scope['currentSiteId']) as $d) {
				$rows[] = [
					'id' => (int)$d->getId(),
					'name' => $d->getKind() . ' · ' . $d->getPeriod(),
					'periodLabel' => $d->getCreatedAt() ? $this->fmt($d->getCreatedAt()) : '',
					'audience' => (string)$d->getAudience(),
					'clientVisible' => (bool)$d->getClientVisible(),
				];
			}
			foreach ($this->reports->listSchedules($scope['currentSiteId']) as $s) {
				$schedules[] = [
					'cadence' => (string)$s->getCadence(),
					'recipient' => (string)$s->getRecipient(),
					'audience' => \OCA\TourCheck\Service\ReportService::audienceOf($s),
					'active' => (int)$s->getActive() === 1,
				];
			}
		}
		return $this->shell('reports', ['reports' => $rows, 'schedules' => $schedules]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsIndex(): Response
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute('tourcheck.page.settings', ['section' => 'site-defaults']));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(string $section): Response
	{
		try {
			$this->acl->assertAppAdmin();
		} catch (NotFoundException) {
			$r = new TemplateResponse('core', '403', [], 'guest');
			$r->setStatus(Http::STATUS_FORBIDDEN);
			return $r;
		}
		$labels = [
			'site-defaults' => $this->l->t('Site defaults'),
			'evidence' => $this->l->t('Evidence & anchoring'),
			'retention' => $this->l->t('Retention'),
			'branding' => $this->l->t('Branding'),
			'stations' => $this->l->t('Stations'),
			'access' => $this->l->t('Access'),
			'license' => $this->l->t('License'),
			'feedback' => $this->l->t('Feedback'),
		];
		if (!isset($labels[$section])) {
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		$urls = [];
		foreach (array_keys($labels) as $k) {
			$urls[$k] = $this->urlGenerator->linkToRoute('tourcheck.page.settings', ['section' => $k]);
		}

		$scope = $this->scope();
		$stationRows = [];
		$siteCheckpoints = [];
		foreach ($scope['sites'] as $site) {
			$siteId = (int)$site->getId();
			$siteCheckpoints[$siteId] = array_map(static fn ($c) => [
				'id' => (int)$c->getId(),
				'name' => $c->getName(),
			], $this->checkpoints->listForSite($siteId));
			foreach ($this->stations->listForSite($siteId) as $st) {
				$stationRows[] = [
					'id' => (int)$st->getId(),
					'name' => $st->getLabel(),
					'siteId' => $siteId,
					'checkpointId' => $st->getCheckpointId() !== null ? (int)$st->getCheckpointId() : null,
					'siteName' => $site->getName(),
					'lastSeenLabel' => $st->getLastSeenAt() ? $this->fmt($st->getLastSeenAt()) : $this->l->t('never'),
				];
			}
		}

		return $this->shell('settings', [
			'settingsSection' => $section,
			'settingsSectionLabel' => $labels[$section],
			'settingsSectionLabels' => $labels,
			'urls' => ['settingsSections' => $urls],
			'pageTitle' => $this->l->t('Settings'),
			'settings' => $this->settings->snapshot(),
			'stations' => $stationRows,
			'siteCheckpoints' => $siteCheckpoints,
			'siteOptions' => array_map(static fn ($s) => ['id' => (int)$s->getId(), 'name' => $s->getName()], $scope['sites']),
			'license' => $this->license->getLicenseSummary() ?? [],
			'supportUsLinks' => new \OCA\TourCheck\Support\SupportUsLinks(
				'TourCheck',
				true,
				$this->urlGenerator->linkToRouteAbsolute('tourcheck.page.settings', ['section' => 'license']),
			),
		]);
	}
}
