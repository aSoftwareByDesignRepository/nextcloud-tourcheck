<?php
/** Access-door denied page. @var array $_ @var \OCP\IL10N $l */
style('tourcheck', 'tck-tokens');
style('tourcheck', 'tck');
style('tourcheck', 'app');
$htmlLang = str_replace('_', '-', $l->getLanguageCode());
?>
<div id="app-content" class="tck-app tck-app--denied" lang="<?php p($htmlLang); ?>">
	<main id="tck-main-content" class="tck-main">
		<section class="tck-empty-state" aria-labelledby="tck-denied-title">
			<h1 id="tck-denied-title"><?php p($l->t('No access')); ?></h1>
			<p><?php p($l->t('TourCheck is not enabled for your account. Ask your administrator for access.')); ?></p>
		</section>
	</main>
</div>
