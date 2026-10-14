<?php /** @var array $_ @var \OCP\IL10N $l */ $stations = (array)($_['stations'] ?? []); $v = (array)($_['settings'] ?? []); $siteCps = (array)($_['siteCheckpoints'] ?? []); ?>
<?php $siteOptions = (array)($_['siteOptions'] ?? []); ?>
<section aria-labelledby="tck-st-title">
	<div class="tck-section-head">
		<h2 id="tck-st-title" class="tck-section-title"><?php p($l->t('Stations')); ?></h2>
		<?php if ($siteOptions !== []): ?>
			<button type="button" class="tck-btn tck-btn--primary" data-tck-new-station><?php p($l->t('Add station')); ?></button>
		<?php endif; ?>
	</div>
	<?php if ($siteOptions !== []): ?>
		<form class="tck-form tck-hidden" id="tck-station-form" data-tck-station-form<?php if ($stations === []) p(' data-tck-auto-open'); ?> novalidate>
			<div class="tck-field">
				<label for="tck-station-name"><?php p($l->t('Station name')); ?></label>
				<input id="tck-station-name" name="label" type="text" required maxlength="190" autocomplete="off" placeholder="<?php p($l->t('e.g. Lobby kiosk, Warehouse tablet')); ?>">
				<p class="tck-field-error" id="tck-station-name-error" data-tck-err="label" hidden></p>
			</div>
			<div class="tck-field">
				<label for="tck-station-site"><?php p($l->t('Site')); ?></label>
				<select id="tck-station-site" name="site_id" class="tck-select" required>
					<?php foreach ($siteOptions as $so): ?>
						<option value="<?php p((string)$so['id']); ?>"><?php p((string)$so['name']); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="tck-form-actions">
				<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Create station')); ?></button>
				<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	<?php endif; ?>
	<?php if ($stations === []): ?>
		<p class="tck-empty"><?php p($l->t('No stations enrolled. Pair a kiosk device from the companion app.')); ?></p>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($stations as $st): ?>
				<li class="tck-list__row">
					<span class="tck-list__main">
						<span class="tck-list__title"><?php p((string)$st['name']); ?></span>
						<span class="tck-list__sub"><?php p((string)$st['siteName'] . ' · ' . $l->t('last seen') . ' ' . (string)$st['lastSeenLabel']); ?></span>
					</span>
					<span class="tck-list__aside">
						<label class="tck-sr-only" for="tck-bind-<?php p((string)$st['id']); ?>"><?php p($l->t('Checkpoint')); ?></label>
						<select id="tck-bind-<?php p((string)$st['id']); ?>" class="tck-select" data-tck-bind-station="<?php p((string)$st['id']); ?>" data-site-id="<?php p((string)$st['siteId']); ?>">
							<option value=""><?php p($l->t('No checkpoint bound')); ?></option>
							<?php foreach ((array)($siteCps[(int)$st['siteId']] ?? []) as $cp): ?>
								<option value="<?php p((string)$cp['id']); ?>" <?php if ((int)($st['checkpointId'] ?? 0) === (int)$cp['id']): ?>selected<?php endif; ?>><?php p((string)$cp['name']); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="tck-btn" data-tck-pair-station="<?php p((string)$st['id']); ?>"><?php p($l->t('Pair device')); ?></button>
						<button type="button" class="tck-btn tck-btn--danger" data-tck-revoke-station="<?php p((string)$st['id']); ?>"><?php p($l->t('Revoke')); ?></button>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<div class="tck-notice tck-hidden" id="tck-pair-card" role="status" aria-live="polite">
		<p><strong><?php p($l->t('Scan this code on the kiosk device — valid for 10 minutes, single use.')); ?></strong></p>
		<div id="tck-pair-qr" class="tck-pair-qr" aria-label="<?php p($l->t('Pairing QR code')); ?>"></div>
		<div class="tck-copy-row">
			<code id="tck-pair-code" class="tck-copy-row__text"></code>
			<button type="button" class="tck-btn" id="tck-pair-copy"><?php p($l->t('Copy code')); ?></button>
			<button type="button" class="tck-btn" id="tck-pair-print"><?php p($l->t('Print card')); ?></button>
		</div>
	</div>
</section>

<form class="tck-form" data-tck-settings="stations" novalidate>
	<h3 class="tck-section-title"><?php p($l->t('PIN policy')); ?></h3>
	<div class="tck-field">
		<label for="tck-pin-len"><?php p($l->t('PIN length')); ?></label>
		<input id="tck-pin-len" name="pin_length" type="number" min="4" max="8" step="1" value="<?php p((string)($v['pinLength'] ?? 6)); ?>">
		<p class="tck-field-error" id="tck-pin-len-error" hidden></p>
	</div>
	<div class="tck-field">
		<label for="tck-pin-att"><?php p($l->t('Lock after failed attempts')); ?></label>
		<input id="tck-pin-att" name="pin_lockout_attempts" type="number" min="3" max="10" step="1" value="<?php p((string)($v['pinLockoutAttempts'] ?? 5)); ?>">
		<p class="tck-field-error" id="tck-pin-att-error" hidden></p>
	</div>
	<div class="tck-field">
		<label for="tck-pin-min"><?php p($l->t('Lockout minutes')); ?></label>
		<input id="tck-pin-min" name="pin_lockout_minutes" type="number" min="1" max="120" step="1" value="<?php p((string)($v['pinLockoutMinutes'] ?? 15)); ?>">
		<p class="tck-field-error" id="tck-pin-min-error" hidden></p>
	</div>
	<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Save')); ?></button>
</form>
