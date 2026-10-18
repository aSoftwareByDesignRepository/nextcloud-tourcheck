<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Public;

use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Public\RoundLinkReadFacade;
use PHPUnit\Framework\TestCase;

/**
 * F17 producer contract: id hygiene (only digit ids reach the mapper),
 * unknown rounds absent from the map (never an existence oracle), the
 * 200-id cap, and the date picker rejecting malformed input.
 */
class RoundLinkReadFacadeTest extends TestCase
{
	private function facade(RoundMapper $rounds, SiteMapper $sites): RoundLinkReadFacade
	{
		return new RoundLinkReadFacade($rounds, $sites);
	}

	public function testStatusesOfFiltersMalformedIdsWithoutDbHits(): void
	{
		$rounds = $this->createMock(RoundMapper::class);
		$rounds->expects($this->once())->method('find')->with(7)->willReturn(null);
		$f = $this->facade($rounds, $this->createMock(SiteMapper::class));
		self::assertSame([], $f->statusesOf([0, -1, 'abc', '1.5', ' 5', true, 7]));
	}

	public function testStatusesOfMapsRoundAndSite(): void
	{
		$round = new Round();
		$round->setId(7);
		$round->setSiteId(3);
		$round->setStatus('missed');
		$round->setScheduledStart(new \DateTime('2098-06-02 08:00:00'));
		$round->setWindowEnd(new \DateTime('2098-06-02 20:00:00'));
		$site = new Site();
		$site->setName('North Gate');

		$rounds = $this->createMock(RoundMapper::class);
		$rounds->method('find')->with(7)->willReturn($round);
		$sites = $this->createMock(SiteMapper::class);
		$sites->method('find')->with(3)->willReturn($site);

		$out = $this->facade($rounds, $sites)->statusesOf([7, 7, '7']);
		self::assertCount(1, $out);
		self::assertSame('missed', $out[7]['status']);
		self::assertSame(3, $out[7]['siteId']);
		self::assertSame('North Gate', $out[7]['siteName']);
		self::assertSame('2098-06-02 08:00:00', $out[7]['scheduledStart']);
	}

	public function testStatusesOfCapsAtTwoHundredIds(): void
	{
		$rounds = $this->createMock(RoundMapper::class);
		$rounds->expects($this->exactly(200))->method('find')->willReturn(null);
		$f = $this->facade($rounds, $this->createMock(SiteMapper::class));
		self::assertSame([], $f->statusesOf(range(1, 250)));
	}

	public function testOpenRoundsForDateRejectsMalformedInput(): void
	{
		$rounds = $this->createMock(RoundMapper::class);
		$rounds->expects($this->never())->method('findScheduledOn');
		$f = $this->facade($rounds, $this->createMock(SiteMapper::class));
		self::assertSame([], $f->openRoundsForDate('not-a-date'));
		self::assertSame([], $f->openRoundsForDate(''));
		self::assertSame([], $f->openRoundsForDate('2098/06/02'));
	}

	public function testOpenRoundsForDateMapsEntities(): void
	{
		$round = new Round();
		$round->setId(9);
		$round->setSiteId(4);
		$round->setStatus('scheduled');
		$round->setScheduledStart(new \DateTime('2098-06-02 06:30:00'));
		$round->setWindowEnd(new \DateTime('2098-06-02 14:30:00'));
		$site = new Site();
		$site->setName('Depot');

		$rounds = $this->createMock(RoundMapper::class);
		$rounds->expects($this->once())->method('findScheduledOn')->willReturn([$round]);
		$sites = $this->createMock(SiteMapper::class);
		$sites->method('find')->with(4)->willReturn($site);

		$out = $this->facade($rounds, $sites)->openRoundsForDate('2098-06-02');
		self::assertCount(1, $out);
		self::assertSame(9, $out[0]['id']);
		self::assertSame('Depot', $out[0]['siteName']);
		self::assertSame('2098-06-02 06:30', $out[0]['scheduledStart']);
	}
}
