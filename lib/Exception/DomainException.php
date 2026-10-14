<?php

declare(strict_types=1);

namespace OCA\TourCheck\Exception;

class DomainException extends \RuntimeException
{
	public function __construct(
		public readonly string $errorCode,
		string $message = '',
		public readonly int $httpStatus = 400,
		public readonly ?int $retryAfterSeconds = null,
	) {
		parent::__construct($message !== '' ? $message : $errorCode, $httpStatus);
	}
}
