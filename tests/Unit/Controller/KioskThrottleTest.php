<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Controller;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Controller\KioskController;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Service\EventService;
use OCA\TourCheck\Service\LicenseService;
use OCA\TourCheck\Service\PhotoService;
use OCA\TourCheck\Service\RateLimitService;
use OCA\TourCheck\Service\RoundService;
use OCA\TourCheck\Service\StationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Test\TestCase;

/**
 * Kiosk is a token-bearing #[PublicPage]: probing the station token must be
 * rate-limited, and a throttle hit must be indistinguishable from an invalid
 * token on the wire (uniform 403 — never a different status that discloses
 * the throttle engaged).
 */
final class KioskThrottleTest extends TestCase
{
	private function controller(RateLimitService $rl, StationService $stations, LicenseService $license): KioskController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('10.0.0.7');
		return new KioskController(
			'tourcheck', $request, $stations,
			$this->createMock(EventService::class),
			$license, $rl,
			$this->createMock(RoundService::class),
			$this->createMock(PhotoService::class),
		);
	}

	public function testThrottledHomeReturnsUniform403(): void
	{
		$rl = $this->createMock(RateLimitService::class);
		$rl->expects($this->once())->method('hitStationAuth')->with('10.0.0.7')
			->willThrowException(new DomainException(Constants::ERR_RATE_LIMITED, 'Too many requests.', 429));
		$stations = $this->createMock(StationService::class);
		$stations->expects($this->never())->method('authenticate'); // throttle precedes auth
		$res = $this->controller($rl, $stations, $this->createMock(LicenseService::class))->home();
		$this->assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
	}

	public function testInvalidStationTokenIsSame403(): void
	{
		$rl = $this->createMock(RateLimitService::class);
		$rl->expects($this->once())->method('hitStationAuth');
		$stations = $this->createMock(StationService::class);
		$stations->method('authenticate')->willThrowException(new NotFoundException());
		$res = $this->controller($rl, $stations, $this->createMock(LicenseService::class))->home();
		$this->assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
	}

	public function testLicenseFailureStillRendersLicenseTemplate(): void
	{
		$rl = $this->createMock(RateLimitService::class);
		$stations = $this->createMock(StationService::class);
		$station = $this->createMock(\OCA\TourCheck\Db\Station::class);
		$stations->method('authenticate')->willReturn($station);
		$license = $this->createMock(LicenseService::class);
		$license->method('requireStationPlan')
			->willThrowException(new DomainException(Constants::ERR_LICENSE_INVALID, 'nope', 402));
		$res = $this->controller($rl, $stations, $license)->home();
		$this->assertSame('kiosk-license', $res->getTemplateName());
	}
}
