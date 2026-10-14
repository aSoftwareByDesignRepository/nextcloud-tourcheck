<?php
/**
 * Public live object page — scoped token, no auth, read-only.
 * @var array $_ @var \OCP\IL10N $l
 */
style('tourcheck', 'tck-tokens');
style('tourcheck', 'tck');
style('tourcheck', 'app');
$htmlLang = str_replace('_', '-', $l->getLanguageCode());
$live = (array)($_['live'] ?? []);
$statusLabel = [
	'scheduled' => $l->t('Scheduled'), 'active' => $l->t('Active'),
	'completed' => $l->t('Done'), 'missed' => $l->t('Missed'),
	'skipped' => $l->t('Skipped'),
];
?>
<div id="app-content" class="tck-app tck-app--live" lang="<?php p($htmlLang); ?>">
	<main id="tck-main-content" class="tck-main tck-main--live">
		<section class="tck-live" aria-labelledby="tck-live-title">
			<h1 id="tck-live-title" class="tck-live__title"><?php p((string)$live['siteName']); ?></h1>
			<p class="tck-live__lead"><?php p($l->t('Live status — updated %s', [(string)$live['updatedLabel']])); ?></p>
			<div class="tck-tiles">
				<div class="tck-tile tck-tile--done">
					<span class="tck-tile__num"><?php p((string)$live['doneToday']); ?></span>
					<span class="tck-tile__label"><?php p($l->t('Done today')); ?></span>
				</div>
				<div class="tck-tile tck-tile--missed">
					<span class="tck-tile__num"><?php p((string)$live['missedToday']); ?></span>
					<span class="tck-tile__label"><?php p($l->t('Missed today')); ?></span>
				</div>
				<div class="tck-tile tck-tile--incidents">
					<span class="tck-tile__num"><?php p((string)($live['openIncidents'] ?? 0)); ?></span>
					<span class="tck-tile__label"><?php p($l->t('Open incidents')); ?></span>
				</div>
			</div>
			<?php if (!empty($live['lastCompletedLabel'])): ?>
				<p class="tck-live__last"><?php p($l->t('Last completed at %s', [(string)$live['lastCompletedLabel']])); ?></p>
			<?php endif; ?>
			<h2 class="tck-section-title"><?php p($l->t('Latest rounds')); ?></h2>
			<ul class="tck-list">
				<?php foreach ((array)$live['rounds'] as $r): ?>
					<li class="tck-list__row">
						<span class="tck-list__main">
							<span class="tck-list__title"><?php p((string)$r['windowLabel']); ?></span>
						</span>
						<span class="tck-badge tck-badge--<?php p((string)$r['status']); ?>"><?php p($statusLabel[$r['status']] ?? $r['status']); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="tck-live__foot"><?php p($l->t('Powered by TourCheck — tamper-evident patrol records.')); ?></p>
		</section>
	</main>
</div>
