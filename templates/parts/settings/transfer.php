<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="tck-card tck-section" aria-labelledby="tck-xfer-export-h" id="tck-transfer-export-card">
	<header class="tck-section__header">
		<div>
			<h2 id="tck-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="tck-section__sub"><?php p($l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="tck-callout tck-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="tck-transfer-export-form" class="tck-form" novalidate>
		<div class="tck-form-row">
			<label for="tck-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="tck-input-group">
				<input type="password" id="tck-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="tck-transfer-passphrase-hint tck-transfer-passphrase-strength">
				<button type="button" class="tck-btn tck-btn--ghost" id="tck-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="tck-btn tck-btn--secondary" id="tck-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="tck-form-row__hint" id="tck-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="tck-form-row__hint tck-transfer-strength" id="tck-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="tck-actions">
			<button type="submit" class="tck-btn tck-btn--primary" id="tck-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="tck-card tck-section" aria-labelledby="tck-xfer-import-h" id="tck-transfer-import-card">
	<header class="tck-section__header">
		<div>
			<h2 id="tck-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="tck-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="tck-transfer-last" id="tck-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php
			$transferMode = (string)($lastReceipt['mode'] ?? '');
			if ($transferMode === 'merge') {
				p($l->t('Merge'));
			} elseif ($transferMode === 'replace') {
				p($l->t('Replace'));
			} else {
				p($transferMode !== '' ? $transferMode : '—');
			}
		?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="tck-transfer-import-form" class="tck-form" novalidate>
		<fieldset class="tck-form-row tck-fieldset">
			<legend><?php p($l->t('Mode')); ?></legend>
			<label class="tck-radio">
				<input type="radio" name="mode" value="merge" checked>
				<span><strong><?php p($l->t('Merge')); ?></strong> — <?php p($l->t('add and update; nothing is deleted')); ?></span>
			</label>
			<label class="tck-radio tck-radio--danger">
				<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
				<span><strong><?php p($l->t('Replace')); ?></strong> — <?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
			</label>
			<p class="tck-form-row__hint" id="tck-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="tck-form-row">
			<label for="tck-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="tck-transfer-file" name="file" accept=".nctransfer,application/json" class="tck-file-input">
		</div>
		<div class="tck-form-row">
			<label for="tck-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="tck-input-group">
				<input type="password" id="tck-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="tck-btn tck-btn--ghost" id="tck-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="tck-transfer-import-status" class="tck-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="tck-transfer-report" class="tck-dialog tck-transfer-report" aria-labelledby="tck-transfer-report-h">
	<div class="tck-dialog__body">
		<h3 id="tck-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="tck-transfer-report-verdict" class="tck-transfer-report__verdict"></p>
		<p id="tck-transfer-report-file" class="tck-form-row__hint"></p>
		<div id="tck-transfer-report-groups"></div>
	</div>
	<footer class="tck-dialog__footer">
		<div id="tck-transfer-replace-confirm" class="tck-form-row" hidden>
			<label for="tck-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="tck-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="tck-actions">
			<button type="button" class="tck-btn tck-btn--secondary" id="tck-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="tck-btn tck-btn--primary" id="tck-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="tck-transfer-progress" class="tck-dialog" aria-labelledby="tck-transfer-progress-h" aria-live="polite">
	<div class="tck-dialog__body">
		<h3 id="tck-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="tck-transfer-progress-bar" max="100" value="0"></progress>
		<p id="tck-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="tck-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="tck-transfer-result-live" class="tck-visually-hidden" role="status" aria-live="polite"></div>
