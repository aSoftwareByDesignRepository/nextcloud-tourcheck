<?php

declare(strict_types=1);

namespace OCA\TourCheck\Controller;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\ConflictException;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Canonical wire shape (api-security-patterns):
 *   success: {"success":true,"data":...}
 *   failure: {"success":false,"error":CODE,"message":...,"fields":{...}}
 * Uniform 404 for "missing" AND "not yours" — no existence oracles.
 */
trait ApiJsonTrait
{
	protected function ok(mixed $data = null, int $status = Http::STATUS_OK): JSONResponse
	{
		return new JSONResponse(['success' => true, 'data' => $data], $status, [], JSON_INVALID_UTF8_SUBSTITUTE);
	}

	protected function fail(string $error, string $message, array $fields = [], int $status = Http::STATUS_BAD_REQUEST, array $headers = []): JSONResponse
	{
		$res = new JSONResponse([
			'success' => false,
			'error' => $error,
			'message' => $message,
			'fields' => $fields === [] ? new \stdClass() : $fields,
		], $status, [], JSON_INVALID_UTF8_SUBSTITUTE);
		foreach ($headers as $k => $v) {
			$res->addHeader($k, $v);
		}
		return $res;
	}

	protected function notFound(): JSONResponse
	{
		return $this->fail(Constants::ERR_NOT_FOUND, 'Not found.', [], Http::STATUS_NOT_FOUND);
	}

	protected function fromThrowable(\Throwable $e): JSONResponse
	{
		if ($e instanceof NotFoundException) {
			return $this->notFound();
		}
		if ($e instanceof \OCP\AppFramework\Db\DoesNotExistException) {
			return $this->notFound();
		}
		if ($e instanceof ValidationException) {
			if ($e->errorCode === Constants::ERR_NOT_FOUND) {
				return $this->notFound();
			}
			return $this->fail($e->errorCode, $e->getMessage(), $e->fields, $e->httpStatus);
		}
		if ($e instanceof ConflictException) {
			return $this->fail($e->getErrorCode(), $e->getMessage(), [], Http::STATUS_CONFLICT);
		}
		/* LockGuard exhausts its retry budget → LockedException. A contended
		   lock is retryable — 409 + Retry-After, never an opaque 500 (the
		   client may resubmit immediately, same request). */
		if ($e instanceof \OCP\Lock\LockedException) {
			return $this->fail(Constants::ERR_BUSY, 'Busy — retry shortly.', [], Http::STATUS_CONFLICT, ['Retry-After' => '1']);
		}
		if ($e instanceof DomainException) {
			// Uniform 404: an ID-scoped access-denied must be indistinguishable
			// from not-found on the wire (existence oracle).
			if ($e->errorCode === Constants::ERR_ACCESS_DENIED) {
				return $this->notFound();
			}
			$headers = [];
			if ($e->retryAfterSeconds !== null) {
				$headers['Retry-After'] = (string)$e->retryAfterSeconds;
			}
			return $this->fail($e->errorCode, $e->getMessage(), [], $e->httpStatus, $headers);
		}
		// Never leak internals; always log for ops.
		try {
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->error(
				'TourCheck API unexpected error: ' . $e->getMessage(),
				['app' => 'tourcheck', 'exception' => $e],
			);
		} catch (\Throwable) {
		}
		return $this->fail('SERVER_ERROR', 'Unexpected error', [], Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	/** Strict bool parse — the literal string "false" must never be truthy. */
	protected function bodyBool(array $body, string $key, bool $default = false): bool
	{
		$v = $body[$key] ?? null;
		if ($v === null) {
			return $default;
		}
		if (is_bool($v)) {
			return $v;
		}
		return in_array($v, [1, '1', 'true'], true);
	}

	/** Strict int id parse — "2.9", "2e3", " 5" are all rejected. */
	protected function strictId(mixed $raw, string $field): int
	{
		if (is_int($raw)) {
			return $raw;
		}
		if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
			return (int)$raw;
		}
		throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid id.', [$field => 'invalid']);
	}

	/** CAS precondition from body.revision or If-Match-Revision header. */
	protected function revisionPrecondition(array $body): ?int
	{
		$header = trim((string)$this->request->getHeader('If-Match-Revision'));
		if ($header !== '') {
			return preg_match('/^\d+$/', $header) === 1 ? (int)$header : null;
		}
		if (isset($body['revision'])) {
			// Strict int — "2.9" must not silently become 2 (fail-closed CAS).
			if (is_int($body['revision'])) {
				return $body['revision'];
			}
			if (is_string($body['revision']) && preg_match('/^\d+$/', $body['revision']) === 1) {
				return (int)$body['revision'];
			}
			return null;
		}
		return null;
	}
}
