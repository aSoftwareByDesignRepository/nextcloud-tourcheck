<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Db;

use OCA\TourCheck\Db\CheckpointToken;
use OCA\TourCheck\Db\LiveLink;
use OCA\TourCheck\Db\Station;
use OCA\TourCheck\Db\StationMember;
use Test\TestCase;

/**
 * Wire-contract guard: entities carrying secret material must never
 * serialize it. A serialized hash of a low-entropy secret (6-digit PIN,
 * pairing code) is offline-brute-forceable; a serialized token hash is a
 * verification oracle. This test FAILS if any forbidden key ever returns.
 */
final class WireSerializationTest extends TestCase
{
	private const FORBIDDEN = ['pinHash', 'secretHash', 'pairingCodeHash', 'tokenHash', 'pin', 'secret'];

	public static function entityProvider(): array
	{
		$station = new Station();
		$station->setSecretHash('$argon2id$deadbeef');
		$station->setPairingCodeHash(hash('sha256', '123456'));
		$station->setLabel('kiosk');

		$member = new StationMember();
		$member->setPinHash('$argon2id$deadbeef');
		$member->setDisplayName('Alice');

		$link = new LiveLink();
		$link->setTokenHash(hash('sha256', 'live-token'));

		$token = new CheckpointToken();
		$token->setTokenHash(hash('sha256', 'cp-token'));

		return [
			'station' => [$station],
			'stationMember' => [$member],
			'liveLink' => [$link],
			'checkpointToken' => [$token],
		];
	}

	/**
	 * @dataProvider entityProvider
	 */
	public function testNoSecretMaterialOnWire(object $entity): void
	{
		$wire = $entity->jsonSerialize();
		foreach (self::FORBIDDEN as $key) {
			$this->assertArrayNotHasKey($key, $wire, get_class($entity) . ' leaks ' . $key);
		}
		$this->assertNotEmpty($wire, get_class($entity) . ' serializes to nothing');
	}
}
