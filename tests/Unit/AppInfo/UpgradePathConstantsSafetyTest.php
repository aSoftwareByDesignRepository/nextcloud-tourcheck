<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\AppInfo;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Repair\EnsureGroups;
use OCA\TourCheck\Repair\EnsureTourCheckSchema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the upgrade path against the "stale Constants class" fatal.
 *
 * During an in-request app update (settings/apps/update or occ upgrade) the
 * previously installed copy of OCA\TourCheck\Constants is already loaded in
 * PHP memory when new repair steps and migrations run. Any reference to a
 * constant added in the same release resolves against the *old* class and
 * throws "Undefined constant", leaving the instance stuck in maintenance mode
 * (arbeitszeitcheck 1.9.4 / GH #45 — same latent pattern existed here).
 *
 * Rule: files under lib/Repair and lib/Migration must not reference
 * OCA\TourCheck\Constants at all. Stable wire/DB values (app id, group ids,
 * config keys) are inlined as literals or private consts instead.
 */
final class UpgradePathConstantsSafetyTest extends TestCase
{
	/**
	 * @return list<string>
	 */
	private static function upgradePathFiles(): array
	{
		$root = dirname(__DIR__, 3) . '/lib';
		$files = array_merge(
			glob($root . '/Repair/*.php') ?: [],
			glob($root . '/Migration/*.php') ?: [],
		);
		sort($files);
		return $files;
	}

	public function testUpgradePathDoesNotReferenceAppConstants(): void
	{
		$offenders = [];
		foreach (self::upgradePathFiles() as $file) {
			$source = file_get_contents($file);
			$this->assertIsString($source, $file);
			if (preg_match('/\bConstants::|use\s+OCA\\\\TourCheck\\\\Constants\b/', $source) === 1) {
				$offenders[] = basename($file);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'Upgrade-path code must not reference OCA\TourCheck\Constants — '
			. 'the previous release\'s copy may already be loaded when repair steps '
			. 'and migrations run during an app update. Inline stable values.',
		);
	}

	public function testInlinedGroupIdsMatchConstants(): void
	{
		$value = (new ReflectionClass(EnsureGroups::class))
			->getReflectionConstant('GROUP_IDS')
			->getValue();

		$this->assertSame(
			[
				Constants::GROUP_ADMIN,
				Constants::GROUP_PLANNER,
				Constants::GROUP_SUPERVISOR,
				Constants::GROUP_WORKER,
				Constants::GROUP_CLIENT,
			],
			$value,
			'EnsureGroups::GROUP_IDS drifted from Constants::GROUP_*',
		);
	}

	public function testInlinedSchemaKeysMatchConstants(): void
	{
		$refl = new ReflectionClass(EnsureTourCheckSchema::class);

		$this->assertSame(
			Constants::APP_ID,
			$refl->getReflectionConstant('APP_ID')->getValue(),
			'EnsureTourCheckSchema::APP_ID drifted from Constants::APP_ID',
		);
		$this->assertSame(
			Constants::CONFIG_CHAIN_SECRET,
			$refl->getReflectionConstant('CONFIG_CHAIN_SECRET')->getValue(),
			'EnsureTourCheckSchema::CONFIG_CHAIN_SECRET drifted from Constants::CONFIG_CHAIN_SECRET',
		);
	}
}
