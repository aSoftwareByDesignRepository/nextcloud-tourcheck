<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\ClientGrantMapper;
use OCA\TourCheck\Db\ReportDelivery;
use OCA\TourCheck\Db\ReportDeliveryMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Client portal read model (F6, Q1 decision: real NC accounts in the
 * `tourcheck_client` group, site scope via tck_client_grant rows).
 *
 * PRIVACY WALL (NN-03): everything here is site-level aggregate — no worker
 * uid, no positions, no trails, no per-checkpoint actor attribution. If a
 * query returns worker-identifying data, that's a defect by definition.
 */
class ClientPortalService
{
	public function __construct(
		private readonly ClientGrantMapper $grants,
		private readonly \OCA\TourCheck\Db\SiteMapper $sites,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly AccessControlService $acl,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly ReportDeliveryMapper $deliveries,
		private readonly ReportService $reports,
	) {
	}

	public function isClient(string $uid): bool
	{
		return $uid !== '' && $this->groups->isInGroup($uid, Constants::GROUP_CLIENT);
	}

	/** Uniform-404 door — a non-client must not learn the portal exists. */
	public function assertClient(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null || !$this->isClient($user->getUID())) {
			throw new NotFoundException();
		}
		return $user->getUID();
	}

	/** @return list<int> granted site ids */
	public function sitesFor(string $clientUid): array
	{
		return $this->grants->siteIdsFor($clientUid);
	}

	/** Uniform-404 scope check — granted and missing look identical. */
	public function assertSiteScope(string $clientUid, int $siteId): void
	{
		if (!$this->grants->hasAccess($clientUid, $siteId)) {
			throw new NotFoundException();
		}
	}

	/**
	 * Portal overview for one site — aggregates only.
	 * @return array<string,mixed>
	 */
	public function overview(string $clientUid, int $siteId): array
	{
		$this->assertSiteScope($clientUid, $siteId);
		$site = $this->sites->find($siteId);
		if ($site === null || (int)$site->getActive() !== 1) {
			throw new NotFoundException();
		}

		$qb = $this->db->getQueryBuilder();
		$todayStart = (new \DateTime('today'))->format('Y-m-d H:i:s');
		$tomorrow = (new \DateTime('tomorrow'))->format('Y-m-d H:i:s');

		// rounds today — counts by status, NO worker columns selected
		$rows = $qb->select('status', $qb->func()->count('id', 'cnt'))
			->from('tck_round')
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->gte('scheduled_start', $qb->createNamedParameter($todayStart)))
			->andWhere($qb->expr()->lt('scheduled_start', $qb->createNamedParameter($tomorrow)))
			->groupBy('status')
			->executeQuery()->fetchAll();
		$rounds = ['scheduled' => 0, 'active' => 0, 'completed' => 0, 'partial' => 0, 'missed' => 0, 'skipped' => 0];
		foreach ($rows as $r) {
			$rounds[$r['status']] = (int)$r['cnt'];
		}

		$qb = $this->db->getQueryBuilder();
		$incidents = $qb->select('status', 'severity', $qb->func()->count('id', 'cnt'))
			->from('tck_incident')
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->groupBy('status', 'severity')
			->executeQuery()->fetchAll();

		return [
			'site' => [
				'id' => $siteId,
				'name' => $site->getName(),
				'address' => $site->getAddress(),
				'tz' => $site->getTz(),
			],
			'rounds_today' => $rounds,
			'incidents' => $incidents,
			'as_of' => $this->timeFactory->getDateTime()->format(\DateTimeInterface::ATOM),
		];
	}

	/**
	 * Incident list for the client — id/severity/status/age only. Titles and
	 * bodies stay internal; clients see that an incident exists, not its
	 * worker-identifying or sensitive content.
	 * @return list<array<string,mixed>>
	 */
	/**
	 * Curated report list — only deliveries a planner marked client_visible
	 * AND that were generated for the client audience (redacted). Exposes
	 * id/period/createdAt only — never worker data.
	 */
	public function reports(string $clientUid, int $siteId): array
	{
		$this->assertSiteScope($clientUid, $siteId);
		$out = [];
		foreach ($this->deliveries->findClientVisible($siteId) as $d) {
			if ((string)$d->getAudience() !== 'client') {
				continue; // belt: flag without redacted audience never leaks
			}
			$out[] = [
				'id' => (int)$d->getId(),
				'period' => (string)$d->getPeriod(),
				'kind' => (string)$d->getKind(),
				'createdAt' => $d->getCreatedAt() === null ? null : $d->getCreatedAt()->format(\DateTimeInterface::ATOM),
			];
		}
		return $out;
	}

	/** Gated delivery row for the portal download route. */
	public function reportForDownload(string $clientUid, int $id): ReportDelivery
	{
		$d = $this->deliveries->find($id);
		if ($d === null || !(bool)$d->getClientVisible() || (string)$d->getAudience() !== 'client') {
			throw new NotFoundException();
		}
		$this->assertSiteScope($clientUid, (int)$d->getSiteId());
		return $d;
	}

	/** Stored PDF for a portal-visible delivery, or null. */
	public function reportFile(ReportDelivery $d): ?\OCP\Files\SimpleFS\ISimpleFile
	{
		return $this->reports->deliveryFile($d);
	}

	public function incidents(string $clientUid, int $siteId): array
	{
		$this->assertSiteScope($clientUid, $siteId);
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('id', 'status', 'severity', 'created_at', 'updated_at')
			->from('tck_incident')
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('created_at', 'DESC')
			->setMaxResults(100)
			->executeQuery()->fetchAll();
		return array_map(static function ($r) {
			return [
				'id' => (int)$r['id'],
				'status' => $r['status'],
				'severity' => $r['severity'],
				'openedAt' => $r['created_at'],
				'updatedAt' => $r['updated_at'],
			];
		}, $rows);
	}

	/** Grant/revoke — planner/admin on the site. */
	public function grant(string $clientUid, int $siteId, string $actorUid): void
	{
		$this->acl->assertCanManageSite($siteId);
		$this->acl->assertValidUid($clientUid);
		if (!$this->isClient($clientUid)) {
			throw new \OCA\TourCheck\Exception\ValidationException(Constants::ERR_VALIDATION, 'User is not in the client group.', ['client_uid' => 'invalid']);
		}
		$existing = $this->grants->find($clientUid, $siteId);
		$now = $this->timeFactory->getDateTime();
		if ($existing !== null) {
			if ($existing->getRevokedAt() === null) {
				return; // idempotent
			}
			$existing->setRevokedAt(null);
			$this->grants->update($existing);
			return;
		}
		$g = new \OCA\TourCheck\Db\ClientGrant();
		$g->setClientUid($clientUid);
		$g->setSiteId($siteId);
		$g->setCreatedBy($actorUid);
		$g->setCreatedAt($now);
		$this->grants->insert($g);
	}

	public function revoke(string $clientUid, int $siteId, string $actorUid): void
	{
		$this->acl->assertCanManageSite($siteId);
		$g = $this->grants->find($clientUid, $siteId);
		if ($g === null || $g->getRevokedAt() !== null) {
			return; // idempotent — revoked looks like absent
		}
		$g->setRevokedAt($this->timeFactory->getDateTime());
		$this->grants->update($g);
	}

	/** @return list<ClientGrant> admin view of a site's grants */
	public function grantsForSite(int $siteId): array
	{
		$this->acl->assertCanManageSite($siteId);
		return $this->grants->findForSite($siteId);
	}
}
