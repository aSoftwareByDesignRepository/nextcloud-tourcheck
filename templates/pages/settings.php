<?php
/** @var array $_ @var \OCP\IL10N $l */
$section = (string)($_['settingsSection'] ?? 'site');
$values = (array)($_['settings'] ?? []);
?>
<section aria-labelledby="tck-set-title">
	<h2 id="tck-set-title" class="tck-section-title"><?php p((string)($_['settingsSectionLabel'] ?? $l->t('Settings'))); ?></h2>
	<?php
	$partial = __DIR__ . '/settings/' . preg_replace('/[^a-z]/', '', $section) . '.php';
	if (is_file($partial)) {
		include $partial;
	} else {
		p($l->t('Unknown settings section.'));
	}
	?>
</section>
