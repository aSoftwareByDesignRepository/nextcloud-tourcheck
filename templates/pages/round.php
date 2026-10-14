<?php
/** @var array $_ @var \OCP\IL10N $l */
use OCA\TourCheck\Support\Badge;
$round = (array)($_['round'] ?? []);
$items = (array)($round['items'] ?? []);
$events = (array)($round['events'] ?? []);
$trail = $_['trail'] ?? null;
$statusLabel = [
	'scheduled' => $l->t('Scheduled'), 'due' => $l->t('Due'), 'active' => $l->t('Active'),
	'completed' => $l->t('Done'), 'done' => $l->t('Done'), 'partial' => $l->t('Partial'), 'missed' => $l->t('Missed'),
	'attested' => $l->t('Attested'), 'pending' => $l->t('Pending'), 'ok' => $l->t('Done'),
	'skipped' => $l->t('Skipped'), 'failed' => $l->t('Failed'),
];
?>
<?php if ($round): ?>
<section class="tck-card" aria-labelledby="tck-round-sum">
	<h2 id="tck-round-sum" class="tck-section-title"><?php p($l->t('Summary')); ?></h2>
	<dl class="tck-deflist">
		<div><dt><?php p($l->t('Site')); ?></dt><dd><?php p((string)$round['siteName']); ?></dd></div>
		<div><dt><?php p($l->t('Window')); ?></dt><dd><?php p((string)$round['windowStartLabel'] . ' – ' . $round['windowEndLabel']); ?></dd></div>
		<div><dt><?php p($l->t('Status')); ?></dt><dd><?php print_unescaped(Badge::render((string)($statusLabel[$round['status']] ?? $round['status']), Badge::roundStatus((string)$round['status']))); ?></dd></div>
		<?php if (!empty($round['skipReason'])): ?>
			<div><dt><?php p($l->t('Skip reason')); ?></dt><dd><?php p((string)$round['skipReason']); ?></dd></div>
		<?php endif; ?>
		<div><dt><?php p($l->t('Progress')); ?></dt><dd><?php p((string)$round['doneCount'] . ' / ' . $round['totalCount']); ?></dd></div>
	</dl>
	<?php if (!empty($round['canAttest']) && in_array($round['status'], ['done', 'partial', 'completed'], true)): ?>
		<button type="button" class="tck-btn tck-btn--primary" data-tck-attest="<?php p((string)$round['id']); ?>"><?php p($l->t('Attest round')); ?></button>
	<?php endif; ?>
	<?php if (!empty($round['canSupervise']) && in_array($round['status'], ['scheduled', 'active'], true)): ?>
		<button type="button" class="tck-btn tck-btn--danger" data-tck-show="tck-skip-form"><?php p($l->t('Skip round')); ?></button>
		<form class="tck-form tck-hidden" id="tck-skip-form" data-tck-skip-form data-round-id="<?php p((string)$round['id']); ?>" novalidate>
			<div class="tck-field">
				<label for="tck-skip-reason"><?php p($l->t('Skip reason')); ?></label>
				<input id="tck-skip-reason" name="reason" type="text" maxlength="500" placeholder="<?php p($l->t('e.g. holiday, site closed')); ?>">
				<p class="tck-field-error" id="tck-skip-reason-error" hidden></p>
			</div>
			<div class="tck-form-actions">
				<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Skip round')); ?></button>
				<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	<?php endif; ?>
</section>

<section aria-labelledby="tck-items-title">
	<div class="tck-list-head">
		<h2 id="tck-items-title" class="tck-list-head__title"><?php p($l->t('Checkpoints')); ?></h2>
		<span class="tck-list-head__count"><?php p($l->t('%1$d of %2$d done', [(int)$round['doneCount'], (int)$round['totalCount']])); ?></span>
	</div>
	<ol class="tck-list tck-list--ordered">
		<?php foreach ($items as $item): ?>
			<li class="tck-list__row">
				<span class="tck-list__main">
					<span class="tck-list__title"><?php p((string)$item['checkpointName']); ?></span>
					<?php if (!empty($item['eventLabel'])): ?>
						<span class="tck-list__sub"><?php p((string)$item['eventLabel']); ?></span>
					<?php endif; ?>
				</span>
				<?php print_unescaped(Badge::render((string)($statusLabel[$item['status']] ?? $item['status']), Badge::roundStatus((string)$item['status']))); ?>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
<?php if ($trail !== null): ?>
<section aria-labelledby="tck-trail-title">
	<h2 id="tck-trail-title" class="tck-section-title"><?php p($l->t('Live position trail')); ?></h2>
	<?php if ($trail === []): ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No positions recorded')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Live positions appear here while a round is active — only if the site opted in.')); ?></p>
		</div>
	<?php else: ?>
		<div class="tck-table-wrap" tabindex="0" role="region" aria-label="<?php p($l->t('Live position trail')); ?>">
			<table class="tck-table tck-table--cards">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Time')); ?></th>
						<th scope="col"><?php p($l->t('Who')); ?></th>
						<th scope="col"><?php p($l->t('Latitude')); ?></th>
						<th scope="col"><?php p($l->t('Longitude')); ?></th>
						<th scope="col"><?php p($l->t('Accuracy')); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($trail as $p): ?>
						<tr>
							<td data-cell="<?php p($l->t('Time')); ?>"><?php p((string)$p['timeLabel']); ?></td>
							<td data-cell="<?php p($l->t('Who')); ?>"><?php p((string)$p['actor']); ?></td>
							<td data-cell="<?php p($l->t('Latitude')); ?>"><?php p(number_format((float)$p['lat'], 5)); ?></td>
							<td data-cell="<?php p($l->t('Longitude')); ?>"><?php p(number_format((float)$p['lon'], 5)); ?></td>
							<td data-cell="<?php p($l->t('Accuracy')); ?>"><?php p($p['accuracy'] === null ? '—' : $l->t('±%d m', [(int)$p['accuracy']])); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
