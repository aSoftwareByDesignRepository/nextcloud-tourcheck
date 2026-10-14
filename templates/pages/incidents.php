<?php
/** @var array $_ @var \OCP\IL10N $l */
use OCA\TourCheck\Support\Badge;
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
$pills = [
	'' => $l->t('All'),
	'open' => $l->t('Open'),
	'action' => $l->t('In progress'),
	'verify' => $l->t('To verify'),
	'closed' => $l->t('Closed'),
];
?>
<section aria-labelledby="tck-inc-title">
	<div class="tck-list-head">
		<h2 id="tck-inc-title" class="tck-list-head__title"><?php p($l->t('Incidents')); ?></h2>
		<span class="tck-list-head__count" data-tck-incidents-count role="status"></span>
		<?php if ($sites !== []): ?>
			<span class="tck-list-head__actions">
				<button type="button" class="tck-btn tck-btn--primary" data-tck-new-incident><?php p($l->t('New incident')); ?></button>
			</span>
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
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No incidents recorded')); ?></p>
			<p class="tck-empty-state__text"><?php p($sites === [] ? $l->t('Incidents need a site first.') : $l->t('If something happens on a round, report it here.')); ?></p>
			<?php if ($sites !== []): ?>
				<button type="button" class="tck-btn tck-btn--primary" data-tck-new-incident><?php p($l->t('Report an incident')); ?></button>
			<?php endif; ?>
		</div>
	<?php else: ?>
		<div class="tck-quick-filters">
			<span class="tck-quick-filters__label" id="tck-incidents-filter-label"><?php p($l->t('Show')); ?></span>
			<div class="tck-filter-bar" data-tck-incidents-filter aria-labelledby="tck-incidents-filter-label">
				<?php foreach ($pills as $value => $label): ?>
					<button type="button" class="tck-filter" data-value="<?php p($value); ?>" aria-pressed="<?php p($value === '' ? 'true' : 'false'); ?>">
						<?php p($label); ?>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="tck-empty-state" data-tck-incidents-empty-filtered hidden>
			<p class="tck-empty-state__title"><?php p($l->t('Nothing matches')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Pick another status to see more incidents.')); ?></p>
		</div>
		<ul class="tck-list" data-tck-incidents-list>
			<?php foreach ($incidents as $i): ?>
				<?php $closed = $i['status'] === 'closed'; ?>
				<li class="tck-incident" data-status="<?php p((string)$i['status']); ?>">
					<div class="tck-list__row">
						<span class="tck-list__main">
							<span class="tck-list__title"><?php p((string)$i['title']); ?></span>
							<span class="tck-list__sub"><?php p((string)$i['siteName'] . ' · ' . $i['timeLabel']); ?></span>
						</span>
						<span class="tck-list__aside">
							<?php print_unescaped(Badge::render((string)($sevLabel[$i['severity']] ?? $i['severity']), Badge::incidentSeverity((string)$i['severity']))); ?>
							<?php print_unescaped(Badge::render((string)($statusLabel[$i['status']] ?? $i['status']), Badge::incidentStatus((string)$i['status']))); ?>
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
