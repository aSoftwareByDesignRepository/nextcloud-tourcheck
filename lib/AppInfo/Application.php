<?php

declare(strict_types=1);

namespace OCA\TourCheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCA\TourCheck\Service\UpgradeBackupService;
use OCA\TourCheck\Repair\BackupBeforeUpdate;
use OCA\TourCheck\Repair\EnsureGroups;
use OCA\TourCheck\Repair\EnsureTemplates;
use OCA\TourCheck\Repair\EnsureTourCheckSchema;
use OCA\TourCheck\Repair\UninstallDropTables;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

// Bundled splitbrain/phpQRCode lives under lib/Vendor. Nextcloud only autoloads
// OCA\* classes, so map the vendored prefix here (appended — never shadows
// OCP/OC; same pattern as inventorycheck).
spl_autoload_register(static function (string $class): void {
	$prefix = 'splitbrain\\phpQRCode\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/../Vendor/splitbrain/phpQRCode/'
			. str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
}, true, false);

class Application extends App implements IBootstrap
{
	public const APP_ID = 'tourcheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		// Repair steps — explicit factories. Every FQCN named in info.xml
		// MUST be registered with ALL constructor deps (occ upgrade resolves
		// them via the container; a missing arg stalls the upgrade).
		$context->registerService(EnsureTourCheckSchema::class, function ($c): EnsureTourCheckSchema {
			return new EnsureTourCheckSchema(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
			);
		});
		$context->registerService(EnsureGroups::class, function ($c): EnsureGroups {
			return new EnsureGroups(
				$c->get(\OCP\IGroupManager::class),
			);
		});
		$context->registerService(EnsureTemplates::class, function ($c): EnsureTemplates {
			return new EnsureTemplates(
				$c->get(\OCA\TourCheck\Service\TemplateService::class),
			);
		});
		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\Files\IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});

		// Without a registered INotifier every notifySite notification is
		// unpreparable — the server drops it on fetch (silent dead alerts).
		$context->registerNotifierService(\OCA\TourCheck\Notification\Notifier::class);

		// Suite flange F11 — advertise check:drive-segments to MobilityCheck.
		$context->registerCapability(\OCA\TourCheck\Capabilities::class);

		// NH-04: instance Dashboard widget (missed/active rounds + open incidents).
		$context->registerDashboardWidget(\OCA\TourCheck\Dashboard\TourCheckWidget::class);
	}

	public function boot(IBootContext $context): void
	{
	}
}
