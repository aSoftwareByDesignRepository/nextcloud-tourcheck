<?php

declare(strict_types=1);

namespace OCA\TourCheck\Listener;

use OCA\TourCheck\Flow\TourCheckEntity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\WorkflowEngine\Events\RegisterEntitiesEvent;

/**
 * NH-04: hands the TourCheck entity to the workflow engine's registry so
 * its events appear in the Flow admin UI.
 *
 * @template-implements IEventListener<RegisterEntitiesEvent>
 */
class RegisterFlowEntityListener implements IEventListener
{
	public function __construct(private readonly TourCheckEntity $entity)
	{
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof RegisterEntitiesEvent) {
			return;
		}
		$event->registerEntity($this->entity);
	}
}
