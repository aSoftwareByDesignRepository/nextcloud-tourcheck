<?php

declare(strict_types=1);

namespace OCA\TourCheck\Controller;

use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\ClientPortalService;
use OCA\TourCheck\Service\KpiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Client portal — /portal/* route space, SEPARATE from the staff app.
 *
 * Q1 decision: clients are real Nextcloud accounts in `tourcheck_client`
 * with per-site grants. The door is uniform-404 in BOTH directions:
 * a non-client hitting /portal sees "not found", and staff URLs under
 * /portal don't exist either — no probing oracle.
 *
 * Everything rendered here is site-level aggregate (NN-03): no worker uid,
 * no positions, no trails, no checkpoint-level actor attribution.
 */
class PortalController extends Controller
{
	use ApiJsonTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ClientPortalService $portal,
		private readonly KpiService $kpis,
	) {
		parent::__construct($appName, $request);
	}

	/** Portal shell — non-clients get the uniform-404 page. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		try {
			$this->portal->assertClient();
		} catch (NotFoundException) {
			return $this->notFoundPage();
		}
		return new TemplateResponse('tourcheck', 'portal', [], 'user');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function site(int $siteId): TemplateResponse
	{
		try {
			$uid = $this->portal->assertClient();
			$this->portal->assertSiteScope($uid, $siteId);
		} catch (NotFoundException) {
			return $this->notFoundPage();
		}
		return new TemplateResponse('tourcheck', 'portal', ['siteId' => $siteId], 'user');
	}

	/**
	 * The 404 must carry HTTP 404 — a 200-status 404-body is a soft-404
	 * that still discloses the route exists (status-code oracle).
	 */
	private function notFoundPage(): TemplateResponse
	{
		$r = new TemplateResponse('core', '404', [], 'guest');
		$r->setStatus(Http::STATUS_NOT_FOUND);
		return $r;
	}

	// ── Portal JSON (same wire contract; the client gate runs first) ────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function sites(): JSONResponse
	{
		try {
			$uid = $this->portal->assertClient();
			$ids = $this->portal->sitesFor($uid);
			$out = [];
			foreach ($ids as $sid) {
				try {
					$out[] = $this->portal->overview($uid, $sid)['site'];
				} catch (NotFoundException) {
					// Stale grant to an archived/deleted site must not kill
					// the whole portal index — skip it, keep live grants.
					continue;
				}
			}
			return $this->ok(['sites' => $out]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function overview(int $siteId): JSONResponse
	{
		try {
			$uid = $this->portal->assertClient();
			return $this->ok($this->portal->overview($uid, $siteId));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function incidents(int $siteId): JSONResponse
	{
		try {
			$uid = $this->portal->assertClient();
			return $this->ok(['incidents' => $this->portal->incidents($uid, $siteId)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reports(int $siteId): JSONResponse
	{
		try {
			$uid = $this->portal->assertClient();
			return $this->ok(['reports' => $this->portal->reports($uid, $siteId)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Portal PDF download — the delivery must be client_visible AND
	 * client-audience (redacted) AND the client must hold a grant for the
	 * delivery's site. Everything else is the uniform 404.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadReport(int $id): JSONResponse|DataDownloadResponse
	{
		try {
			$uid = $this->portal->assertClient();
			$dlv = $this->portal->reportForDownload($uid, $id);
			$file = $this->portal->reportFile($dlv);
			if ($file === null) {
				throw new NotFoundException();
			}
			return new DataDownloadResponse($file->getContent(), 'tourcheck-report-' . $dlv->getPeriod() . '.pdf', 'application/pdf');
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kpis(int $siteId): JSONResponse
	{
		try {
			$uid = $this->portal->assertClient();
			$this->portal->assertSiteScope($uid, $siteId);
			// KPIs are site-aggregate — safe for clients. Bypass the staff ACL
			// by computing them directly (KpiService gates on view-site scope
			// which a client lacks by design).
			$from = \DateTime::createFromFormat('Y-m-d', (string)$this->request->getParam('from', '')) ?: new \DateTime('-30 days');
			$to = \DateTime::createFromFormat('Y-m-d', (string)$this->request->getParam('to', '')) ?: new \DateTime('tomorrow');
			return $this->ok($this->kpis->forSiteBypassingStaffAcl($siteId, $from, $to));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
