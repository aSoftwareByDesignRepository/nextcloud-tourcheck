<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Service\SettingsService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Map-tile provider settings: the schematic fallback is the privacy default
 * (Q2), provider classification drives the settings picker, and a malformed
 * template must never reach the Leaflet layer (fail closed on read).
 */
class TileProviderTest extends TestCase
{
	/** @var array<string,string> */
	private array $store = [];
	private SettingsService $settings;

	protected function setUp(): void
	{
		$this->store = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default
		);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->store[$key] = $value;
		});
		$this->settings = new SettingsService($config);
	}

	public function testFreshInstallDefaultsToSchematic(): void
	{
		self::assertSame('', $this->settings->getTileUrl());
		self::assertSame(Constants::TILE_PROVIDER_SCHEMATIC, $this->settings->getTileProvider());
		$snap = $this->settings->snapshot();
		self::assertSame('', $snap['tileUrl']);
		self::assertSame('schematic', $snap['tileProvider']);
		self::assertSame('', $snap['tileAttribution']);
	}

	public function testOsmPresetClassifiesAsOsm(): void
	{
		$this->settings->setTileUrl(Constants::TILE_URL_OPENSTREETMAP);
		self::assertSame(Constants::TILE_PROVIDER_OSM, $this->settings->getTileProvider());
		self::assertSame(Constants::TILE_URL_OPENSTREETMAP, $this->settings->getTileUrl());
		self::assertSame(Constants::TILE_ATTRIBUTION_OSM, $this->settings->getTileAttributionEffective());
	}

	public function testCustomUrlRoundTrips(): void
	{
		$url = 'https://tiles.example.com/{z}/{x}/{y}.png';
		$this->settings->setTileUrl($url);
		self::assertSame(Constants::TILE_PROVIDER_CUSTOM, $this->settings->getTileProvider());
		self::assertSame($url, $this->settings->getTileUrl());
		// No stored attribution → host credit fallback.
		self::assertSame('© tiles.example.com', $this->settings->getTileAttributionEffective());
	}

	public function testStoredAttributionWinsOverHostFallback(): void
	{
		$this->settings->setTileUrl('https://tiles.example.com/{z}/{x}/{y}.png');
		$this->settings->setTileAttribution('© Example Maps');
		self::assertSame('© Example Maps', $this->settings->getTileAttributionEffective());
	}

	public function testAttributionIsPlainTextAndBounded(): void
	{
		$this->settings->setTileAttribution('<a href="https://evil.example">© x</a>');
		self::assertSame('© x', $this->settings->getTileAttribution());
		$this->settings->setTileAttribution(str_repeat('a', 500));
		self::assertSame(300, mb_strlen($this->settings->getTileAttribution()));
	}

	public function testSchematicHasNoAttribution(): void
	{
		self::assertSame('', $this->settings->getTileAttributionEffective());
	}

	public function testInvalidStoredUrlFailsClosedOnRead(): void
	{
		// A legacy/tampered value the validator rejects renders schematic —
		// but stays classified 'custom' so the admin sees and can fix it.
		$this->store[Constants::CONFIG_TILE_URL] = 'http://tiles.example.com/{z}/{x}/{y}.png';
		self::assertSame('', $this->settings->getTileUrl());
		self::assertSame(Constants::TILE_PROVIDER_CUSTOM, $this->settings->getTileProvider());
	}

	/**
	 * @dataProvider invalidTemplates
	 */
	public function testInvalidTemplatesRejected(string $url): void
	{
		self::assertFalse(SettingsService::isValidTileTemplate($url));
	}

	/** @return array<string,array{string}> */
	public static function invalidTemplates(): array
	{
		return [
			'empty' => [''],
			'plain http' => ['http://tiles.example.com/{z}/{x}/{y}.png'],
			'missing {z}' => ['https://tiles.example.com/{x}/{y}.png'],
			'missing {x}' => ['https://tiles.example.com/{z}/{y}.png'],
			'missing {y}' => ['https://tiles.example.com/{z}/{x}.png'],
			'whitespace inside' => ['https://tiles.example.com/{z}/{x} {y}.png'],
			'not a url' => ['{z}/{x}/{y}'],
		];
	}

	/**
	 * @dataProvider validTemplates
	 */
	public function testValidTemplatesAccepted(string $url): void
	{
		self::assertTrue(SettingsService::isValidTileTemplate($url));
	}

	/* ── img-src CSP derivation ──────────────────────────────────────
	 * CSP `*.host` never matches the apex — the OSM preset must therefore
	 * emit BOTH forms, while a custom bare host stays least-privilege. */

	public function testSchematicEmitsNoCspDomains(): void
	{
		self::assertSame([], $this->settings->getTileImageCspDomains());
	}

	public function testInvalidStoredUrlEmitsNoCspDomains(): void
	{
		$this->store[Constants::CONFIG_TILE_URL] = 'http://tiles.example.com/{z}/{x}/{y}.png';
		self::assertSame([], $this->settings->getTileImageCspDomains());
	}

	public function testOsmPresetEmitsApexAndWildcard(): void
	{
		$this->settings->setTileUrl(Constants::TILE_URL_OPENSTREETMAP);
		self::assertSame(
			['https://tile.openstreetmap.org', 'https://*.tile.openstreetmap.org'],
			$this->settings->getTileImageCspDomains(),
		);
	}

	public function testCustomBareHostEmitsApexOnly(): void
	{
		$this->settings->setTileUrl('https://tiles.example.com/{z}/{x}/{y}.png');
		self::assertSame(['https://tiles.example.com'], $this->settings->getTileImageCspDomains());
	}

	public function testSubdomainTemplateEmitsWildcardOnly(): void
	{
		// {s} only ever resolves to a subdomain — the apex is never requested.
		$this->settings->setTileUrl('https://{s}.tiles.example.com/{z}/{x}/{y}.png');
		self::assertSame(['https://*.tiles.example.com'], $this->settings->getTileImageCspDomains());
	}

	public function testPortIsPreservedInCspDomain(): void
	{
		$this->settings->setTileUrl('https://tiles.example.com:8443/{z}/{x}/{y}.png');
		self::assertSame(['https://tiles.example.com:8443'], $this->settings->getTileImageCspDomains());
	}

	public function testMidDomainPlaceholderWidensToParentWildcard(): void
	{
		// `tile{n}.example.com` cannot be expressed in CSP — the wildcard
		// must be leftmost, so the parent domain is the safe superset.
		$this->settings->setTileUrl('https://tile{n}.example.com/{z}/{x}/{y}.png');
		self::assertSame(['https://*.example.com'], $this->settings->getTileImageCspDomains());
	}

	public function testGluedWildcardLabelWidensToParent(): void
	{
		// `{s}cdn.example.com` → `*cdn.example.com` is not a legal CSP host —
		// the wildcard must own the leftmost label, so widen to the parent.
		$this->settings->setTileUrl('https://{s}cdn.example.com/{z}/{x}/{y}.png');
		self::assertSame(['https://*.example.com'], $this->settings->getTileImageCspDomains());
	}

	public function testPlaceholderOnlyHostFailsClosed(): void
	{
		$this->settings->setTileUrl('https://{s}/{z}/{x}/{y}.png');
		self::assertSame([], $this->settings->getTileImageCspDomains());
	}

	/** @return array<string,array{string}> */
	public static function validTemplates(): array
	{
		return [
			'osm' => [Constants::TILE_URL_OPENSTREETMAP],
			'subdomain + retina extras' => ['https://{s}.tiles.example.com/{z}/{x}/{y}{r}.png'],
			'query suffix' => ['https://tiles.example.com/{z}/{x}/{y}.png?key=abc'],
		];
	}
}
