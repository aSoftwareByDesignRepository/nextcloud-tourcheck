<?php /** @var array $_ @var \OCP\IL10N $l */ $lic = (array)($_['license'] ?? []); ?>
<section aria-labelledby="tck-lic-title">
	<h2 id="tck-lic-title" class="tck-section-title"><?php p($l->t('Companion license')); ?></h2>
	<p class="tck-field-hint"><?php p($l->t('The web app is free (AGPL). A license unlocks the native companion and station devices.')); ?></p>
	<?php if (!empty($lic['active'])): ?>
		<dl class="tck-deflist">
			<div><dt><?php p($l->t('Customer')); ?></dt><dd><?php p((string)$lic['customerId'] ?? ''); ?></dd></div>
			<div><dt><?php p($l->t('Valid until')); ?></dt><dd><?php p((string)$lic['validUntil'] ?? '—'); ?></dd></div>
			<div><dt><?php p($l->t('Mobile seats')); ?></dt><dd><?php p((string)$lic['mobileSeats'] ?? 0); ?></dd></div>
			<div><dt><?php p($l->t('Stations')); ?></dt><dd><?php p((string)$lic['stations'] ?? 0); ?></dd></div>
		</dl>
		<button type="button" class="tck-btn tck-btn--danger" data-tck-clear-license><?php p($l->t('Remove license')); ?></button>
	<?php else: ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No license installed')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Paste the key below to unlock companion and station devices.')); ?></p>
		</div>
	<?php endif; ?>
	<form class="tck-form" data-tck-license novalidate>
		<div class="tck-field">
			<label for="tck-lic-key"><?php p($l->t('License key (TCK2…)')); ?></label>
			<input id="tck-lic-key" name="license" type="text" autocomplete="off" spellcheck="false" placeholder="TCK2.…">
			<p class="tck-field-error" id="tck-lic-key-error" hidden></p>
		</div>
		<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Apply license')); ?></button>
	</form>
</section>
