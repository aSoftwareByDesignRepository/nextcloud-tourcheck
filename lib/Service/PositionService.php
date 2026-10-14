<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\PositionConsent;
use OCA\TourCheck\Db\PositionConsentMapper;
use OCA\TourCheck\Db\PositionEvent;
use OCA\TourCheck\Db\PositionEventMapper;
use OCA\TourCheck\Exception\ValidationException;
use OCA\TourCheck\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Positions — breadcrumbs during an ACTIVE round only, opt-in per site,
 * hard retention cap (default 24 h then physical delete — positions are
 * operational data, not evidence, so they leave no journal trace).
 * Works-council positioning: only inside active rounds, never background.
 */
class PositionService
{
	/** Consent is per (uid, site) and valid for 12 h — a daily re-prompt. */
	public const CONSENT_VALID_S = 43200;

	public function __construct(
		private readonly PositionEventMapper $positions,
		private readonly PositionConsentMapper $consents,
		private readonly GeofenceService $geofences,
		private readonly RoundService $rounds,
		private readonly SiteService $sites,
		private readonly AccessControlService $acl,
		private readonly RateLimitService $rateLimit,
		private readonly SettingsService $settings,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Ingest a position. Only accepted while the round is active AND the
	 * site opted into live positions AND the actor is the round starter or
	 * a worker on that site.
	 */
	public function ingest(int $roundId, string $uid, float $lat, float $lon, ?int $accuracy, ?string $clientTs): void
	{
		if (abs($lat) > 90 || abs($lon) > 180) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Coordinates out of range.', ['geo' => 'invalid']);
		}
		$round = $this->rounds->findById($roundId);
		if ($round === null) {
			throw new NotFoundException();
		}
		// Site-scope first: a round id is no license to inject positions into
		// a site the actor cannot see (GA-1).
		$this->acl->assertCanViewSite((int)$round->getSiteId());
		$site = $this->sites->findById((int)$round->getSiteId());
		if ($site === null || (int)$site->getLivePosition() !== 1) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Live positions are disabled for this site.', ['geo' => 'disabled']);
		}
		if ($round->getStatus() !== 'active') {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Positions only during an active round.', ['round' => 'not_active']);
		}
		// Breadcrumb integrity: only the round starter or a site WORKER may
		// write positions. View-scoped roles (supervisor/planner/admin) read
		// the trail via trailFor — they must never inject breadcrumbs into a
		// round they are not physically working (works-council surface).
		$role = $this->acl->siteRole($uid, (int)$round->getSiteId());
		if ($role !== Constants::ROLE_WORKER && $round->getStartedBy() !== $uid) {
			throw new NotFoundException();
		}
		// Worker consent — the site opted in is not the worker's consent.
		// NN: no tracking without an in-window consent row, and a revoke
		// stops writes immediately (not just "next round").
		$this->assertConsent($uid, (int)$round->getSiteId());
		// ≤1 update per 30 s per (round, actor) — breadcrumbs, not telemetry.
		$this->rateLimit->check('pos', $roundId . '|' . $uid, 1, 30);
		$now = $this->timeFactory->getDateTime();
		$p = new PositionEvent();
		$p->setRoundId($roundId);
		$p->setActorUid($uid);
		$p->setLat($lat);
		$p->setLon($lon);
		$p->setAccuracyM($accuracy !== null ? max(0, (int)$accuracy) : null);
		$p->setServerReceivedAt($now);
		if ($clientTs !== null && $clientTs !== '') {
			// Clients legitimately send RFC 3339 with 'Z' (JS toISOString) or an
			// offset — accept the strict shape, reject relative/garbage strings
			// that \DateTime would otherwise guess into arbitrary times.
			$dt = null;
			if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $clientTs) === 1) {
				try {
					$dt = new \DateTime($clientTs);
				} catch (\Exception) {
					$dt = null;
				}
			}
			$p->setClientTs($dt);
		}
		$p->setExpiresAt((new \DateTime())->setTimestamp($now->getTimestamp() + $this->settings->getPositionRetentionHours() * 3600));
		$this->positions->insert($p);
		// Geofence evaluation piggy-backs on accepted positions only — a
		// rejected position must never move boundary state.
		try {
			$this->geofences->evaluate($uid, (int)$round->getSiteId(), $roundId, $lat, $lon, $accuracy ?? 0);
		} catch (\Throwable) {
			// A geofence bug must not drop the position breadcrumb.
		}
	}

	/** Worker grants position consent for a site (companion prompt). */
	public function grantConsent(string $uid, int $siteId): void
	{
		$this->acl->assertCanViewSite($siteId);
		$now = $this->timeFactory->getDateTime();
		$c = $this->consents->findFor($uid, $siteId);
		if ($c === null) {
			$c = new PositionConsent();
			$c->setUid($uid);
			$c->setSiteId($siteId);
			$c->setGrantedAt($now);
			$this->consents->insert($c);
			return;
		}
		// Re-grant renews the window; a revoke is undone by explicit action.
		$c->setGrantedAt($now);
		$c->setRevokedAt(null);
		$this->consents->update($c);
	}

	/** Worker revokes — immediate: no new positions accepted, existing rows still expire on schedule. */
	public function revokeConsent(string $uid, int $siteId): void
	{
		$this->acl->assertCanViewSite($siteId);
		$c = $this->consents->findFor($uid, $siteId);
		if ($c !== null && $c->getRevokedAt() === null) {
			$c->setRevokedAt($this->timeFactory->getDateTime());
			$this->consents->update($c);
		}
	}

	/** True while the worker has a consent grant younger than the window. */
	public function hasConsent(string $uid, int $siteId): bool
	{
		$c = $this->consents->findFor($uid, $siteId);
		if ($c === null || $c->getRevokedAt() !== null) {
			return false;
		}
		return $c->getGrantedAt()->getTimestamp() > $this->timeFactory->getTime() - self::CONSENT_VALID_S;
	}

	private function assertConsent(string $uid, int $siteId): void
	{
		if (!$this->hasConsent($uid, $siteId)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Position consent required — grant it in the app first.', ['consent' => 'required']);
		}
	}

	/**
	 * @return list<PositionEvent> trail for a round. Supervisor+ only —
	 * movement breadcrumbs are heavier PII than the round itself (a worker
	 * may view the round but must not pull coworker trails).
	 */
	public function trailFor(int $roundId): array
	{
		$round = $this->rounds->findById($roundId);
		if ($round === null) {
			throw new NotFoundException();
		}
		$this->acl->assertCanSuperviseSite((int)$round->getSiteId());
		return $this->positions->findForRound($roundId);
	}

	/** Retention sweep — physical delete past expires_at (cron). */
	public function purgeExpired(): int
	{
		return $this->positions->deleteExpired($this->timeFactory->getDateTime());
	}
}
