<?php /** @var array $_ @var \OCP\IL10N $l */ $v = (array)($_['settings'] ?? []); ?>
<form class="tck-form" data-tck-settings="retention" novalidate>
	<div class="tck-field">
		<label for="tck-ret-j"><?php p($l->t('Keep journal & evidence (years)')); ?></label>
		<input id="tck-ret-j" name="journal_retention_years" type="number" min="1" max="30" step="1" value="<?php p((string)($v['journalRetentionYears'] ?? 10)); ?>">
		<p class="tck-field-error" id="tck-ret-j-error" hidden></p>
	</div>
	<div class="tck-field">
		<label for="tck-ret-pos"><?php p($l->t('Keep GPS positions (hours)')); ?></label>
		<input id="tck-ret-pos" name="position_retention_hours" type="number" min="0" step="1" value="<?php p((string)($v['positionRetentionHours'] ?? 2160)); ?>">
		<p class="tck-field-error" id="tck-ret-pos-error" hidden></p>
		<p class="tck-field-hint"><?php p($l->t('GPS breadcrumbs expire fast — works-council friendly default is 90 days.')); ?></p>
	</div>
	<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Save')); ?></button>
</form>
