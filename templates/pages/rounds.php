<?php
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
/** @var array $_ @var \OCP\IL10N $l */
$rounds = (array)($_['rounds'] ?? []);
$statusLabel = [
	'scheduled' => $l->t('Scheduled'), 'due' => $l->t('Due'), 'active' => $l->t('Active'),
	'completed' => $l->t('Done'), 'done' => $l->t('Done'), 'partial' => $l->t('Partial'), 'missed' => $l->t('Missed'),
	'skipped' => $l->t('Skipped'),
	'attested' => $l->t('Attested'),
];
?>
<section aria-labelledby="tck-rounds-title">
	<h2 id="tck-rounds-title" class="tck-section-title"><?php p($l->t('Rounds')); ?></h2>
	<?php if ($rounds === []): ?>
		<p class="tck-empty"><?php p($l->t('No rounds in this range.')); ?></p>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($rounds as $r): ?>
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
