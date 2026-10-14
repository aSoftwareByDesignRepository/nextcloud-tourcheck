<?php

declare(strict_types=1);

namespace OCA\TourCheck\Exception;

/** 409 — CAS revision mismatch / stale snapshot. Never merges. */
class ConflictException extends \RuntimeException
{
	public function __construct(private readonly string $errorCode, string $message = '')
	{
		parent::__construct($message !== '' ? $message : $errorCode, 409);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
