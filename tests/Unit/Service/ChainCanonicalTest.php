<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Service\ChainService;
use Test\TestCase;

/**
 * Golden vectors for the journal chain canonical form. If any of these
 * constants change, the on-disk chain format changed — every previously
 * anchored site would fail verification. Treat failures here as
 * wire-format regressions, not test bugs.
 */
final class ChainCanonicalTest extends TestCase
{
	private function goldenEntry(): array
	{
		return [
			'seq' => 1,
			'siteId' => 1,
			'roundId' => 0,
			'kind' => 'check',
			'actorUid' => 'worker1',
			'memberId' => 0,
			'body' => 'scan',
			'payloadRef' => 'event:42',
			'payloadHash' => ChainService::payloadHash(['id' => 42, 'method' => 'scan_qr', 'site_id' => 1]),
			'serverTs' => '2026-10-12T16:08:57Z',
		];
	}

	public function testI64beIsBigEndian(): void
	{
		self::assertSame('0000000000000001', bin2hex(ChainService::i64be(1)));
		self::assertSame('0000000100000000', bin2hex(ChainService::i64be(0x100000000)));
		self::assertSame('ffffffffffffffff', bin2hex(ChainService::i64be(-1)));
	}

	public function testLpIsLengthPrefixedUtf8(): void
	{
		self::assertSame('00000003616263', bin2hex(ChainService::lp('abc')));
		self::assertSame('00000000', bin2hex(ChainService::lp('')));
		// multibyte: byte length, not char length
		self::assertSame('00000004' . bin2hex('öä'), bin2hex(ChainService::lp('öä')));
	}

	public function testTsNormalizesToUtc(): void
	{
		$berlin = new \DateTimeImmutable('2026-10-12 18:08:57', new \DateTimeZone('Europe/Berlin'));
		self::assertSame('2026-10-12T16:08:57Z', ChainService::ts($berlin));
		// winter time (CET = UTC+1) — DST correctness
		$winter = new \DateTimeImmutable('2026-01-12 18:08:57', new \DateTimeZone('Europe/Berlin'));
		self::assertSame('2026-01-12T17:08:57Z', ChainService::ts($winter));
	}

	public function testCanonicalEntryGolden(): void
	{
		self::assertSame(
			'00000000000000010000000000000001000000000000000000000005636865636b00000007776f726b6572310000000000000000000000047363616e000000086576656e743a3432000000403536323865643034346563363262346634623836626130366539626562343537393136336433353664353637306636333539333761663262666465363438363500000014323032362d31302d31325431363a30383a35375a',
			bin2hex(ChainService::canonicalEntry($this->goldenEntry()))
		);
	}

	public function testPayloadHashGolden(): void
	{
		self::assertSame(
			'5628ed044ec62b4f4b86ba06e9beb4579163d356d5670f635937af2bfde64865',
			ChainService::payloadHash(['id' => 42, 'method' => 'scan_qr', 'site_id' => 1])
		);
	}

	public function testEntryHashGolden(): void
	{
		$genesis = str_repeat('0', 64);
		self::assertSame(
			'd6794c684a66f0f01276b352a02063d26fabf04268c99182027a4aefebd8b9db',
			ChainService::entryHashFor($genesis, ChainService::canonicalEntry($this->goldenEntry()))
		);
	}

	public function testTamperSensitivity(): void
	{
		$base = $this->goldenEntry();
		$hash0 = ChainService::entryHashFor(str_repeat('0', 64), ChainService::canonicalEntry($base));

		$mutated = $base;
		$mutated['actorUid'] = 'worker2'; // replaying under another actor
		self::assertNotSame($hash0, ChainService::entryHashFor(str_repeat('0', 64), ChainService::canonicalEntry($mutated)));

		$mutated = $base;
		$mutated['seq'] = 2; // reordering attempt
		self::assertNotSame($hash0, ChainService::entryHashFor(str_repeat('0', 64), ChainService::canonicalEntry($mutated)));

		// prev-hash difference propagates (chain linkage)
		self::assertNotSame(
			$hash0,
			ChainService::entryHashFor(str_repeat('1', 64), ChainService::canonicalEntry($base))
		);
	}

	public function testPayloadHashTamperSensitivity(): void
	{
		$h = ChainService::payloadHash(['id' => 1, 'body' => 'clean']);
		self::assertNotSame($h, ChainService::payloadHash(['id' => 1, 'body' => 'edited']));
		self::assertNotSame($h, ChainService::payloadHash(['id' => 1, 'body' => null]));
		self::assertNotSame($h, ChainService::payloadHash(['id' => 2, 'body' => 'clean']));
	}
}
