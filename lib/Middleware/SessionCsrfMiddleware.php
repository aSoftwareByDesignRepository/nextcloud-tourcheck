<?php

declare(strict_types=1);

namespace OCA\TourCheck\Middleware;

use OCA\TourCheck\Exception\SessionCsrfException;
use OCA\TourCheck\Service\CsrfTokenValidator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Enforces the requesttoken on every session-authenticated mutation, even when
 * the request carries `OCS-APIRequest`.
 *
 * Nextcloud's own CSRF middleware skips its check whenever that header is set
 * (Request::passesCSRFCheck short-circuits) — the intended exemption is for
 * Basic-auth API clients. A header is client-controlled, so session-cookie
 * requests that opt into it would silently lose both CSRF and strict-cookie
 * protection. This guard re-asserts the invariant server-side: ambient
 * (cookie-session) mutations must always prove a valid token.
 *
 * Exempt: requests with an `Authorization: Basic …` header — the companion app
 * authenticates via app-password, which carries no ambient authority.
 */
class SessionCsrfMiddleware extends Middleware
{
	private const MUTATION_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

	public function __construct(
		private IRequest $request,
		private IUserSession $userSession,
		private CsrfTokenValidator $csrfTokenValidator,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\TourCheck\\Controller\\')) {
			return;
		}
		if (!in_array($this->request->getMethod(), self::MUTATION_METHODS, true)) {
			return;
		}
		// Basic app-password auth is non-ambient — nothing a forged request could ride.
		$auth = strtolower((string) $this->request->getHeader('Authorization'));
		if (str_starts_with($auth, 'basic ')) {
			return;
		}
		// No logged-in session → nothing ambient to protect; auth middleware rejects anyway.
		if ($this->userSession->getUser() === null) {
			return;
		}
		if (!$this->hasValidToken()) {
			throw new SessionCsrfException();
		}
	}

	private function hasValidToken(): bool
	{
		$token = $this->request->getParam('requesttoken');
		if (!is_string($token) || $token === '') {
			$header = (string) $this->request->getHeader('requesttoken');
			$token = $header !== '' ? $header : null;
		}
		if ($token === null) {
			return false;
		}
		return $this->csrfTokenValidator->isValid($token);
	}

	public function afterException($controller, $methodName, \Exception $exception): JSONResponse
	{
		if (!$exception instanceof SessionCsrfException) {
			throw $exception;
		}
		return new JSONResponse(
			[
				'success' => false,
				'error' => 'CSRF_FAILED',
				'message' => 'Session check failed — reload the page and retry.',
				'fields' => new \stdClass(),
			],
			Http::STATUS_PRECONDITION_FAILED,
		);
	}
}
