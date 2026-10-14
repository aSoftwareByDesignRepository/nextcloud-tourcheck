<?php /** @var array $_ @var \OCP\IL10N $l */
$reportMailto = 'mailto:' . rawurlencode('info@software-by-design.de') . '?subject=' . rawurlencode($l->t('TourCheck feedback')); ?>
<section aria-labelledby="tck-fb-title">
	<h2 id="tck-fb-title" class="tck-section-title"><?php p($l->t('Feedback & support')); ?></h2>
	<ul class="tck-list">
		<li class="tck-list__row">
			<span class="tck-list__main"><span class="tck-list__title"><?php p($l->t('Report a problem')); ?></span>
			<span class="tck-list__sub"><?php p($l->t('Mail us what happened — include the page and time.')); ?></span></span>
			<a class="tck-btn" href="<?php p($reportMailto); ?>"><?php p($l->t('Write mail')); ?></a>
		</li>
		<li class="tck-list__row">
			<span class="tck-list__main"><span class="tck-list__title"><?php p($l->t('Support development')); ?></span>
			<span class="tck-list__sub"><?php p($l->t('TourCheck is AGPL — sponsoring keeps it maintained.')); ?></span></span>
			<a class="tck-btn" href="https://github.com/sponsors/aSoftwareByDesignRepository" target="_blank" rel="noopener noreferrer"><?php p($l->t('Sponsor')); ?></a>
		</li>
		<li class="tck-list__row">
			<span class="tck-list__main"><span class="tck-list__title"><?php p($l->t('Get the app')); ?></span>
			<span class="tck-list__sub"><?php p($l->t('The licensed native companion for workers and stations.')); ?></span></span>
			<a class="tck-btn" href="https://nextcloud.software-by-design.de/" target="_blank" rel="noopener noreferrer"><?php p($l->t('Learn more')); ?></a>
		</li>
	</ul>
</section>
<?php
$supportUsLinks = $_['supportUsLinks'] ?? null;
$supportUsCssPrefix = 'tck';
include __DIR__ . '/../../parts/support-us-section.php';
?>
