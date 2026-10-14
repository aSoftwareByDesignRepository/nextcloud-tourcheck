<?php

declare(strict_types=1);

namespace OCA\TourCheck\Exception;

/**
 * Carries a `fields` map keyed to control names — the wire payload renders
 * field-level errors (aria-invalid + inline .tck-field-error), never toast-only.
 */
class ValidationException extends \RuntimeException
{
	/** @param array<string,string> $fields */
	public function __construct(
		public readonly string $errorCode,
		string $message = '',
		public readonly array $fields = [],
		public readonly int $httpStatus = 422,
	) {
		parent::__construct($message !== '' ? $message : $errorCode, $httpStatus);
	}
}
