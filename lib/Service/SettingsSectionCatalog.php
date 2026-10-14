<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCP\IL10N;

/**
 * One settings page per topic (design-system settings standard). The route
 * requirement is derived from this catalog — unknown sections 404 uniformly.
 */
final class SettingsSectionCatalog
{
	public const SECTION_ACCESS = 'access';
	public const SECTION_SITE_DEFAULTS = 'site-defaults';
	public const SECTION_EVIDENCE = 'evidence';
	public const SECTION_RETENTION = 'retention';
	public const SECTION_BRANDING = 'branding';
	public const SECTION_STATIONS = 'stations';
	public const SECTION_LICENSE = 'license';
	public const SECTION_FEEDBACK = 'feedback';

	/** @return list<string> */
	public static function keys(): array
	{
		return [
			self::SECTION_ACCESS,
			self::SECTION_SITE_DEFAULTS,
			self::SECTION_EVIDENCE,
			self::SECTION_RETENTION,
			self::SECTION_BRANDING,
			self::SECTION_STATIONS,
			self::SECTION_LICENSE,
			self::SECTION_FEEDBACK,
		];
	}

	public static function routeRequirement(): string
	{
		return implode('|', self::keys());
	}

	/** @return list<array{key:string,label:string,lead:string}> */
	public static function sections(IL10N $l): array
	{
		return [
			['key' => self::SECTION_ACCESS, 'label' => $l->t('Access'), 'lead' => $l->t('Who may open TourCheck and who administers it.')],
			['key' => self::SECTION_SITE_DEFAULTS, 'label' => $l->t('Site defaults'), 'lead' => $l->t('Timezone, tolerances and kiosk PIN rules.')],
			['key' => self::SECTION_EVIDENCE, 'label' => $l->t('Evidence & timestamping'), 'lead' => $l->t('External RFC 3161 timestamp authority and chain verification.')],
			['key' => self::SECTION_RETENTION, 'label' => $l->t('Retention'), 'lead' => $l->t('How long positions, events and the journal are kept.')],
			['key' => self::SECTION_BRANDING, 'label' => $l->t('Branding'), 'lead' => $l->t('Customer name and accent used on reports.')],
			['key' => self::SECTION_STATIONS, 'label' => $l->t('Stations'), 'lead' => $l->t('Kiosk devices and member PINs.')],
			['key' => self::SECTION_LICENSE, 'label' => $l->t('License'), 'lead' => $l->t('Companion-app license key (web app stays free).')],
			['key' => self::SECTION_FEEDBACK, 'label' => $l->t('Feedback'), 'lead' => $l->t('Report a problem or suggest an improvement.')],
		];
	}
}
