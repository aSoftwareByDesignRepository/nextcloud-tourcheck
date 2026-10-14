<?php
/** @var array $_ @var \OCP\IL10N $l */
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
?>
<section aria-labelledby="tck-journal-title">
	<div class="tck-section-head">
		<h2 id="tck-journal-title" class="tck-section-title"><?php p($l->t('Journal')); ?></h2>
		<div class="tck-btn-row">
			<span class="tck-badge tck-badge--anchor" title="<?php p($l->t('Hash-chained and anchored')); ?>"><?php p($l->t('Chained')); ?></span>
			<span class="tck-badge"><?php p($l->t('Anchor: %s', [$anchorLabel])); ?></span>
			<?php if (!empty($_['canManage']) && (int)($_['siteId'] ?? 0) > 0): ?>
				<button type="button" class="tck-btn" data-tck-anchor-now data-site-id="<?php p((int)$_['siteId']); ?>"><?php p($l->t('Anchor now')); ?></button>
			<?php endif; ?>
		</div>
	</div>
	<?php if ($entries === []): ?>
		<p class="tck-empty"><?php p($l->t('No journal entries yet.')); ?></p>
	<?php else: ?>
		<div class="tck-tablewrap" tabindex="0" role="region" aria-label="<?php p($l->t('Journal')); ?>">
			<table class="tck-table">
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
						<tr<?php if (!empty($e['flag'])) p(' class="tck-row--flag"'); ?>>
							<td><?php p((string)$e['timeLabel']); ?></td>
							<td><?php p((string)$e['what']); ?></td>
							<td><?php p((string)$e['actor']); ?></td>
							<td>
								<?php p($methodLabel[$e['method']] ?? $e['method']); ?>
								<?php if (!empty($e['flag'])): ?>
									<span class="tck-badge tck-badge--warn"><?php p((string)$e['flag']); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
