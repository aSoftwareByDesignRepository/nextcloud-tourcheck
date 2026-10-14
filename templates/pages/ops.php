<?php
/** @var array $_ @var \OCP\IL10N $l */
$siteId = (int)($_['currentSiteId'] ?? 0);
$canManage = !empty($_['canManage']);
?>
<nav class="tck-chipbar" aria-label="<?php p($l->t('On this page')); ?>">
	<a class="tck-chipbar__chip" href="#tck-sec-alerts"><?php p($l->t('Alerts')); ?></a>
	<a class="tck-chipbar__chip" href="#tck-sec-board"><?php p($l->t('Board')); ?></a>
	<a class="tck-chipbar__chip" href="#tck-sec-map"><?php p($l->t('Map')); ?></a>
	<a class="tck-chipbar__chip" href="#tck-sec-tasks"><?php p($l->t('Tasks')); ?></a>
	<a class="tck-chipbar__chip" href="#tck-sec-logbook"><?php p($l->t('Logbook')); ?></a>
	<a class="tck-chipbar__chip" href="#tck-sec-kpis"><?php p($l->t('KPIs')); ?></a>
</nav>

<section id="tck-sec-alerts" aria-labelledby="tck-alerts-title">
	<div class="tck-list-head">
		<h2 id="tck-alerts-title" class="tck-list-head__title"><?php p($l->t('Open alerts')); ?></h2>
		<span class="tck-list-head__count" id="tck-alert-count"></span>
	</div>
	<div id="tck-alerts" class="tck-ops-alerts" data-site-id="<?php p($siteId); ?>">
		<p class="tck-empty-state__text" id="tck-alerts-empty" hidden><?php p($l->t('No open alerts — everything is fine.')); ?></p>
	</div>
</section>

<section id="tck-sec-board" aria-labelledby="tck-board-title">
	<div class="tck-list-head">
		<h2 id="tck-board-title" class="tck-list-head__title"><?php p($l->t('Site board')); ?></h2>
	</div>
	<div id="tck-board" class="tck-ops-board" aria-live="polite">
		<p class="tck-empty-state__text" id="tck-board-empty" hidden><?php p($l->t('No sites to supervise.')); ?></p>
	</div>
</section>

<section id="tck-sec-map" aria-labelledby="tck-map-title">
	<div class="tck-list-head">
		<h2 id="tck-map-title" class="tck-list-head__title"><?php p($l->t('Live map')); ?></h2>
		<div class="tck-list-head__actions">
			<select id="tck-map-round" class="tck-select" aria-label="<?php p($l->t('Round for trail replay')); ?>" hidden></select>
			<button type="button" class="tck-btn" id="tck-trail-play" hidden><?php p($l->t('Replay trail')); ?></button>
			<?php if ($canManage): ?>
				<button type="button" class="tck-btn" id="tck-gf-new"><?php p($l->t('New geofence')); ?></button>
			<?php endif; ?>
		</div>
	</div>
	<div id="tck-map" class="tck-map" role="application" aria-label="<?php p($l->t('Site map')); ?>"></div>
	<p class="tck-hint" id="tck-map-schematic" hidden>
		<?php p($l->t('Schematic view — no map tiles configured. An admin can enable a tile provider in Settings → Integrations.')); ?>
	</p>
	<div id="tck-gf-list" class="tck-ops-gflist"></div>
	<form class="tck-form tck-hidden" id="tck-gf-form" data-site-id="<?php p($siteId); ?>" novalidate>
		<div class="tck-field">
			<label for="tck-gf-kind"><?php p($l->t('Geofence type')); ?></label>
			<select id="tck-gf-kind" name="kind" class="tck-select">
				<option value="perimeter"><?php p($l->t('Site perimeter')); ?></option>
				<option value="restricted"><?php p($l->t('Restricted zone')); ?></option>
			</select>
		</div>
		<div class="tck-field">
			<label for="tck-gf-lat"><?php p($l->t('Center latitude')); ?></label>
			<input id="tck-gf-lat" name="center_lat" type="number" step="any" min="-90" max="90" required>
			<p class="tck-field-error" id="tck-gf-lat-error" data-tck-err="center" hidden></p>
		</div>
		<div class="tck-field">
			<label for="tck-gf-lon"><?php p($l->t('Center longitude')); ?></label>
			<input id="tck-gf-lon" name="center_lon" type="number" step="any" min="-180" max="180" required>
		</div>
		<div class="tck-field">
			<label for="tck-gf-radius"><?php p($l->t('Radius (m)')); ?></label>
			<input id="tck-gf-radius" name="radius_m" type="number" min="10" max="100000" value="200" required>
			<p class="tck-field-error" id="tck-gf-radius-error" data-tck-err="radiusM" hidden></p>
		</div>
		<p class="tck-hint"><?php p($l->t('Tip: click on the map to set the center.')); ?></p>
		<div class="tck-form-actions">
			<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Save geofence')); ?></button>
			<button type="button" class="tck-btn" data-tck-cancel="tck-gf-form"><?php p($l->t('Cancel')); ?></button>
		</div>
	</form>
