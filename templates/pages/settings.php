<?php
/** @var array $_ @var \OCP\IL10N $l */
$section = (string)($_['settingsSection'] ?? 'site');
?>
<section aria-labelledby="tck-set-title">
	<div class="tck-list-head">
		<h2 id="tck-set-title" class="tck-list-head__title"><?php p((string)($_['settingsSectionLabel'] ?? $l->t('Settings'))); ?></h2>
	</div>
	<?php
	$partial = __DIR__ . '/settings/' . preg_replace('/[^a-z]/', '', $section) . '.php';
	if (is_file($partial)) {
		include $partial;
	} else {
		p($l->t('Unknown settings section.'));
	}
	?>
</section>
