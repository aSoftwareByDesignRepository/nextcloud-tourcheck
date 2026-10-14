<?php
/** @var array $_ @var \OCP\IL10N $l */
use OCA\TourCheck\Support\Badge;
$entries = (array)($_['entries'] ?? []);
$methodLabel = [
	'scan_qr' => $l->t('QR scan'), 'scan_nfc' => $l->t('NFC scan'), 'manual' => $l->t('Manual'),
	'kiosk_pin' => $l->t('Kiosk PIN'), 'attestation' => $l->t('Attestation'), 'admin' => $l->t('Admin change'),
	'import' => $l->t('Import'), 'system' => $l->t('System'),
];
$anchorStatus = $_['anchorStatus'] ?? null;
$anchorLabel = match ($anchorStatus) {
	'anchored' => $l->t('anchored'),
	'pending' => $l->t('pending'),
	'unanchored' => $l->t('not yet anchored'),
	default => $l->t('none yet'),
};
$methods = [];
foreach ($entries as $e) {
	$m = (string)$e['method'];
	$methods[$m] = $methodLabel[$m] ?? $m;
}
?>
<section aria-labelledby="tck-journal-title">
	<div class="tck-list-head">
		<h2 id="tck-journal-title" class="tck-list-head__title"><?php p($l->t('Journal')); ?></h2>
		<span class="tck-list-head__count" data-tck-journal-count role="status"></span>
		<div class="tck-list-head__actions">
			<span class="tck-badge tck-badge--info" title="<?php p($l->t('Hash-chained and anchored')); ?>"><span class="tck-badge__dot" aria-hidden="true"></span><?php p($l->t('Chained')); ?></span>
			<?php print_unescaped(Badge::render($l->t('Anchor: %s', [$anchorLabel]), '')); ?>
			<?php if (!empty($_['canManage']) && (int)($_['siteId'] ?? 0) > 0): ?>
				<button type="button" class="tck-btn" data-tck-anchor-now data-site-id="<?php p((int)$_['siteId']); ?>"><?php p($l->t('Anchor now')); ?></button>
			<?php endif; ?>
		</div>
	</div>
	<?php if ($entries === []): ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No journal entries yet')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Every scan, change and report lands here — hash-chained and timestamped.')); ?></p>
		</div>
	<?php else: ?>
		<section class="tck-filter-panel" aria-labelledby="tck-journal-filter-title">
			<header class="tck-filter-panel__head">
				<h2 id="tck-journal-filter-title"><?php p($l->t('Filter entries')); ?></h2>
				<p class="tck-filter-panel__intro"><?php p($l->t('Narrow the record — changes apply as you type.')); ?></p>
			</header>
			<div class="tck-filter-panel__body">
				<form class="tck-filter-panel__form" role="search" data-tck-journal-filter novalidate
					aria-label="<?php p($l->t('Filter journal entries')); ?>">
					<div class="tck-filter-grid">
						<div class="tck-filter-field">
							<label class="tck-filter-field__label" for="tck-jf-q"><?php p($l->t('Search')); ?></label>
							<div class="tck-filter-field__control">
								<input id="tck-jf-q" name="q" type="search" class="tck-select"
									placeholder="<?php p($l->t('What, who, when…')); ?>" autocomplete="off">
							</div>
						</div>
						<div class="tck-filter-field">
							<label class="tck-filter-field__label" for="tck-jf-method"><?php p($l->t('How')); ?></label>
							<div class="tck-filter-field__control">
								<select id="tck-jf-method" name="method" class="tck-select">
									<option value=""><?php p($l->t('All methods')); ?></option>
									<?php foreach ($methods as $m => $mLabel): ?>
										<option value="<?php p($m); ?>"><?php p($mLabel); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="tck-filter-field tck-filter-field--actions">
							<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Apply')); ?></button>
							<button type="reset" class="tck-btn"><?php p($l->t('Clear')); ?></button>
						</div>
					</div>
				</form>
			</div>
		</section>
		<div class="tck-empty-state" data-tck-journal-empty-filtered hidden>
			<p class="tck-empty-state__title"><?php p($l->t('Nothing matches')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Clear the filter to see all entries again.')); ?></p>
		</div>
		<div class="tck-table-wrap" tabindex="0" role="region" aria-label="<?php p($l->t('Journal entries')); ?>" data-tck-journal-table>
			<table class="tck-table tck-table--cards">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Time')); ?></th>
						<th scope="col"><?php p($l->t('What')); ?></th>
						<th scope="col"><?php p($l->t('Who')); ?></th>
						<th scope="col"><?php p($l->t('How')); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($entries as $e): ?>
						<tr<?php if (!empty($e['flag'])) p(' class="tck-row--flag"'); ?>
							data-method="<?php p((string)$e['method']); ?>"
							data-search="<?php p(strtolower((string)$e['what'] . ' ' . (string)$e['actor'] . ' ' . (string)$e['timeLabel'])); ?>">
							<td data-cell="<?php p($l->t('Time')); ?>"><?php p((string)$e['timeLabel']); ?></td>
							<td data-cell="<?php p($l->t('What')); ?>"><?php p((string)$e['what']); ?></td>
							<td data-cell="<?php p($l->t('Who')); ?>"><?php p((string)$e['actor']); ?></td>
							<td data-cell="<?php p($l->t('How')); ?>">
								<?php p($methodLabel[$e['method']] ?? $e['method']); ?>
								<?php if (!empty($e['flag'])): ?>
									<?php print_unescaped(Badge::render((string)$e['flag'], 'warning')); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
