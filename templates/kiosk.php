<?php
/**
 * Kiosk/station surface — member grid + PIN. Session resets after every
 * confirm: zero retained identity (UX-4).
 * @var array $_ @var \OCP\IL10N $l
 */
script('tourcheck', 'kiosk');
style('tourcheck', 'tck-tokens');
style('tourcheck', 'tck');
style('tourcheck', 'app');
$htmlLang = str_replace('_', '-', $l->getLanguageCode());
$members = (array)($_['members'] ?? []);
$stationName = (string)($_['stationName'] ?? 'Station');
?>
<div id="app-content" class="tck-app tck-app--kiosk" lang="<?php p($htmlLang); ?>">
	<div id="tck-live-region" class="tck-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="tck-alert-region" class="tck-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<main id="tck-main-content" class="tck-main tck-main--kiosk" tabindex="-1">
		<section class="tck-kiosk" data-tck-kiosk data-station-name="<?php p($stationName); ?>" data-pin-len="<?php p((string)($_['pinLength'] ?? 6)); ?>">
			<h1 class="tck-kiosk__title"><?php p($stationName); ?></h1>

			<div class="tck-kiosk__stage" data-tck-stage="grid">
				<p class="tck-kiosk__prompt"><?php p($l->t('Who are you?')); ?></p>
				<ul class="tck-kiosk__grid" role="list">
					<?php foreach ($members as $m): ?>
						<li>
							<button type="button" class="tck-kiosk__tile" data-tck-member="<?php p((string)$m['id']); ?>" data-name="<?php p((string)$m['displayName']); ?>">
								<span class="tck-kiosk__initials" aria-hidden="true"><?php p((string)$m['initials']); ?></span>
								<span class="tck-kiosk__name"><?php p((string)$m['displayName']); ?></span>
							</button>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="tck-kiosk__stage tck-hidden" data-tck-stage="pin">
				<p class="tck-kiosk__prompt"><?php print_unescaped(str_replace('__NAME__', '<strong data-tck-pin-name></strong>', \OCP\Util::sanitizeHTML($l->t('Hi %s — your PIN', ['__NAME__'])))); ?></p>
				<div class="tck-pinpad" data-tck-pinpad role="group" aria-label="<?php p($l->t('PIN pad')); ?>">
					<div class="tck-pinpad__dots" data-tck-dots aria-hidden="true"></div>
					<input type="password" class="tck-sr-only" data-tck-pin-input inputmode="numeric" autocomplete="off" aria-label="<?php p($l->t('PIN')); ?>">
					<div class="tck-pinpad__keys">
						<?php foreach ([1,2,3,4,5,6,7,8,9,'',0,'⌫'] as $k): ?>
							<?php if ($k === ''): ?><span></span><?php else: ?>
								<button type="button" class="tck-pinpad__key" data-tck-key="<?php p((string)$k); ?>"<?php if ($k === '⌫') p(' aria-label="' . $l->t('Delete') . '"'); ?>><?php p((string)$k); ?></button>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				</div>
				<p class="tck-field-error tck-kiosk__pinerr" data-tck-pin-error hidden></p>
				<button type="button" class="tck-btn" data-tck-back><?php p($l->t('Back')); ?></button>
			</div>

			<div class="tck-kiosk__stage tck-hidden" data-tck-stage="confirm">
				<p class="tck-kiosk__prompt tck-kiosk__big" data-tck-confirm-text></p>
			</div>
		</section>
	</main>
</div>
