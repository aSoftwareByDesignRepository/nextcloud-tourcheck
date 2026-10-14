<?php /** @var array $_ @var \OCP\IL10N $l */ $v = (array)($_['settings'] ?? []); ?>
<form class="tck-form" data-tck-settings="branding" novalidate>
	<div class="tck-field">
		<label for="tck-brand-name"><?php p($l->t('Company name on reports')); ?></label>
		<input id="tck-brand-name" name="brand_name" type="text" value="<?php p((string)($v['brandName'] ?? '')); ?>" maxlength="120">
	</div>
	<div class="tck-field">
		<label for="tck-brand-color"><?php p($l->t('Accent color')); ?></label>
		<input id="tck-brand-color" name="brand_color" type="text" value="<?php p((string)($v['brandColor'] ?? '')); ?>" maxlength="32" placeholder="#1a7f37">
		<p class="tck-field-error" id="tck-brand-color-error" hidden></p>
	</div>
	<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Save')); ?></button>
</form>
