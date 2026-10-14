<?php
/**
 * TourCheck sidebar — Nextcloud #app-navigation (Check-family chrome).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\TourCheck\Service\IconCatalog;

/** @var \OCP\IURLGenerator $urlGenerator */
$pageId = (string)($_['pageId'] ?? 'dashboard');
$nav = (array)($_['nav'] ?? []);
$settingsSection = (string)($_['settingsSection'] ?? '');
$settingsSectionUrls = (array)(($_['urls']['settingsSections'] ?? []) ?: []);
$settingsSectionLabels = (array)($_['settingsSectionLabels'] ?? []);
$settingsChildren = [];
if ($pageId === 'settings' && $settingsSectionLabels !== []) {
	foreach ($settingsSectionLabels as $sectionId => $sectionLabel) {
		$childHref = (string)($settingsSectionUrls[$sectionId] ?? '');
		if ($childHref === '' || $childHref === '#') {
			continue;
		}
		$settingsChildren[] = [
			'id' => (string)$sectionId,
			'label' => (string)$sectionLabel,
			'href' => $childHref,
			'active' => $settingsSection === (string)$sectionId,
		];
	}
}

$groups = [
	'work' => $l->t('Work'),
	'evidence' => $l->t('Evidence'),
	'admin' => $l->t('Admin'),
];
$byGroup = [];
foreach ($nav as $item) {
	$g = (string)($item['group'] ?? 'work');
	$byGroup[$g][] = $item;
}

$roleLabel = $l->t('Member');
if (!empty($_['isAppAdmin'])) {
	$roleLabel = $l->t('App admin');
} elseif (!empty($_['isSupervisor'])) {
	$roleLabel = $l->t('Supervisor');
}
?>
<nav id="app-navigation" class="tck-nav" data-tck-nav role="navigation" aria-label="<?php p($l->t('TourCheck')); ?>">
	<div class="app-brand tck-brand">
		<span class="app-icon tck-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('shield-check', 'tck-brand__icon-svg')); ?>
		</span>
		<div class="app-info tck-brand__text">
			<h3 class="tck-brand__title"><?php p($l->t('TourCheck')); ?></h3>
			<p class="tck-brand__subtitle"><?php p($l->t('Rounds and checkpoint documentation')); ?></p>
		</div>
	</div>
	<?php $firstGroup = true; ?>
	<?php foreach ($groups as $groupId => $groupLabel): ?>
		<?php if (empty($byGroup[$groupId])) continue; ?>
		<?php if (!$firstGroup): ?>
			<hr class="tck-nav__divider" role="separator" aria-hidden="true">
		<?php endif; ?>
		<?php $firstGroup = false; ?>
		<div class="tck-nav__group" role="group" aria-labelledby="tck-nav-group-<?php p($groupId); ?>">
			<p id="tck-nav-group-<?php p($groupId); ?>" class="tck-nav__group-title"><?php p($groupLabel); ?></p>
			<ul class="tck-nav__list">
			<?php foreach ($byGroup[$groupId] as $item): ?>
				<?php $active = ($item['id'] ?? '') === $pageId || (!empty($item['match']) && in_array($pageId, (array)$item['match'], true)); ?>
				<li class="tck-nav__item<?php if ($active) p(' tck-nav__item--active'); ?>">
					<a class="tck-nav__link" href="<?php p((string)($item['href'] ?? '#')); ?>"<?php if ($active) p(' aria-current="page"'); ?>>
						<span class="tck-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render((string)($item['icon'] ?? 'layout-grid'), 'tck-nav__icon-svg')); ?></span>
						<span class="tck-nav__label"><?php p((string)($item['label'] ?? '')); ?></span>
					</a>
					<?php if ($active && ($item['id'] ?? '') === 'settings' && $settingsChildren !== []): ?>
						<ul class="tck-nav__sub">
							<?php foreach ($settingsChildren as $child): ?>
								<li class="tck-nav__subitem<?php if ($child['active']) p(' tck-nav__subitem--active'); ?>">
									<a class="tck-nav__sublink" href="<?php p($child['href']); ?>"<?php if ($child['active']) p(' aria-current="page"'); ?>><?php p($child['label']); ?></a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
			</ul>
		</div>
	<?php endforeach; ?>
	<div class="tck-nav__footer">
		<span class="tck-nav__role"><?php p($roleLabel); ?></span>
	</div>
	<?php include __DIR__ . '/../parts/feedback-nav-footer.php'; ?>
</nav>
