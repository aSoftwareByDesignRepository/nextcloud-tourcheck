<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Integration;

use OCA\TourCheck\Flow\TourCheckEntity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\WorkflowEngine\Events\RegisterEntitiesEvent;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IManager;
use Test\TestCase;

/**
 * NH-04: proves the app wires TourCheckEntity into the workflow engine's
 * RegisterEntitiesEvent — the real event dispatcher, not a hand-invoked
 * listener. Without this wiring the entity silently never appears in the
 * Flow admin UI.
 */
final class FlowEntityRegistrationIntegrationTest extends TestCase
{
	public function testEntityRegistersThroughTheRealDispatcher(): void
	{
		if (!\class_exists(\OC::class) || !isset(\OC::$server)) {
			self::markTestSkipped('Nextcloud not bootstrapped');
		}
		$captured = new class implements IManager {
			public array $entities = [];
			public function registerEntity(IEntity $entity): void { $this->entities[] = $entity; }
			public function registerOperation(\OCP\WorkflowEngine\IOperation $operation): void {}
			public function registerCheck(\OCP\WorkflowEngine\ICheck $check): void {}
			public function getRuleMatcher(): \OCP\WorkflowEngine\IRuleMatcher { throw new \LogicException('unused'); }
		};
		\OC::$server->get(IEventDispatcher::class)->dispatchTyped(new RegisterEntitiesEvent($captured));
		$classes = array_map(static fn ($e) => get_class($e), $captured->entities);
		self::assertContains(TourCheckEntity::class, $classes, 'tourcheck must register its Flow entity on RegisterEntitiesEvent');
	}
}
