<?php
/**
 * TourCheck page shell — design-system chrome (sidebar + page header).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\TourCheck\Service\IconCatalog;

/** @var \OCP\IURLGenerator $urlGenerator */
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
script('tourcheck', 'common/view-components');
script('tourcheck', 'app');
script('tourcheck', 'common/mobile-nav');
script('tourcheck', 'common/app-feedback');
style('tourcheck', 'tck-tokens');
style('tourcheck', 'common/view-components');
style('tourcheck', 'tck');
style('tourcheck', 'app');
$pageId = (string)($_['pageId'] ?? 'dashboard');
$htmlLang = str_replace('_', '-', $l->getLanguageCode());
$locale = $l->getLanguageCode();
$pageMeta = [
	'dashboard' => ['title' => $l->t('Dashboard'), 'help' => $l->t('Rounds at a glance.'), 'icon' => 'layout-grid'],
	'sites' => ['title' => $l->t('Sites'), 'help' => $l->t('Objects, checkpoints, and plans.'), 'icon' => 'building-2'],
	'site' => ['title' => $l->t('Site'), 'help' => '', 'icon' => 'building-2'],
	'rounds' => ['title' => $l->t('Rounds'), 'help' => $l->t('Past and upcoming rounds.'), 'icon' => 'route'],
	'round' => ['title' => $l->t('Round'), 'help' => '', 'icon' => 'route'],
	'journal' => ['title' => $l->t('Journal'), 'help' => $l->t('Tamper-evident record of every event.'), 'icon' => 'clipboard-list'],
	'incidents' => ['title' => $l->t('Incidents'), 'help' => $l->t('Findings and corrective actions.'), 'icon' => 'flag'],
	'reports' => ['title' => $l->t('Reports'), 'help' => $l->t('Scheduled and on-demand evidence.'), 'icon' => 'file-text'],
	'settings' => ['title' => $l->t('Settings'), 'help' => $l->t('Evidence, retention, branding, stations, access.'), 'icon' => 'settings'],
];
$meta = $pageMeta[$pageId] ?? ['title' => $l->t('TourCheck'), 'help' => '', 'icon' => 'shield-check'];
$pageTitle = (string)($_['pageTitle'] ?? $meta['title']);
$pageHelp = (string)($_['pageHelp'] ?? $meta['help']);
$headerIcon = (string)($_['pageIcon'] ?? $meta['icon']);
$dashUrl = $urlGenerator->linkToRoute('tourcheck.page.dashboard');

include __DIR__ . '/common/navigation.php';
?>
<div id="app-content"
	 class="tck-app tck-app--<?php p($pageId); ?>"
	 data-tck-page="<?php p($pageId); ?>"
	 lang="<?php p($htmlLang); ?>"
	 data-tck-locale="<?php p($locale); ?>">
	<a class="tck-skip-link" href="#tck-main-content"><?php p($l->t('Skip to content')); ?></a>
	<a class="tck-skip-link tck-skip-link--nav" href="#app-navigation"><?php p($l->t('Skip to navigation')); ?></a>
	<div id="tck-live-region" class="tck-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="tck-alert-region" class="tck-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="tck-shell tck-shell--constrained">
		<header class="tck-page-header" role="group" aria-labelledby="tck-page-title">
			<button type="button" class="tck-nav-toggle" id="tck-nav-toggle"
				aria-controls="app-navigation" aria-expanded="false"
				aria-label="<?php p($l->t('Open navigation menu')); ?>"
				data-tck-nav-toggle
				data-aria-label-open="<?php p($l->t('Open navigation menu')); ?>"
				data-aria-label-close="<?php p($l->t('Close navigation menu')); ?>">
				<span class="tck-nav-toggle__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('menu', 'tck-nav-toggle__icon-svg')); ?></span>
				<span class="tck-nav-toggle__label"><?php p($l->t('Menu')); ?></span>
			</button>
			<nav class="tck-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol>
					<li><a class="tck-breadcrumb__brand" href="<?php p($dashUrl); ?>"><?php p($l->t('TourCheck')); ?></a></li>
					<li class="tck-breadcrumb__sep" aria-hidden="true">/</li>
					<?php if ($pageId === 'settings' && !empty($_['settingsSectionLabel'])): ?>
						<li><a href="<?php p($urlGenerator->linkToRoute('tourcheck.page.settingsIndex')); ?>"><?php p($l->t('Settings')); ?></a></li>
						<li class="tck-breadcrumb__sep" aria-hidden="true">/</li>
						<li class="tck-breadcrumb__current" aria-current="page"><?php p((string)$_['settingsSectionLabel']); ?></li>
					<?php else: ?>
						<li class="tck-breadcrumb__current" aria-current="page"><?php p($pageTitle); ?></li>
					<?php endif; ?>
				</ol>
			</nav>
			<div class="tck-page-header__main">
				<div class="tck-page-header__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render($headerIcon, 'tck-page-header__icon-svg')); ?></div>
				<div class="tck-page-header__text">
					<h1 id="tck-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?>
						<p class="tck-page-header__lead"><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="tck-page-actions" class="tck-page-header__actions" aria-live="polite"></div>
			</div>
			<?php if (!empty($_['multiSite']) && !empty($_['sites'])): ?>
				<div class="tck-scope-strip" role="navigation" aria-label="<?php p($l->t('Site scope')); ?>">
					<label class="tck-scope-strip__label" for="tck-site-select"><?php p($l->t('Site')); ?></label>
					<select id="tck-site-select" class="tck-select">
						<?php $curSite = (int)($_['currentSiteId'] ?? 0); ?>
						<?php foreach ($_['sites'] as $site): ?>
							<option value="<?php p($site->getId()); ?>"<?php if ((int)$site->getId() === $curSite) p(' selected'); ?>><?php p($site->getName()); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
		</header>
		<main id="tck-main-content" class="tck-main" tabindex="-1" aria-labelledby="tck-page-title">
			<div class="tck-page-stack">
			<?php
			$partial = __DIR__ . '/pages/' . preg_replace('/[^a-z]/', '', $pageId) . '.php';
			if (is_file($partial)) {
				include $partial;
			}
			?>
			</div>
		</main>
		<?php include __DIR__ . '/common/feedback.php'; ?>
	</div>
</div>
