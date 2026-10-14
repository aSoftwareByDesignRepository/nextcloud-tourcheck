<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Support;

use OCA\TourCheck\Support\GeoMath;
use Test\TestCase;

/**
 * Geofence math is the correctness core of F1 — a wrong sign or wrap
 * silently flips enter/exit alerts. Tests pin the antimeridian, the
 * boundary-counts-inside rule, and the accuracy-slack rule.
 */
final class GeoMathTest extends TestCase
{
	public function testDistanceKnownPair(): void
	{
		// Berlin → Paris ≈ 878 km; allow 1 % tolerance.
		$d = GeoMath::distance(52.5200, 13.4050, 48.8566, 2.3522);
		$this->assertGreaterThan(870_000, $d);
		$this->assertLessThan(887_000, $d);
		$this->assertSame(0.0, GeoMath::distance(52.5, 13.4, 52.5, 13.4));
	}

	public function testNormalizeLngWrapsIntoRange(): void
	{
		$this->assertSame(170.0, GeoMath::normalizeLng(170.0));
		$this->assertEqualsWithDelta(-170.0, GeoMath::normalizeLng(190.0), 1e-9);
		$this->assertEqualsWithDelta(179.0, GeoMath::normalizeLng(-181.0), 1e-9);
		$this->assertEqualsWithDelta(0.0, GeoMath::normalizeLng(360.0), 1e-9);
		$this->assertEqualsWithDelta(-180.0, GeoMath::normalizeLng(540.0), 1e-9);
	}

	public function testLngDeltaTakesShortestPathAcrossAntimeridian(): void
	{
		// 179° → -179° is +2°, not -358°.
		$this->assertEqualsWithDelta(2.0, GeoMath::lngDelta(179.0, -179.0), 1e-9);
		$this->assertEqualsWithDelta(-2.0, GeoMath::lngDelta(-179.0, 179.0), 1e-9);
		$this->assertEqualsWithDelta(10.0, GeoMath::lngDelta(10.0, 20.0), 1e-9);
	}

	public function testPointInPolygonBasicInsideOutside(): void
	{
		$sq = [[0.0, 0.0], [0.0, 10.0], [10.0, 10.0], [10.0, 0.0]];
		$this->assertTrue(GeoMath::pointInPolygon(5.0, 5.0, $sq));
		$this->assertFalse(GeoMath::pointInPolygon(15.0, 5.0, $sq));
		$this->assertFalse(GeoMath::pointInPolygon(5.0, -5.0, $sq));
	}

	public function testPointInPolygonBoundaryCountsInside(): void
	{
		$sq = [[0.0, 0.0], [0.0, 10.0], [10.0, 10.0], [10.0, 0.0]];
		$this->assertTrue(GeoMath::pointInPolygon(5.0, 0.0, $sq));   // on edge
		$this->assertTrue(GeoMath::pointInPolygon(0.0, 0.0, $sq));   // vertex
	}

	public function testPointInPolygonRejectsDegenerateInput(): void
	{
		$this->assertFalse(GeoMath::pointInPolygon(5.0, 5.0, []));
		$this->assertFalse(GeoMath::pointInPolygon(5.0, 5.0, [[0.0, 0.0], [1.0, 1.0]]));
	}

	public function testPointInPolygonAntimeridianSafe(): void
	{
		// Square straddling ±180 (Fiji-scale).
		$sq = [[-10.0, 170.0], [-10.0, -170.0], [10.0, -170.0], [10.0, 170.0]];
		$this->assertTrue(GeoMath::pointInPolygon(0.0, 179.0, $sq));
		$this->assertTrue(GeoMath::pointInPolygon(0.0, -179.0, $sq));
		$this->assertFalse(GeoMath::pointInPolygon(0.0, 0.0, $sq));
		$this->assertFalse(GeoMath::pointInPolygon(0.0, 160.0, $sq));
	}

	public function testInCircleHonorsAccuracySlack(): void
	{
		// Point 90 m from a 100 m fence: provably inside only when the whole
		// error circle fits — acc 5 m ok (95 ≤ 100·? → dist 90 ≤ 95), acc 20 m not.
		$latC = 52.5;
		$lonC = 13.4;
		// ~90 m north of center.
		$lat = $latC + 90.0 / 111_320.0;
		$this->assertTrue(GeoMath::inCircle($lat, $lonC, $latC, $lonC, 100, 5));
		$this->assertFalse(GeoMath::inCircle($lat, $lonC, $latC, $lonC, 100, 20));
		$this->assertFalse(GeoMath::inCircle($latC + 0.01, $lonC, $latC, $lonC, 100, 0));
	}
}
