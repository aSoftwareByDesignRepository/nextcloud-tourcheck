<?php
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
/** @var array $_ @var \OCP\IL10N $l */
use OCA\TourCheck\Service\IconCatalog;
$dash = (array)($_['dashboard'] ?? []);
$coach = (array)($_['coach'] ?? []);
$statusLabel = [
	'scheduled' => $l->t('Scheduled'), 'due' => $l->t('Due'), 'active' => $l->t('Active'),
	'completed' => $l->t('Done'), 'done' => $l->t('Done'), 'partial' => $l->t('Partial'), 'missed' => $l->t('Missed'),
	'skipped' => $l->t('Skipped'),
	'attested' => $l->t('Attested'),
];
?>
<?php if (!empty($dash['cronStale'])): ?>
	<p class="tck-notice tck-notice--warn" role="alert" data-tck-cron-stale><?php p($l->t('Background jobs are not running — generated tours may be missing. Check the instance cron setup.')); ?></p>
<?php endif; ?>
<?php if (!empty($coach['steps'])): ?>
<section class="tck-coach" aria-labelledby="tck-coach-title" data-tck-coach>
	<h2 id="tck-coach-title" class="tck-section-title"><?php p($l->t('Get started')); ?></h2>
	<ol class="tck-coach__steps">
		<?php foreach ($coach['steps'] as $step): ?>
			<li class="tck-coach__step<?php if (!empty($step['done'])) p(' tck-coach__step--done'); ?>">
				<span class="tck-coach__state" aria-hidden="true"><?php print_unescaped(IconCatalog::render(!empty($step['done']) ? 'check-circle' : 'route', 'tck-icon')); ?></span>
				<span class="tck-coach__label"><?php p((string)$step['label']); ?></span>
				<?php if (empty($step['done']) && !empty($step['href'])): ?>
					<a class="tck-btn tck-btn--primary" href="<?php p((string)$step['href']); ?>"><?php p((string)($step['cta'] ?? $l->t('Start'))); ?></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
<?php endif; ?>

<section class="tck-tiles" aria-label="<?php p($l->t('Round status')); ?>">
	<?php foreach (['due', 'active', 'missed', 'done'] as $k): ?>
		<div class="tck-tile tck-tile--<?php p($k); ?>">
			<span class="tck-tile__num"><?php p((string)($dash[$k] ?? 0)); ?></span>
			<span class="tck-tile__label"><?php p($statusLabel[$k] ?? $k); ?></span>
		</div>
	<?php endforeach; ?>
</section>

<section aria-labelledby="tck-next-title">
	<h2 id="tck-next-title" class="tck-section-title"><?php p($l->t('Next rounds')); ?></h2>
	<?php if (empty($dash['nextRounds'])): ?>
		<p class="tck-empty"><?php p($l->t('Nothing scheduled. Create a plan on a site to get started.')); ?></p>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ((array)$dash['nextRounds'] as $r): ?>
				<li class="tck-list__row">
					<a class="tck-list__main" href="<?php p($urlGenerator->linkToRoute('tourcheck.page.round', ['roundId' => $r['id']])); ?>">
						<span class="tck-list__title"><?php p((string)$r['siteName']); ?></span>
						<span class="tck-list__sub"><?php p((string)$r['windowStartLabel'] . ' – ' . $r['windowEndLabel']); ?></span>
					</a>
					<span class="tck-badge tck-badge--<?php p((string)$r['status']); ?>"><?php p($statusLabel[$r['status']] ?? $r['status']); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<section aria-labelledby="tck-inc-title">
	<h2 id="tck-inc-title" class="tck-section-title"><?php p($l->t('Open incidents')); ?></h2>
	<?php if (empty($dash['openIncidents'])): ?>
		<p class="tck-empty"><?php p($l->t('No open incidents.')); ?></p>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ((array)$dash['openIncidents'] as $i): ?>
				<li class="tck-list__row">
					<a class="tck-list__main" href="<?php p($urlGenerator->linkToRoute('tourcheck.page.incidents')); ?>">
						<span class="tck-list__title"><?php p((string)$i['title']); ?></span>
						<span class="tck-list__sub"><?php p((string)$i['siteName']); ?></span>
					</a>
					<span class="tck-badge tck-badge--warn"><?php p((string)$i['severity']); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
