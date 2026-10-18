<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Integration\ArbeitszeitStampPushService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F16 consumer side: first-scan stamp proposal is fail-closed — no actor,
 * no round, AZC disabled/absent, or any facade error yields false, never
 * an exception into the evidence path. The accepted path is covered by
 * AZC's facade tests + the Docker roundtrip (ingest → pending draft).
 */
class ArbeitszeitStampPushTest extends TestCase
{
	private function svc(bool $azcEnabled): ArbeitszeitStampPushService
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($azcEnabled);
		return new ArbeitszeitStampPushService($apps, $this->createMock(LoggerInterface::class));
	}

	private function event(?string $actorUid, ?int $roundId): CheckEvent
	{
		$e = new CheckEvent();
		$e->setActorUid($actorUid);
		$e->setRoundId($roundId);
		$e->setServerReceivedAt(new \DateTime());
		return $e;
	}

	public function testMissingActorNeverProbes(): void
	{
		// Station-member scans carry no uid — no personal data crosses the seam.
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->never())->method('isEnabledForUser');
		$svc = new ArbeitszeitStampPushService($apps, $this->createMock(LoggerInterface::class));
		$this->assertFalse($svc->proposeFirstScan($this->event(null, 7)));
	}

	public function testOrphanEventNeverProbes(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->never())->method('isEnabledForUser');
		$svc = new ArbeitszeitStampPushService($apps, $this->createMock(LoggerInterface::class));
		$this->assertFalse($svc->proposeFirstScan($this->event('worker', null)));
	}

	public function testAzcDisabledYieldsFalse(): void
	{
		$this->assertFalse($this->svc(false)->proposeFirstScan($this->event('worker', 7)));
		$this->assertFalse($this->svc(false)->isAvailable());
	}

	public function testFacadeProbeIsBoolean(): void
	{
		$svc = $this->svc(true);
		$this->assertSame(
			class_exists(\OCA\ArbeitszeitCheck\Public\ExternalStampDraftFacade::class),
			$svc->isAvailable()
		);
	}
}
