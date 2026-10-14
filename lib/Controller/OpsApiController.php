<?php

declare(strict_types=1);

namespace OCA\TourCheck\Controller;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\AlertService;
use OCA\TourCheck\Service\ClientPortalService;
use OCA\TourCheck\Service\GeofenceService;
use OCA\TourCheck\Service\KpiService;
use OCA\TourCheck\Service\LogbookService;
use OCA\TourCheck\Service\ManDownService;
use OCA\TourCheck\Service\PositionService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCA\TourCheck\Service\TaskService;
use OCA\TourCheck\Service\WebhookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * Web JSON API for the competitive-parity surface: ops board/map, geofences,
 * tasks, logbook, KPIs, alerts, webhooks, escalation legs, client grants.
 * Same wire contract as ApiController (ApiJsonTrait) — uniform 404
 * throughout: every ID-scoped read/write first loads, then scope-checks,
 * so "not yours" and "doesn't exist" serialize identically.
 */
class OpsApiController extends Controller
{
	use ApiJsonTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly RoundService $rounds,
		private readonly PositionService $positions,
		private readonly GeofenceService $geofences,
		private readonly TaskService $tasks,
		private readonly LogbookService $logbook,
		private readonly KpiService $kpis,
		private readonly AlertService $alerts,
		private readonly WebhookService $webhooks,
		private readonly ClientPortalService $portal,
		private readonly ManDownService $manDown,
		private readonly SettingsService $settings,
		private readonly IUserManager $users,
		private readonly IDBConnection $db,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): string
	{
		return $this->acl->assertAppAccess();
	}

	// ── Ops board + map ────────────────────────────────────────────────

	/**
	 * Multi-site operations board — one card per visible site: active
	 * rounds, overdue checkpoints, open alerts, coverage state.
	 * Supervisor+ only (live worker state is heavier than read access).
	 */
	#[NoAdminRequired]
	public function opsBoard(): JSONResponse
	{
		try {
			$uid = $this->uid();
			if (!$this->acl->canSupervise($uid) && !$this->acl->isAppAdmin($uid)) {
				throw new NotFoundException(); // the ops board is supervisor-only
			}
			$canSupervise = $this->acl->canSupervise($uid);
			$cards = [];
			foreach ($this->sites->listForCurrentUser() as $site) {
				$sid = (int)$site->getId();
				if (!$canSupervise && $this->acl->siteRole($uid, $sid) === Constants::ROLE_WORKER) {
					continue; // workers never see the ops board
				}
				$cards[] = $this->siteCard($sid);
			}
			return $this->ok(['sites' => $cards, 'serverTime' => (new \DateTime())->format('c')]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	private function siteCard(int $siteId): array
	{
		$site = $this->sites->findById($siteId);
		$now = new \DateTime();
		$qb = $this->db->getQueryBuilder();

		$activeRounds = $qb->select('r.id', 'r.status', 'r.scheduled_start', 'r.window_end', 'r.started_by')
			->from('tck_round', 'r')
			->where($qb->expr()->eq('r.site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->in('r.status', $qb->createNamedParameter(['active', 'scheduled', 'missed'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('r.scheduled_start', $qb->createNamedParameter((new \DateTime('-12 hours'))->format('Y-m-d H:i:s'))))
			->orderBy('r.scheduled_start', 'ASC')
			->setMaxResults(20)
			->executeQuery()->fetchAll();

		$roundRows = [];
		foreach ($activeRounds as $r) {
			$overdue = $this->db->getQueryBuilder()
				->select($qb->func()->count('id'))
				->from('tck_round_cp')
				->where('round_id = :rid')->andWhere("status IN ('pending','missed')")
				->andWhere('latest < :now')
				->setParameter('rid', (int)$r['id'])
				->setParameter('now', $now->format('Y-m-d H:i:s'))
				->executeQuery()->fetchOne();
			$starter = $r['started_by'] !== null ? $this->users->get((string)$r['started_by']) : null;
			$lastPos = null;
			if ($r['status'] === 'active' && (int)$site->getLivePosition() === 1) {
				$qb2 = $this->db->getQueryBuilder();
				$lastPos = $qb2->select('lat', 'lon', 'server_received_at')
					->from('tck_position')
					->where($qb2->expr()->eq('round_id', $qb2->createNamedParameter((int)$r['id'])))
					->orderBy('id', 'DESC')->setMaxResults(1)
					->executeQuery()->fetch();
			}
			$roundRows[] = [
				'id' => (int)$r['id'],
				'status' => $r['status'],
				'scheduledStart' => $r['scheduled_start'],
				'windowEnd' => $r['window_end'],
				'worker' => $starter === null ? null : $starter->getDisplayName(),
				'overdueCheckpoints' => (int)$overdue,
				'lastPosition' => $lastPos === false || $lastPos === null ? null : [
					'lat' => (float)$lastPos['lat'], 'lon' => (float)$lastPos['lon'],
					'at' => $lastPos['server_received_at'],
				],
			];
		}

		$openAlerts = array_map(static fn ($a) => $a->jsonSerialize(), $this->alerts->openFor([$siteId]));
		$openTasks = $this->db->getQueryBuilder()
			->select($qb->func()->count('id'))
			->from('tck_task')
			->where('site_id = :sid')->andWhere("status = 'open'")
			->setParameter('sid', $siteId)->executeQuery()->fetchOne();

		$worst = 'ok';
		foreach ($roundRows as $r) {
			if ($r['status'] === 'missed' || $r['overdueCheckpoints'] > 0) {
				$worst = 'attention';
			}
		}
		foreach ($openAlerts as $a) {
			if (($a['severity'] ?? '') === 'critical') {
				$worst = 'critical';
			} elseif ($worst === 'ok') {
				$worst = 'attention';
			}
		}

		return [
			'site' => ['id' => $siteId, 'name' => $site->getName(), 'tz' => $site->getTz()],
			'severity' => $worst,
			'rounds' => $roundRows,
			'alerts' => $openAlerts,
			'openTasks' => (int)$openTasks,
			'manDown' => $this->manDown->configFor($siteId),
		];
	}

	/**
	 * Map payload — geofences + geo checkpoints + latest breadcrumbs for
	 * active rounds on ONE site. Supervisor+ (trails are supervise-scope).
	 */
	#[NoAdminRequired]
	public function opsMap(int $siteId): JSONResponse
	{
		try {
			$this->acl->assertCanSuperviseSite($siteId);
			$site = $this->sites->findById($siteId);
			if ($site === null) {
				throw new NotFoundException();
			}
			$qb = $this->db->getQueryBuilder();
			$cps = $qb->select('id', 'name', 'geo_lat', 'geo_lon', 'geo_radius', 'cp_kind')
				->from('tck_checkpoint')
				->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
				->andWhere($qb->expr()->isNotNull('geo_lat'))
				->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)))
				->executeQuery()->fetchAll();
			$qb = $this->db->getQueryBuilder();
			$positions = $qb->select('p.round_id', 'p.actor_uid', 'p.lat', 'p.lon', 'p.server_received_at')
				->from('tck_position', 'p')
				->innerJoin('p', 'tck_round', 'r', 'r.id = p.round_id')
				->where($qb->expr()->eq('r.site_id', $qb->createNamedParameter($siteId)))
				->andWhere($qb->expr()->eq('r.status', $qb->createNamedParameter('active')))
				->orderBy('p.id', 'DESC')
				->setMaxResults(200)
				->executeQuery()->fetchAll();
			// one marker per (round, actor) — newest wins
			$latest = [];
			foreach ($positions as $p) {
				$key = $p['round_id'] . '|' . $p['actor_uid'];
				if (!isset($latest[$key])) {
					$u = $this->users->get((string)$p['actor_uid']);
					$latest[$key] = [
						'roundId' => (int)$p['round_id'],
						'worker' => $u === null ? (string)$p['actor_uid'] : $u->getDisplayName(),
						'lat' => (float)$p['lat'], 'lon' => (float)$p['lon'],
						'at' => $p['server_received_at'],
					];
				}
			}
			return $this->ok([
				'site' => ['id' => $siteId, 'name' => $site->getName(), 'tz' => $site->getTz()],
				'tileUrl' => $this->settings->getTileUrl(), // '' = schematic fallback
				'geofences' => array_map(static fn ($g) => $g->jsonSerialize(), $this->geofences->listForSite($siteId)),
				'checkpoints' => $cps,
				'positions' => array_values($latest),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Trail replay — supervisor-only (US-F5-01). Bounded by retention. */
	#[NoAdminRequired]
	public function roundTrail(int $id): JSONResponse
	{
		try {
			$trail = array_map(static function ($p) {
				return [
					'lat' => (float)$p->getLat(),
					'lon' => (float)$p->getLon(),
					'accuracyM' => $p->getAccuracyM() === null ? null : (int)$p->getAccuracyM(),
					'at' => $p->getServerReceivedAt()?->format(\DateTimeInterface::ATOM),
					'clientAt' => $p->getClientTs()?->format(\DateTimeInterface::ATOM),
				];
			}, $this->positions->trailFor($id));
			return $this->ok(['trail' => $trail]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Geofences ───────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listGeofences(int $siteId): JSONResponse
	{
		try {
			return $this->ok(['geofences' => array_map(static fn ($g) => $g->jsonSerialize(), $this->geofences->listForSite($siteId))]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createGeofence(int $siteId): JSONResponse
	{
		try {
			$b = $this->request->getParams();
			$g = $this->geofences->create(
				$siteId,
				(string)($b['kind'] ?? 'perimeter'),
				(string)($b['shape'] ?? 'circle'),
				isset($b['center_lat']) && is_numeric($b['center_lat']) ? (float)$b['center_lat'] : null,
				isset($b['center_lon']) && is_numeric($b['center_lon']) ? (float)$b['center_lon'] : null,
				isset($b['radius_m']) && is_numeric($b['radius_m']) ? (int)$b['radius_m'] : null,
				isset($b['polygon']) && is_array($b['polygon']) ? $b['polygon'] : null,
			);
			return $this->ok($g->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateGeofence(int $id): JSONResponse
	{
		try {
			$b = $this->request->getParams();
			$g = $this->geofences->update(
				$id,
				(string)($b['shape'] ?? 'circle'),
				isset($b['center_lat']) && is_numeric($b['center_lat']) ? (float)$b['center_lat'] : null,
				isset($b['center_lon']) && is_numeric($b['center_lon']) ? (float)$b['center_lon'] : null,
				isset($b['radius_m']) && is_numeric($b['radius_m']) ? (int)$b['radius_m'] : null,
				isset($b['polygon']) && is_array($b['polygon']) ? $b['polygon'] : null,
			);
			return $this->ok($g->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteGeofence(int $id): JSONResponse
	{
		try {
			$this->geofences->delete($id);
			return $this->ok(['deleted' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Tasks ──────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listTasks(int $siteId): JSONResponse
	{
		try {
			$status = $this->request->getParam('status');
			$rows = $this->tasks->listForSite($siteId, is_string($status) && $status !== '' ? $status : null);
			return $this->ok(['tasks' => array_map(static fn ($t) => $t->jsonSerialize(), $rows)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createTask(int $siteId): JSONResponse
	{
		try {
			$b = $this->request->getParams();
			$due = null;
			if (isset($b['due_at']) && is_string($b['due_at']) && $b['due_at'] !== '') {
				$due = \DateTime::createFromFormat('Y-m-d\\TH:i', $b['due_at']) ?: new \DateTime($b['due_at']);
			}
			$t = $this->tasks->create($siteId, (string)($b['title'] ?? ''), $b['body'] ?? null,
				isset($b['assignee_uid']) && $b['assignee_uid'] !== '' ? (string)$b['assignee_uid'] : null,
				$due, $this->uid());
			return $this->ok($t->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateTask(int $id): JSONResponse
	{
		try {
			$b = $this->request->getParams();
			$baseRev = isset($b['revision']) && preg_match('/^\d+$/', (string)$b['revision']) === 1 ? (int)$b['revision'] : null;
			$t = $this->tasks->update($id, $baseRev, $b, $this->uid());
			return $this->ok($t->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function completeTask(int $id): JSONResponse
	{
		try {
			$b = $this->request->getParams();
			$t = $this->tasks->complete($id, $this->uid(), isset($b['note']) ? (string)$b['note'] : null);
			return $this->ok($t->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Logbook ─────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listLogbook(int $siteId): JSONResponse
	{
		try {
			$rows = $this->logbook->listForSite($siteId);
			return $this->ok(['entries' => array_map(static fn ($l) => $l->jsonSerialize(), $rows)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createLogbook(int $siteId): JSONResponse
	{
		try {
			$b = $this->request->getParams();
			$l = $this->logbook->create($siteId, $this->uid(),
				(string)($b['kind'] ?? 'note'), (string)($b['body'] ?? ''),
				($b['pinned'] ?? null) === '1' || ($b['pinned'] ?? null) === 'true' || ($b['pinned'] ?? null) === true);
			return $this->ok($l->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateLogbook(int $id): JSONResponse
	{
		try {
			$b = $this->request->getParams();
			$baseRev = isset($b['revision']) && preg_match('/^\d+$/', (string)$b['revision']) === 1 ? (int)$b['revision'] : null;
			$l = $this->logbook->update($id, $this->uid(), $baseRev, (string)($b['body'] ?? ''),
				array_key_exists('pinned', $b) ? (($b['pinned'] === '1' || $b['pinned'] === 'true' || $b['pinned'] === true)) : null);
			return $this->ok($l->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function ackLogbook(int $id): JSONResponse
	{
		try {
			$this->logbook->ack($id, $this->uid());
			return $this->ok(['acked' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── KPIs ────────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function siteKpis(int $siteId): JSONResponse
	{
		try {
			$from = \DateTime::createFromFormat('Y-m-d', (string)$this->request->getParam('from', '')) ?: new \DateTime('-30 days');
			$to = \DateTime::createFromFormat('Y-m-d', (string)$this->request->getParam('to', '')) ?: new \DateTime('tomorrow');
			if ($to < $from || $to->getTimestamp() - $from->getTimestamp() > 400 * 86400) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid date range.', ['range' => 'invalid']);
			}
			return $this->ok($this->kpis->forSite($siteId, $from, $to));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Alerts ──────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listAlerts(int $siteId): JSONResponse
	{
		try {
			$rows = $this->alerts->listForSite($siteId);
			return $this->ok(['alerts' => array_map(static fn ($a) => $a->jsonSerialize(), $rows)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function ackAlert(int $id): JSONResponse
	{
		try {
			return $this->ok($this->alerts->ack($id, $this->uid())->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function resolveAlert(int $id): JSONResponse
	{
		try {
			$b = $this->request->getParams();
			$falseAlarm = ($b['false_alarm'] ?? null) === '1' || ($b['false_alarm'] ?? null) === 'true' || ($b['false_alarm'] ?? null) === true;
			return $this->ok($this->alerts->resolve($id, $this->uid(), (string)($b['note'] ?? ''), $falseAlarm)->jsonSerialize());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Admin: webhooks / escalation legs / tiles / grants ──────────────

	#[NoAdminRequired]
	public function listWebhooks(): JSONResponse
	{
		try {
			$this->acl->assertAppAdmin();
			// secret is never serialized — Webhook::jsonSerialize omits it
			return $this->ok(['webhooks' => array_map(static fn ($w) => $w->jsonSerialize(), $this->webhooks->listEndpoints())]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createWebhook(): JSONResponse
	{
		try {
			$b = $this->request->getParams();
			$events = isset($b['events']) && is_array($b['events']) ? $b['events'] : [];
			$wh = $this->webhooks->createEndpoint((string)($b['url'] ?? ''), $events, isset($b['secret']) ? (string)$b['secret'] : null);
			// Shown ONCE — the row never serializes its secret again.
			return $this->ok(['webhook' => $wh->jsonSerialize(), 'secret' => $wh->getSecret()], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function rotateWebhookSecret(int $id): JSONResponse
	{
		try {
			$wh = $this->webhooks->rotateSecret($id);
			return $this->ok(['webhook' => $wh->jsonSerialize(), 'secret' => $wh->getSecret()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteWebhook(int $id): JSONResponse
	{
		try {
			$this->webhooks->deleteEndpoint($id);
			return $this->ok(['deleted' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function webhookDeliveries(int $id): JSONResponse
	{
		try {
			return $this->ok(['deliveries' => array_map(static fn ($d) => $d->jsonSerialize(), $this->webhooks->recentDeliveries($id))]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Replace a site's escalation leg chain (admin/planner on the site). */
	#[NoAdminRequired]
	public function setEscalationLegs(int $siteId): JSONResponse
	{
		try {
			$this->acl->assertCanManageSite($siteId);
			$b = $this->request->getParams();
			$legs = $b['legs'] ?? null;
			if (!is_array($legs) || count($legs) > 5) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'legs must be a list of ≤5.', ['legs' => 'invalid']);
			}
			// transactional replace — half a chain must never persist
			$this->db->beginTransaction();
			try {
				$this->db->getQueryBuilder()->delete('tck_escalation_leg')
					->where('site_id = :sid')->setParameter('sid', $siteId)->executeStatement();
				$no = 1;
				foreach ($legs as $leg) {
					if (!is_array($leg)) {
						continue;
					}
					$channel = (string)($leg['channel'] ?? '');
					if (!in_array($channel, ['nc_notify', 'mail', 'webhook'], true)) {
						throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown channel.', ['legs' => 'invalid']);
					}
					$delay = isset($leg['delay_min']) && is_numeric($leg['delay_min']) ? max(0, min(1440, (int)$leg['delay_min'])) : null;
					if ($delay === null) {
						throw new ValidationException(Constants::ERR_VALIDATION, 'delay_min required.', ['legs' => 'invalid']);
					}
					$this->db->getQueryBuilder()->insert('tck_escalation_leg')
						->values([
							'site_id' => $this->db->getQueryBuilder()->createNamedParameter($siteId),
							'leg_no' => $this->db->getQueryBuilder()->createNamedParameter($no),
							'delay_min' => $this->db->getQueryBuilder()->createNamedParameter($delay),
							'channel' => $this->db->getQueryBuilder()->createNamedParameter($channel),
							'target_json' => $this->db->getQueryBuilder()->createNamedParameter(json_encode($leg['target'] ?? [])),
						])->executeStatement();
					$no++;
				}
				$this->db->commit();
			} catch (\Throwable $t) {
				$this->db->rollBack();
				throw $t;
			}
			return $this->ok(['saved' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function getEscalationLegs(int $siteId): JSONResponse
	{
		try {
			$this->acl->assertCanManageSite($siteId);
			$legs = array_map(static fn ($l) => $l->jsonSerialize(), $this->alerts->legsFor($siteId));
			return $this->ok(['legs' => $legs]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** Tile provider opt-in — empty = schematic fallback (Q2). */
	#[NoAdminRequired]
	public function saveTileUrl(): JSONResponse
	{
		try {
			$this->acl->assertAppAdmin();
			$url = (string)$this->request->getParam('tile_url', '');
			if ($url !== '' && preg_match('#^https://[^\s]+\{z\}#i', $url) !== 1) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Tile URL must be an https:// template containing {z}/{x}/{y}.', ['tile_url' => 'invalid']);
			}
			$this->settings->setTileUrl($url);
			return $this->ok(['saved' => true, 'tileUrl' => $this->settings->getTileUrl()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── Client grants (planner/admin on the site) ──────────────────────

	#[NoAdminRequired]
	public function listClientGrants(int $siteId): JSONResponse
	{
		try {
			$rows = $this->portal->grantsForSite($siteId);
			return $this->ok(['grants' => array_map(static fn ($g) => $g->jsonSerialize(), $rows)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function grantClient(int $siteId): JSONResponse
	{
		try {
			$clientUid = (string)$this->request->getParam('client_uid', '');
			$this->portal->grant($clientUid, $siteId, $this->uid());
			return $this->ok(['granted' => true], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function revokeClient(int $siteId, string $uid): JSONResponse
	{
		try {
			$this->portal->revoke($uid, $siteId, $this->uid());
			return $this->ok(['revoked' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
