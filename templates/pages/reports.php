<?php
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
/** @var array $_ @var \OCP\IL10N $l */
$reports = (array)($_['reports'] ?? []);
?>
<section aria-labelledby="tck-rep-title">
	<div class="tck-section-head">
		<h2 id="tck-rep-title" class="tck-section-title"><?php p($l->t('Reports')); ?></h2>
		<?php if (!empty($_['canManage']) && (int)($_['currentSiteId'] ?? 0) > 0): ?>
			<div class="tck-section-actions">
				<a class="tck-btn" href="<?php p($urlGenerator->linkToRoute('tourcheck.api.exportCsv', ['siteId' => (int)$_['currentSiteId']]) . '?period=' . urlencode(date('Y-m'))); ?>" download><?php p($l->t('Export CSV')); ?></a>
				<a class="tck-btn" href="<?php p($urlGenerator->linkToRoute('tourcheck.api.exportCsv', ['siteId' => (int)$_['currentSiteId']]) . '?period=' . urlencode(date('Y-m')) . '&format=ods'); ?>" download><?php p($l->t('Export ODS')); ?></a>
				<button type="button" class="tck-btn tck-btn--primary" data-tck-new-report><?php p($l->t('New report')); ?></button>
			</div>
		<?php endif; ?>
	</div>
	<?php if (!empty($_['canManage']) && (int)($_['currentSiteId'] ?? 0) > 0): ?>
		<form class="tck-form tck-hidden" id="tck-report-form" data-tck-report-form data-site-id="<?php p((string)($_['currentSiteId'] ?? 0)); ?>" novalidate>
			<div class="tck-field">
				<label for="tck-report-period"><?php p($l->t('Month')); ?></label>
				<input id="tck-report-period" name="period" type="month" value="<?php p(date('Y-m')); ?>" required>
				<p class="tck-field-error" id="tck-report-period-error" hidden></p>
			</div>
			<div class="tck-form-actions">
				<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Generate report')); ?></button>
				<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	<?php endif; ?>
	<?php if ($reports === []): ?>
		<p class="tck-empty"><?php p($l->t('No reports yet. Reports bundle rounds into branded, timestamped PDFs for your customers.')); ?></p>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($reports as $r): ?>
				<li class="tck-list__row">
					<span class="tck-list__main">
						<span class="tck-list__title"><?php p((string)$r['name']); ?></span>
						<span class="tck-list__sub"><?php p((string)$r['periodLabel']); ?></span>
					</span>
					<a class="tck-btn" href="<?php p($urlGenerator->linkToRoute('tourcheck.api.downloadReport', ['id' => $r['id']])); ?>"><?php p($l->t('Download')); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
<?php if (!empty($_['canManage']) && (int)($_['currentSiteId'] ?? 0) > 0): ?>
<section aria-labelledby="tck-sched-title">
	<div class="tck-section-head">
		<h2 id="tck-sched-title" class="tck-section-title"><?php p($l->t('Scheduled reports')); ?></h2>
		<div class="tck-section-actions">
			<button type="button" class="tck-btn" data-tck-new-schedule><?php p($l->t('Add schedule')); ?></button>
		</div>
	</div>
	<form class="tck-form tck-hidden" id="tck-schedule-form" data-tck-schedule-form data-site-id="<?php p((string)$_['currentSiteId']); ?>" novalidate>
		<div class="tck-field">
			<label for="tck-sched-cadence"><?php p($l->t('Cadence')); ?></label>
			<select id="tck-sched-cadence" name="cadence" required>
				<option value="weekly"><?php p($l->t('Weekly')); ?></option>
				<option value="monthly"><?php p($l->t('Monthly')); ?></option>
			</select>
			<p class="tck-field-error" id="tck-sched-cadence-error" hidden></p>
		</div>
		<div class="tck-field">
			<label for="tck-sched-recipient"><?php p($l->t('Email')); ?></label>
			<input id="tck-sched-recipient" name="recipient" type="email" required autocomplete="off">
			<p class="tck-field-error" id="tck-sched-recipient-error" hidden></p>
		</div>
		<div class="tck-field">
			<label for="tck-sched-audience"><?php p($l->t('Audience')); ?></label>
			<select id="tck-sched-audience" name="audience">
				<option value="internal"><?php p($l->t('Internal')); ?></option>
				<option value="client"><?php p($l->t('Customer — no worker names')); ?></option>
			</select>
			<p class="tck-field-error" id="tck-sched-audience-error" hidden></p>
		</div>
		<div class="tck-field">
			<label for="tck-sched-active"><?php p($l->t('Status')); ?></label>
			<select id="tck-sched-active" name="active">
				<option value="1"><?php p($l->t('Active')); ?></option>
				<option value="0"><?php p($l->t('Disabled')); ?></option>
			</select>
		</div>
		<div class="tck-form-actions">
			<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Save schedule')); ?></button>
			<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
		</div>
	</form>
	<?php $schedules = (array)($_['schedules'] ?? []); ?>
	<?php if ($schedules === []): ?>
		<p class="tck-empty"><?php p($l->t('No scheduled reports yet.')); ?></p>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($schedules as $s): ?>
				<li class="tck-list__row">
					<span class="tck-list__main">
						<span class="tck-list__title"><?php p($l->t($s['cadence'] === 'monthly' ? 'Monthly' : 'Weekly')); ?> · <?php p((string)$s['recipient']); ?></span>
						<span class="tck-list__sub"><?php p($l->t($s['audience'] === 'client' ? 'Customer — no worker names' : 'Internal')); ?><?php if (empty($s['active'])): ?> · <?php p($l->t('Disabled')); ?><?php endif; ?></span>
					</span>
					<button type="button" class="tck-btn" data-tck-toggle-sched
						data-site-id="<?php p((string)$_['currentSiteId']); ?>"
						data-cadence="<?php p((string)$s['cadence']); ?>"
						data-recipient="<?php p((string)$s['recipient']); ?>"
						data-audience="<?php p((string)$s['audience']); ?>"
						data-active="<?php p(empty($s['active']) ? '0' : '1'); ?>"><?php p(empty($s['active']) ? $l->t('Enable') : $l->t('Disable')); ?></button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
<?php endif; ?>
