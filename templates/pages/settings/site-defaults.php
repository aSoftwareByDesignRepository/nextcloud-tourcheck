<?php /** @var array $_ @var \OCP\IL10N $l */ $v = (array)($_['settings'] ?? []); ?>
<form class="tck-form" data-tck-settings="site" novalidate>
	<div class="tck-field">
		<label for="tck-def-tz"><?php p($l->t('Default timezone')); ?></label>
		<input id="tck-def-tz" name="default_timezone" type="text" value="<?php p((string)($v['defaultTimezone'] ?? 'Europe/Berlin')); ?>" maxlength="64">
		<p class="tck-field-hint"><?php p($l->t('Used for new sites and scheduling.')); ?></p>
		<p class="tck-field-error" id="tck-def-tz-error" hidden></p>
	</div>
	<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Save')); ?></button>
</form>