</section>

<section id="tck-sec-tasks" aria-labelledby="tck-tasks-title">
	<div class="tck-list-head">
		<h2 id="tck-tasks-title" class="tck-list-head__title"><?php p($l->t('Tasks')); ?></h2>
		<div class="tck-list-head__actions">
			<button type="button" class="tck-btn tck-btn--primary" id="tck-task-new"><?php p($l->t('New task')); ?></button>
		</div>
	</div>
	<ul class="tck-list" id="tck-tasks" data-site-id="<?php p($siteId); ?>"></ul>
	<p class="tck-empty-state__text" id="tck-tasks-empty" hidden><?php p($l->t('No tasks for this site.')); ?></p>
	<form class="tck-form tck-hidden" id="tck-task-form" data-site-id="<?php p($siteId); ?>" novalidate>
		<div class="tck-field">
			<label for="tck-task-title"><?php p($l->t('Title')); ?></label>
			<input id="tck-task-title" name="title" type="text" required maxlength="190">
			<p class="tck-field-error" id="tck-task-title-error" hidden></p>
		</div>
		<div class="tck-field">
			<label for="tck-task-assignee"><?php p($l->t('Assignee (optional)')); ?></label>
			<select id="tck-task-assignee" name="assignee_uid" class="tck-select"><option value=""><?php p($l->t('Unassigned')); ?></option></select>
			<p class="tck-field-error" id="tck-task-assignee-error" data-tck-err="assignee_uid" hidden></p>
		</div>
		<div class="tck-field">
			<label for="tck-task-due"><?php p($l->t('Due (optional)')); ?></label>
			<input id="tck-task-due" name="due_at" type="datetime-local">
		</div>
		<div class="tck-field">
			<label for="tck-task-body"><?php p($l->t('Details')); ?></label>
			<textarea id="tck-task-body" name="body" rows="3" maxlength="4000"></textarea>
		</div>
		<div class="tck-form-actions">
			<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Create task')); ?></button>
			<button type="button" class="tck-btn" data-tck-cancel="tck-task-form"><?php p($l->t('Cancel')); ?></button>
		</div>
	</form>
</section>

<section id="tck-sec-logbook" aria-labelledby="tck-log-title">
	<div class="tck-list-head">
		<h2 id="tck-log-title" class="tck-list-head__title"><?php p($l->t('Site logbook')); ?></h2>
		<div class="tck-list-head__actions">
			<button type="button" class="tck-btn tck-btn--primary" id="tck-log-new"><?php p($l->t('New entry')); ?></button>
		</div>
	</div>
	<ul class="tck-list" id="tck-logbook" data-site-id="<?php p($siteId); ?>"></ul>
	<p class="tck-empty-state__text" id="tck-log-empty" hidden><?php p($l->t('No logbook entries yet.')); ?></p>
	<form class="tck-form tck-hidden" id="tck-log-form" data-site-id="<?php p($siteId); ?>" novalidate>
		<div class="tck-field">
			<label for="tck-log-kind"><?php p($l->t('Entry type')); ?></label>
			<select id="tck-log-kind" name="kind" class="tck-select">
				<option value="note"><?php p($l->t('Note')); ?></option>
				<option value="handover"><?php p($l->t('Handover')); ?></option>
				<option value="hazard"><?php p($l->t('Hazard')); ?></option>
			</select>
		</div>
		<div class="tck-field">
			<label for="tck-log-body"><?php p($l->t('Entry')); ?></label>
			<textarea id="tck-log-body" name="body" rows="3" required maxlength="4000"></textarea>
			<p class="tck-field-error" id="tck-log-body-error" hidden></p>
		</div>
		<?php if ($canManage): ?>
		<div class="tck-field tck-field--check">
			<input id="tck-log-pinned" name="pinned" type="checkbox" value="1">
			<label for="tck-log-pinned"><?php p($l->t('Pin — stays on top until edited')); ?></label>
		</div>
		<?php endif; ?>
		<div class="tck-form-actions">
			<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Add entry')); ?></button>
			<button type="button" class="tck-btn" data-tck-cancel="tck-log-form"><?php p($l->t('Cancel')); ?></button>
		</div>
	</form>
</section>

<section id="tck-sec-kpis" aria-labelledby="tck-kpi-title">
	<div class="tck-list-head">
		<h2 id="tck-kpi-title" class="tck-list-head__title"><?php p($l->t('KPIs')); ?></h2>
		<div class="tck-list-head__actions tck-kpi-range">
			<label class="tck-sr-only" for="tck-kpi-from"><?php p($l->t('From')); ?></label>
			<input id="tck-kpi-from" type="date">
			<label class="tck-sr-only" for="tck-kpi-to"><?php p($l->t('To')); ?></label>
			<input id="tck-kpi-to" type="date">
			<button type="button" class="tck-btn" id="tck-kpi-load"><?php p($l->t('Load')); ?></button>
		</div>
	</div>
	<div id="tck-kpis" class="tck-kpi-grid" data-site-id="<?php p($siteId); ?>" aria-live="polite"></div>
</section>
