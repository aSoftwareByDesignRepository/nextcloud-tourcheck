<?php
/**
 * Public live object page — scoped token, no auth, read-only.
 * @var array $_ @var \OCP\IL10N $l
 */
use OCA\TourCheck\Support\Badge;
style('tourcheck', 'tck-tokens');
style('tourcheck', 'common/view-components');
style('tourcheck', 'tck');
style('tourcheck', 'app');
$htmlLang = str_replace('_', '-', $l->getLanguageCode());
$live = (array)($_['live'] ?? []);
$statusLabel = [
	'scheduled' => $l->t('Scheduled'), 'active' => $l->t('Active'),
	'completed' => $l->t('Done'), 'partial' => $l->t('Partial'), 'missed' => $l->t('Missed'),
	'skipped' => $l->t('Skipped'),
];
?>
<div id="app-content" class="tck-app tck-app--live" lang="<?php p($htmlLang); ?>">
	<main id="tck-main-content" class="tck-main tck-main--live">
		<section class="tck-live" aria-labelledby="tck-live-title">
			<h1 id="tck-live-title" class="tck-live__title"><?php p((string)$live['siteName']); ?></h1>
			<p class="tck-live__lead"><?php p($l->t('Live status — updated %s', [(string)$live['updatedLabel']])); ?></p>
			<div class="tck-stat-strip">
				<div class="tck-stat-tile tck-stat-tile--success">
					<span class="tck-stat-tile__label"><?php p($l->t('Done today')); ?></span>
					<span class="tck-stat-tile__value"><?php p((string)$live['doneToday']); ?></span>
				</div>
				<div class="tck-stat-tile tck-stat-tile--danger">
					<span class="tck-stat-tile__label"><?php p($l->t('Missed today')); ?></span>
					<span class="tck-stat-tile__value"><?php p((string)$live['missedToday']); ?></span>
				</div>
				<div class="tck-stat-tile tck-stat-tile--warning">
					<span class="tck-stat-tile__label"><?php p($l->t('Open incidents')); ?></span>
					<span class="tck-stat-tile__value"><?php p((string)($live['openIncidents'] ?? 0)); ?></span>
				</div>
			</div>
			<?php if (!empty($live['lastCompletedLabel'])): ?>
				<p class="tck-live__last"><?php p($l->t('Last completed at %s', [(string)$live['lastCompletedLabel']])); ?></p>
			<?php endif; ?>
			<div class="tck-list-head">
				<h2 class="tck-list-head__title"><?php p($l->t('Latest rounds')); ?></h2>
			</div>
			<?php if ((array)$live['rounds'] === []): ?>
				<div class="tck-empty-state">
					<p class="tck-empty-state__title"><?php p($l->t('No rounds yet')); ?></p>
				</div>
			<?php else: ?>
				<ul class="tck-list">
					<?php foreach ((array)$live['rounds'] as $r): ?>
						<li class="tck-list__row">
							<span class="tck-list__main">
								<span class="tck-list__title"><?php p((string)$r['windowLabel']); ?></span>
							</span>
							<?php print_unescaped(Badge::render((string)($statusLabel[$r['status']] ?? $r['status']), Badge::roundStatus((string)$r['status']))); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="tck-live__foot"><?php p($l->t('Powered by TourCheck — tamper-evident patrol records.')); ?></p>
		</section>
	</main>
</div>
