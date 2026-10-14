<?php

declare(strict_types=1);

namespace OCA\TourCheck\Controller;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\LivePageService;
use OCA\TourCheck\Service\RateLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Util;
use OCP\IRequest;

/**
 * Live object page — the portal-free customer view. Public (token-gated),
 * read-only, no-store + noindex, IP-throttled. Contains ZERO mutating links.
 */
class PublicPageController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly LivePageService $live,
		private readonly RateLimitService $rateLimit,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function live(string $token): TemplateResponse
	{
		try {
			$this->rateLimit->hitLivePage((string)$this->request->getRemoteAddress());
			$data = $this->live->resolve($token);
		} catch (\Throwable) {
			// Uniform 404 — revoked/expired/unknown/throttled all look alike.
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		Util::addTranslations(Constants::APP_ID);
		$response = new TemplateResponse(Constants::APP_ID, 'live', [
			'live' => $data['payload'],
		], 'guest');
		$response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
		$response->addHeader('X-Robots-Tag', 'noindex, nofollow');
		$response->addHeader('X-Frame-Options', 'DENY');
		return $response;
	}
}
