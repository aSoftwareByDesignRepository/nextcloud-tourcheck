<?php
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
/** @var array $_ @var \OCP\IL10N $l */
use OCA\TourCheck\Support\Badge;
$rounds = (array)($_['rounds'] ?? []);
$activeStatus = (string)($_['roundsStatus'] ?? '');
$statusLabel = [
	'scheduled' => $l->t('Scheduled'), 'active' => $l->t('Active'),
	'completed' => $l->t('Done'), 'partial' => $l->t('Partial'), 'missed' => $l->t('Missed'),
	'skipped' => $l->t('Skipped'), 'attested' => $l->t('Attested'), 'done' => $l->t('Done'),
];
/* Recipe-B pills: the value is a status group, JS maps it onto real
   statuses — 'done' covers completed/partial/attested rows. */
$pills = [
	'' => $l->t('All'),
	'scheduled' => $l->t('Scheduled'),
	'active' => $l->t('Active'),
	'missed' => $l->t('Missed'),
	'done' => $l->t('Done'),
	'skipped' => $l->t('Skipped'),
];
?>
<section aria-labelledby="tck-rounds-title">
	<div class="tck-list-head">
		<h2 id="tck-rounds-title" class="tck-list-head__title"><?php p($l->t('Rounds')); ?></h2>
		<span class="tck-list-head__count" data-tck-rounds-count role="status"></span>
	</div>
	<?php if ($rounds === []): ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No rounds yet')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Rounds appear here once a plan starts scheduling them.')); ?></p>
		</div>
	<?php else: ?>
		<div class="tck-quick-filters">
			<span class="tck-quick-filters__label" id="tck-rounds-filter-label"><?php p($l->t('Show')); ?></span>
			<div class="tck-filter-bar" data-tck-rounds-filter aria-labelledby="tck-rounds-filter-label">
				<?php foreach ($pills as $value => $label): ?>
					<button type="button" class="tck-filter" data-value="<?php p($value); ?>"
						aria-pressed="<?php p($value === $activeStatus ? 'true' : 'false'); ?>">
						<?php p($label); ?>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="tck-empty-state" data-tck-rounds-empty-filtered hidden>
			<p class="tck-empty-state__title"><?php p($l->t('Nothing matches')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Pick another status to see more rounds.')); ?></p>
		</div>
		<ul class="tck-list" data-tck-rounds-list>
			<?php foreach ($rounds as $r): ?>
				<li class="tck-list__row" data-status="<?php p((string)$r['status']); ?>">
					<a class="tck-list__main" href="<?php p($urlGenerator->linkToRoute('tourcheck.page.round', ['roundId' => $r['id']])); ?>">
						<span class="tck-list__title"><?php p((string)$r['siteName']); ?></span>
						<span class="tck-list__sub"><?php p((string)$r['windowStartLabel'] . ' – ' . $r['windowEndLabel']); ?></span>
					</a>
					<?php print_unescaped(Badge::render((string)($statusLabel[$r['status']] ?? $r['status']), Badge::roundStatus((string)$r['status']))); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
