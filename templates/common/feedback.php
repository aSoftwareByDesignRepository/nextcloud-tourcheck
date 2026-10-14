<?php
/**
 * TourCheck feedback footer — §13 stack (feedback + Get the App + support-us).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$reportMailto = 'mailto:' . rawurlencode('info@software-by-design.de')
	. '?subject=' . rawurlencode($l->t('TourCheck feedback'))
	. '&body=' . rawurlencode($l->t('What happened? What did you expect?') . "\n\n" . $l->t('Page:') . ' ' . (string)($_['pageId'] ?? ''));
?>
<footer class="tck-footer" role="contentinfo">
	<div class="tck-footer__inner">
		<a class="tck-footer__link" href="<?php p($reportMailto); ?>"><?php p($l->t('Report a problem')); ?></a>
		<?php if (!empty($_['getTheAppUrl'])): ?>
			<a class="tck-footer__link" href="<?php p((string)$_['getTheAppUrl']); ?>"><?php p($l->t('Get the app')); ?></a>
		<?php endif; ?>
		<a class="tck-footer__link" href="https://github.com/sponsors/aSoftwareByDesignRepository" target="_blank" rel="noopener noreferrer"><?php p($l->t('Support development')); ?></a>
	</div>
</footer>
