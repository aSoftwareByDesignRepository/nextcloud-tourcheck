<?php /** @var array $_ @var \OCP\IL10N $l */ $v = (array)($_['settings'] ?? []); ?>
<form class="tck-form" data-tck-settings="evidence" novalidate>
	<div class="tck-field">
		<label for="tck-tsa-url"><?php p($l->t('Timestamp authority (TSA) URL')); ?></label>
		<input id="tck-tsa-url" name="tsa_url" type="url" value="<?php p((string)($v['tsaUrl'] ?? '')); ?>" placeholder="https://…" maxlength="500">
		<p class="tck-field-error" id="tck-tsa-url-error" hidden></p>
		<p class="tck-field-hint"><?php p($l->t('RFC 3161 endpoint that anchors the evidence chain. Leave empty to disable external anchoring.')); ?></p>
	</div>
	<div class="tck-field">
		<label for="tck-tsa-ca"><?php p($l->t('TSA CA bundle path')); ?></label>
		<input id="tck-tsa-ca" name="tsa_ca_bundle" type="text" value="<?php p((string)($v['tsaCaBundle'] ?? '')); ?>" placeholder="/etc/ssl/tsa-ca.pem" maxlength="500" autocomplete="off" spellcheck="false">
		<p class="tck-field-error" id="tck-tsa-ca-error" hidden></p>
		<p class="tck-field-hint"><?php p($l->t('Optional PEM file pinning the TSA certificate chain. Absolute path on the server; leave empty to skip chain verification.')); ?></p>
	</div>
	<div class="tck-field">
		<label for="tck-late-days"><?php p($l->t('Late sync window (days)')); ?></label>
		<input id="tck-late-days" name="late_sync_days" type="number" min="0" max="30" step="1" value="<?php p((string)($v['lateSyncDays'] ?? 7)); ?>">
		<p class="tck-field-error" id="tck-late-days-error" hidden></p>
		<p class="tck-field-hint"><?php p($l->t('Events synced within this window keep their client timestamp flagged as late-synced.')); ?></p>
	</div>
	<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Save')); ?></button>
</form>
