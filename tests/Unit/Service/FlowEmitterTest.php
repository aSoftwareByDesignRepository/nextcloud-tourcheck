<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Service\FlowEmitter;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FlowEmitterTest extends TestCase
{
	public function testDispatchesNamedGenericEvent(): void
	{
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::once())->method('dispatch')
			->with(FlowEmitter::EVENT_ROUND_MISSED, self::callback(
				static fn ($e) => $e instanceof GenericEvent && $e->getSubject() === ['siteId' => 4, 'roundId' => 9]
			));
		(new FlowEmitter($dispatcher, $this->createMock(LoggerInterface::class)))
			->emit(FlowEmitter::EVENT_ROUND_MISSED, ['siteId' => 4, 'roundId' => 9]);
	}

	public function testBrokenFlowListenerNeverBreaksTheDomainPath(): void
	{
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatch')->willThrowException(new \RuntimeException('flow op exploded'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');
		// Must not throw — evidence recording outranks automation.
		(new FlowEmitter($dispatcher, $logger))->emit(FlowEmitter::EVENT_INCIDENT_CREATED, ['siteId' => 1, 'incidentId' => 2]);
	}
}
