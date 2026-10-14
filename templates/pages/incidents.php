<?php
/** @var array $_ @var \OCP\IL10N $l */
$incidents = (array)($_['incidents'] ?? []);
$sites = (array)($_['sites'] ?? []);
$uid = (string)($_['uid'] ?? '');
$sevLabel = ['low' => $l->t('Low'), 'medium' => $l->t('Medium'), 'high' => $l->t('High'), 'critical' => $l->t('Critical')];
$catLabel = [
	'security' => $l->t('Security'), 'damage' => $l->t('Damage'), 'safety' => $l->t('Safety'),
	'maintenance' => $l->t('Maintenance'), 'customer' => $l->t('Customer'), 'other' => $l->t('Other'),
];
$statusLabel = ['open' => $l->t('Open'), 'action' => $l->t('In progress'), 'verify' => $l->t('To verify'), 'closed' => $l->t('Closed')];
$corrStatusLabel = ['open' => $l->t('Open'), 'done' => $l->t('Done'), 'verified' => $l->t('Verified')];
?>
<section aria-labelledby="tck-inc-title">
	<div class="tck-section-head">
		<h2 id="tck-inc-title" class="tck-section-title"><?php p($l->t('Incidents')); ?></h2>
		<?php if ($sites !== []): ?>
			<button type="button" class="tck-btn tck-btn--primary" data-tck-new-incident><?php p($l->t('New incident')); ?></button>
		<?php endif; ?>
	</div>
	<?php if ($sites !== []): ?>
		<form class="tck-form tck-hidden" id="tck-incident-form" data-tck-incident-form novalidate>
			<div class="tck-field">
				<label for="tck-inc-form-title"><?php p($l->t('Title')); ?></label>
				<input id="tck-inc-form-title" name="title" type="text" required maxlength="400" autocomplete="off">
				<p class="tck-field-error" id="tck-inc-form-title-error" hidden></p>
			</div>
			<div class="tck-field">
				<label for="tck-inc-site"><?php p($l->t('Site')); ?></label>
				<select id="tck-inc-site" name="site_id" class="tck-select">
					<?php foreach ($sites as $s): ?>
						<option value="<?php p($s->getId()); ?>"<?php if ((int)$s->getId() === (int)($_['currentSiteId'] ?? 0)) p(' selected'); ?>><?php p($s->getName()); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="tck-field">
				<label for="tck-inc-cat"><?php p($l->t('Category')); ?></label>
				<select id="tck-inc-cat" name="category" class="tck-select">
					<?php foreach ($catLabel as $c => $cLabel): ?>
						<option value="<?php p($c); ?>"><?php p($cLabel); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="tck-field">
				<label for="tck-inc-sev"><?php p($l->t('Severity')); ?></label>
				<select id="tck-inc-sev" name="severity" class="tck-select">
					<?php foreach ($sevLabel as $sev => $sevL): ?>
						<option value="<?php p($sev); ?>"<?php if ($sev === 'medium') p(' selected'); ?>><?php p($sevL); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="tck-field">
				<label for="tck-inc-note"><?php p($l->t('Note (optional)')); ?></label>
				<textarea id="tck-inc-note" name="note" class="tck-select" rows="3" maxlength="8000"></textarea>
			</div>
			<div class="tck-form-actions">
				<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Report incident')); ?></button>
				<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	<?php endif; ?>
	<?php if ($incidents === []): ?>
		<p class="tck-empty"><?php p($sites === [] ? $l->t('No incidents recorded.') : $l->t('No incidents recorded. If something happens on a round, report it here.')); ?></p>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($incidents as $i): ?>
				<?php $closed = $i['status'] === 'closed'; ?>
				<li class="tck-incident">
					<div class="tck-list__row">
						<span class="tck-list__main">
							<span class="tck-list__title"><?php p((string)$i['title']); ?></span>
							<span class="tck-list__sub"><?php p((string)$i['siteName'] . ' · ' . $i['timeLabel']); ?></span>
						</span>
						<span class="tck-list__aside">
							<span class="tck-badge tck-badge--sev-<?php p((string)$i['severity']); ?>"><?php p($sevLabel[$i['severity']] ?? $i['severity']); ?></span>
							<span class="tck-badge"><?php p($statusLabel[$i['status']] ?? $i['status']); ?></span>
							<button type="button" class="tck-btn" data-tck-incident-toggle
								aria-expanded="false" aria-controls="tck-inc-detail-<?php p((string)$i['id']); ?>">
								<?php p($l->t('Details')); ?>
							</button>
							<?php if (!$closed && !empty($i['canSupervise'])): ?>
								<button type="button" class="tck-btn tck-btn--danger" data-tck-close-incident="<?php p((string)$i['id']); ?>" data-revision="<?php p((string)$i['revision']); ?>"><?php p($l->t('Close')); ?></button>
							<?php endif; ?>
						</span>
					</div>
					<div id="tck-inc-detail-<?php p((string)$i['id']); ?>" class="tck-incident__detail" hidden
						data-tck-incident-detail="<?php p((string)$i['id']); ?>"
						data-status="<?php p((string)$i['status']); ?>"
						data-uid="<?php p($uid); ?>"
						data-supervise="<?php p(!empty($i['canSupervise']) ? '1' : '0'); ?>">
						<?php if ((string)($i['note'] ?? '') !== ''): ?>
							<p class="tck-incident__note"><?php p((string)$i['note']); ?></p>
						<?php endif; ?>
						<h3 class="tck-incident__sub-title"><?php p($l->t('Corrective actions')); ?></h3>
						<div data-tck-corr-list>
							<p class="tck-hint"><?php p($l->t('Loading…')); ?></p>
						</div>
						<?php if (!$closed && !empty($i['canSupervise']) && in_array($i['status'], ['open', 'action'], true)): ?>
							<form class="tck-form tck-form--compact" data-tck-corr-form="<?php p((string)$i['id']); ?>" novalidate>
								<div class="tck-field">
									<label for="tck-corr-text-<?php p((string)$i['id']); ?>"><?php p($l->t('What must be done')); ?></label>
									<input id="tck-corr-text-<?php p((string)$i['id']); ?>" name="text" type="text" required maxlength="1000" autocomplete="off">
									<p class="tck-field-error" id="tck-corr-text-<?php p((string)$i['id']); ?>-error" data-tck-err="text" hidden></p>
								</div>
								<div class="tck-field">
									<label for="tck-corr-uid-<?php p((string)$i['id']); ?>"><?php p($l->t('Assign to (optional)')); ?></label>
									<input id="tck-corr-uid-<?php p((string)$i['id']); ?>" name="assignee_uid" type="text" maxlength="64" autocomplete="off"
										placeholder="<?php p($l->t('Nextcloud user id')); ?>" data-tck-uid-picker>
								</div>
								<div class="tck-field">
									<label for="tck-corr-due-<?php p((string)$i['id']); ?>"><?php p($l->t('Due (optional)')); ?></label>
									<input id="tck-corr-due-<?php p((string)$i['id']); ?>" name="due_at" type="date">
									<p class="tck-field-error" id="tck-corr-due-<?php p((string)$i['id']); ?>-error" data-tck-err="due_at" hidden></p>
								</div>
								<div class="tck-form-actions">
									<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Add action')); ?></button>
								</div>
							</form>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
