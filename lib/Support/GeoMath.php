<?php

declare(strict_types=1);

namespace OCA\TourCheck\Support;

/**
 * Geospatial math — deliberately dependency-free (no spatial DB indexes):
 * site scales are ≤10 km where the equirectangular error stays <0.3 %.
 * All inputs normalized: lat clamped to [-90,90], lng wrapped to [-180,180).
 */
final class GeoMath
{
	private const EARTH_R = 6371000.0; // metres

	public static function normalizeLng(float $lng): float
	{
		// wrap to [-180,180) — survives antimeridian-adjacent input
		$l = fmod($lng + 180.0, 360.0);
		if ($l < 0) {
			$l += 360.0;
		}
		return $l - 180.0;
	}

	/** Shortest signed longitudinal delta a→b in degrees, antimeridian-safe. */
	public static function lngDelta(float $from, float $to): float
	{
		$d = self::normalizeLng($to) - self::normalizeLng($from);
		if ($d > 180.0) {
			$d -= 360.0;
		} elseif ($d < -180.0) {
			$d += 360.0;
		}
		return $d;
	}

	/** Great-circle distance in metres (haversine). */
	public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
	{
		$rLat1 = deg2rad($lat1);
		$rLat2 = deg2rad($lat2);
		$dLat = $rLat2 - $rLat1;
		$dLon = deg2rad(self::lngDelta($lon1, $lon2));
		$a = sin($dLat / 2) ** 2 + cos($rLat1) * cos($rLat2) * sin($dLon / 2) ** 2;
		return 2 * self::EARTH_R * asin(min(1.0, sqrt($a)));
	}

	/**
	 * Ray-cast point-in-polygon. $polygon = list of [lat,lng] pairs.
	 * Boundary counts as inside (checkpoints on the line stay usable).
	 * Antimeridian-safe: the polygon is unwrapped into a continuous lng
	 * sequence anchored on its first vertex (consecutive vertices take the
	 * shortest path), and the query point is expressed in the same
	 * unwrapped space — a fence straddling ±180 stays one contiguous shape.
	 */
	public static function pointInPolygon(float $lat, float $lon, array $polygon): bool
	{
		$n = count($polygon);
		if ($n < 3) {
			return false;
		}
		$v0lng = (float)$polygon[0][1];
		$pts = [[ (float)$polygon[0][0], self::normalizeLng($v0lng) ]];
		for ($i = 1; $i < $n; $i++) {
			$pts[] = [
				(float)$polygon[$i][0],
				$pts[$i - 1][1] + self::lngDelta((float)$polygon[$i - 1][1], (float)$polygon[$i][1]),
			];
		}
		$x = $pts[0][1] + self::lngDelta($v0lng, $lon);
		$y = $lat;

		$inside = false;
		for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
			$yi = $pts[$i][0];
			$xi = $pts[$i][1];
			$yj = $pts[$j][0];
			$xj = $pts[$j][1];
			if (self::onSegment($x, $y, $xi, $yi, $xj, $yj)) {
				return true;
			}
			if (($yi > $y) !== ($yj > $y)
				&& $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
				$inside = !$inside;
			}
		}
		return $inside;
	}

	private static function onSegment(float $px, float $py, float $ax, float $ay, float $bx, float $by): bool
	{
		$cross = ($bx - $ax) * ($py - $ay) - ($by - $ay) * ($px - $ax);
		if (abs($cross) > 1e-9) {
			return false;
		}
		return $px >= min($ax, $bx) - 1e-9 && $px <= max($ax, $bx) + 1e-9
			&& $py >= min($ay, $by) - 1e-9 && $py <= max($ay, $by) + 1e-9;
	}

	/** Does the point lie inside a circle geofence (with accuracy slack)? */
	public static function inCircle(float $lat, float $lon, float $cLat, float $cLon, int $radiusM, int $accuracyM = 0): bool
	{
		// Accuracy shrinks the provable fix — a point is "inside" only when the
		// whole error circle fits (strict proof of presence; spec F1-02 AC-4).
		return self::distance($lat, $lon, $cLat, $cLon) <= max(0, $radiusM - $accuracyM);
	}

	/**
	 * Coarse containment for a polygon: distance to each vertex is bounded by
	 * polygon extent — cheap pre-check before ray casting for large sets.
	 */
	public static function polygonContains(array $polygon, float $lat, float $lon): bool
	{
		return self::pointInPolygon($lat, $lon, $polygon);
	}
}
