<?php
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
/** @var array $_ @var \OCP\IL10N $l */
use OCA\TourCheck\Service\IconCatalog;
use OCA\TourCheck\Support\Badge;
$dash = (array)($_['dashboard'] ?? []);
$coach = (array)($_['coach'] ?? []);
$statusLabel = [
	'scheduled' => $l->t('Scheduled'), 'due' => $l->t('Due'), 'active' => $l->t('Active'),
	'completed' => $l->t('Done'), 'done' => $l->t('Done'), 'partial' => $l->t('Partial'), 'missed' => $l->t('Missed'),
	'skipped' => $l->t('Skipped'),
	'attested' => $l->t('Attested'),
];
$sevLabel = ['low' => $l->t('Low'), 'medium' => $l->t('Medium'), 'high' => $l->t('High'), 'critical' => $l->t('Critical')];
$tiles = [
	['key' => 'due', 'label' => $l->t('Due'), 'variant' => 'warning', 'href' => $urlGenerator->linkToRoute('tourcheck.page.rounds')],
	['key' => 'active', 'label' => $l->t('Active'), 'variant' => 'primary', 'href' => $urlGenerator->linkToRoute('tourcheck.page.rounds')],
	['key' => 'missed', 'label' => $l->t('Missed'), 'variant' => 'danger', 'href' => $urlGenerator->linkToRoute('tourcheck.page.rounds') . '?status=missed'],
	['key' => 'done', 'label' => $l->t('Done'), 'variant' => 'success', 'href' => $urlGenerator->linkToRoute('tourcheck.page.rounds') . '?status=done'],
];
$nextRounds = (array)($dash['nextRounds'] ?? []);
$openIncidents = (array)($dash['openIncidents'] ?? []);
?>
<?php if (!empty($dash['cronStale'])): ?>
	<div class="tck-callout tck-callout--warning" role="alert" data-tck-cron-stale>
		<p class="tck-callout__title"><?php p($l->t('Background jobs are not running')); ?></p>
		<p><?php p($l->t('Generated tours may be missing. Check the instance cron setup.')); ?></p>
	</div>
<?php endif; ?>
<?php if (!empty($coach['steps'])): ?>
<section class="tck-callout tck-callout--info" aria-labelledby="tck-coach-title" data-tck-coach>
	<h2 id="tck-coach-title" class="tck-callout__title"><?php p($l->t('Get started')); ?></h2>
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

<section class="tck-stat-strip" aria-label="<?php p($l->t('Round status')); ?>">
	<?php foreach ($tiles as $tile): ?>
		<a class="tck-stat-tile tck-stat-tile--<?php p($tile['variant']); ?>" href="<?php p($tile['href']); ?>">
			<span class="tck-stat-tile__label"><?php p($tile['label']); ?></span>
			<span class="tck-stat-tile__value"><?php p((string)($dash[$tile['key']] ?? 0)); ?></span>
		</a>
	<?php endforeach; ?>
</section>

<section aria-labelledby="tck-next-title">
	<div class="tck-list-head">
		<h2 id="tck-next-title" class="tck-list-head__title"><?php p($l->t('Next rounds')); ?></h2>
		<?php if ($nextRounds !== []): ?>
			<span class="tck-list-head__count"><?php p($l->t('%n shown', ['%n' => count($nextRounds)])); ?></span>
		<?php endif; ?>
	</div>
	<?php if ($nextRounds === []): ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('Nothing scheduled')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Create a plan on a site and rounds schedule themselves.')); ?></p>
			<?php if (!empty($_['canManage'])): ?>
				<a class="tck-btn tck-btn--primary" href="<?php p($urlGenerator->linkToRoute('tourcheck.page.sites')); ?>"><?php p($l->t('Open sites')); ?></a>
			<?php endif; ?>
		</div>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($nextRounds as $r): ?>
				<li class="tck-list__row">
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

<section aria-labelledby="tck-inc-title">
	<div class="tck-list-head">
		<h2 id="tck-inc-title" class="tck-list-head__title"><?php p($l->t('Open incidents')); ?></h2>
		<?php if ($openIncidents !== []): ?>
			<span class="tck-list-head__count"><?php p($l->t('%n shown', ['%n' => count($openIncidents)])); ?></span>
		<?php endif; ?>
	</div>
	<?php if ($openIncidents === []): ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No open incidents')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Findings reported on rounds appear here until they are closed.')); ?></p>
		</div>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($openIncidents as $i): ?>
				<li class="tck-list__row">
					<a class="tck-list__main" href="<?php p($urlGenerator->linkToRoute('tourcheck.page.incidents')); ?>">
						<span class="tck-list__title"><?php p((string)$i['title']); ?></span>
						<span class="tck-list__sub"><?php p((string)$i['siteName']); ?></span>
					</a>
					<?php print_unescaped(Badge::render((string)($sevLabel[$i['severity']] ?? $i['severity']), Badge::incidentSeverity((string)$i['severity']))); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
