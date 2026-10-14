<?php
/**
 * Worker capture surface — scan-first, ≤2 taps to queued evidence (UX-2).
 * @var array $_ @var \OCP\IL10N $l
 */
script('tourcheck', 'capture');
style('tourcheck', 'tck-tokens');
style('tourcheck', 'tck');
style('tourcheck', 'app');
$htmlLang = str_replace('_', '-', $l->getLanguageCode());
$round = $_['activeRound'] ?? null;
?>
<div id="app-content" class="tck-app tck-app--capture" lang="<?php p($htmlLang); ?>">
	<a class="tck-skip-link" href="#tck-main-content"><?php p($l->t('Skip to content')); ?></a>
	<div id="tck-live-region" class="tck-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="tck-alert-region" class="tck-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="tck-shell tck-shell--capture">
		<main id="tck-main-content" class="tck-main" tabindex="-1">
			<section class="tck-capture" data-tck-capture<?php if (!empty($_['signalSiteId'])): ?> data-tck-site-id="<?php p((string)$_['signalSiteId']); ?>"<?php endif; ?>>
				<h1 class="tck-capture__title"><?php p($l->t('Scan checkpoint')); ?></h1>
				<?php if ($round): ?>
					<p class="tck-capture__progress" data-tck-progress>
						<?php p($l->t('%1$d of %2$d — %3$s', [(int)$round['doneCount'], (int)$round['totalCount'], (string)$round['siteName']])); ?>
					</p>
				<?php else: ?>
					<p class="tck-capture__progress"><?php p($l->t('No active round — a scan will attach when one starts.')); ?></p>
				<?php endif; ?>

				<div class="tck-scanbox" data-tck-scanbox>
					<label class="tck-scanbox__label" for="tck-scan-input"><?php p($l->t('Point the scanner or paste the code')); ?></label>
					<input id="tck-scan-input" class="tck-scanbox__input" type="text" inputmode="text"
						autocomplete="off" autocapitalize="off" spellcheck="false"
						data-tck-scan-input autofocus
						value="<?php p((string)($_['prefillToken'] ?? '')); ?>"
						aria-describedby="tck-scan-hint">
					<p id="tck-scan-hint" class="tck-field-hint"><?php p($l->t('QR and NFC scanners type the code for you — just scan.')); ?></p>
					<p class="tck-field-error" id="tck-scan-error" hidden></p>
				</div>

				<div class="tck-capture__confirm tck-hidden" data-tck-confirm>
					<p class="tck-capture__cpname" data-tck-cpname></p>
					<button type="button" class="tck-btn tck-btn--primary tck-btn--xl" data-tck-confirm-btn>
						<?php p($l->t('Confirm checkpoint')); ?>
					</button>
				</div>

				<p class="tck-capture__reject tck-hidden" role="alert" data-tck-reject></p>
				<div class="tck-capture__action tck-hidden" data-tck-corrective></div>

				<?php if ($round && !empty($round['canClose'])): ?>
					<button type="button" class="tck-btn tck-btn--xl" data-tck-close-round="<?php p((string)$round['id']); ?>">
						<?php p($l->t('Close round')); ?>
					</button>
				<?php endif; ?>
			</section>
		</main>
	</div>
</div>
