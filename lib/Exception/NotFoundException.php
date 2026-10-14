<?php

declare(strict_types=1);

namespace OCA\TourCheck\Exception;

/**
 * Canonical uniform-404: "not found" and "not yours" are identical.
 * Wire payload: {"success":false,"error":"NOT_FOUND","message":"Not found.","fields":{}}
 */
class NotFoundException extends \RuntimeException
{
	public function __construct(string $message = 'Not found.')
	{
		parent::__construct($message, 404);
	}
}
