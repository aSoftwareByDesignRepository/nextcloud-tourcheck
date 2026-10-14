<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TourCheck\Tests\Unit\Flow;

use OCA\TourCheck\Flow\TourCheckEntity;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\FlowEmitter;
use OCP\EventDispatcher\GenericEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IRuleMatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TourCheckEntityTest extends TestCase
{
	private AccessControlService&MockObject $acl;
	private TourCheckEntity $entity;

	protected function setUp(): void
	{
		$this->acl = $this->createMock(AccessControlService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturn('/img/app.svg');
		$this->entity = new TourCheckEntity($l10n, $url, $this->acl);
	}

	public function testDeclaresAllDomainEvents(): void
	{
		$names = array_map(static fn ($e) => $e->getEventName(), $this->entity->getEvents());
		self::assertSame([
			FlowEmitter::EVENT_ROUND_MISSED,
			FlowEmitter::EVENT_ROUND_COMPLETED,
			FlowEmitter::EVENT_ROUND_SKIPPED,
			FlowEmitter::EVENT_INCIDENT_CREATED,
		], $names);
	}

	public function testPrepareRuleMatcherRejectsForeignSubject(): void
	{
		$matcher = $this->createMock(IRuleMatcher::class);
		$matcher->expects(self::never())->method('setEntitySubject');
		$this->entity->prepareRuleMatcher($matcher, FlowEmitter::EVENT_ROUND_MISSED, new GenericEvent('not-an-array'));
		// Non-site-scoped subjects must not legitimize anyone either.
		self::assertFalse($this->entity->isLegitimatedForUserId('alice'));
	}

	public function testLegitimationIsSiteScopedAndFailClosed(): void
	{
		$matcher = $this->createMock(IRuleMatcher::class);
		$matcher->expects(self::once())->method('setEntitySubject')
			->with(self::isInstanceOf(TourCheckEntity::class), ['siteId' => 7, 'roundId' => 3]);
		$this->entity->prepareRuleMatcher($matcher, FlowEmitter::EVENT_ROUND_MISSED, new GenericEvent(['siteId' => 7, 'roundId' => 3]));

		$this->acl->expects(self::exactly(2))->method('siteRole')
			->willReturnMap([
				['member', 7, 'supervisor'],
				['outsider', 7, null],
			]);
		self::assertTrue($this->entity->isLegitimatedForUserId('member'));
		self::assertFalse($this->entity->isLegitimatedForUserId('outsider'));
	}
}
