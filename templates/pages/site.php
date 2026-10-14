<?php
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
/** @var array $_ @var \OCP\IL10N $l */
use OCA\TourCheck\Support\Badge;
$site = $_['site'] ?? null;
$checkpoints = (array)($_['checkpoints'] ?? []);
$plans = (array)($_['plans'] ?? []);
$liveLinks = (array)($_['liveLinks'] ?? []);
$liveBase = (string)($_['liveBase'] ?? '');
$members = (array)($_['members'] ?? []);
$kindLabel = ['qr' => $l->t('QR code'), 'nfc' => $l->t('NFC tag'), 'manual' => $l->t('Manual button')];
$checkpointKinds = (array)($_['checkpointKinds'] ?? []);
$templates = (array)($_['templates'] ?? []);
$canManage = !empty($_['canManage']);
$tckKeys = (array)($_['keys'] ?? []);
$tckKeyLog = (array)($_['keyLog'] ?? []);
?>
<?php if ($site): ?>
<nav class="tck-chipbar" aria-label="<?php p($l->t('On this page')); ?>">
	<a class="tck-chipbar__chip" href="#tck-sec-checkpoints"><?php p($l->t('Checkpoints')); ?></a>
	<a class="tck-chipbar__chip" href="#tck-sec-plans"><?php p($l->t('Plans')); ?></a>
	<a class="tck-chipbar__chip" href="#tck-sec-keys"><?php p($l->t('Keys')); ?></a>
	<a class="tck-chipbar__chip" href="#tck-sec-members"><?php p($l->t('Members')); ?></a>
	<?php if ($canManage): ?>
		<a class="tck-chipbar__chip" href="#tck-sec-live"><?php p($l->t('Live links')); ?></a>
		<a class="tck-chipbar__chip" href="#tck-sec-privacy"><?php p($l->t('Privacy')); ?></a>
	<?php endif; ?>
</nav>

<section id="tck-sec-checkpoints" aria-labelledby="tck-cp-title">
	<div class="tck-list-head">
		<h2 id="tck-cp-title" class="tck-list-head__title"><?php p($l->t('Checkpoints')); ?></h2>
		<span class="tck-list-head__count"><?php p($l->t('%n in walk order', ['%n' => count($checkpoints)])); ?></span>
		<div class="tck-list-head__actions">
			<?php if ($canManage && $checkpoints !== []): ?>
				<a class="tck-btn" href="<?php p($urlGenerator->linkToRoute('tourcheck.page.qrSheet', ['siteId' => $site->getId()])); ?>" target="_blank" rel="noopener"><?php p($l->t('Print QR sheet')); ?></a>
			<?php endif; ?>
			<?php if ($canManage): ?>
				<button type="button" class="tck-btn tck-btn--primary" data-tck-new-cp><?php p($l->t('New checkpoint')); ?></button>
			<?php endif; ?>
		</div>
	</div>
	<?php if ($canManage): ?>
		<form class="tck-form tck-hidden" id="tck-cp-form" data-tck-cp-form data-site-id="<?php p($site->getId()); ?>" novalidate>
			<div class="tck-field">
				<label for="tck-cp-name"><?php p($l->t('Name')); ?></label>
				<input id="tck-cp-name" name="name" type="text" required maxlength="120" autocomplete="off">
				<p class="tck-field-error" id="tck-cp-name-error" hidden></p>
			</div>
			<div class="tck-field">
				<label for="tck-cp-kind"><?php p($l->t('Scan type')); ?></label>
				<select id="tck-cp-kind" name="kind" class="tck-select">
					<option value="qr"><?php p($l->t('QR code')); ?></option>
					<option value="nfc"><?php p($l->t('NFC tag')); ?></option>
					<option value="manual"><?php p($l->t('Manual button')); ?></option>
				</select>
			</div>
			<div class="tck-field">
				<label for="tck-cp-sort"><?php p($l->t('Walk order')); ?></label>
				<input id="tck-cp-sort" name="sort" type="number" min="0" step="1" value="<?php p((string)(count($checkpoints) + 1)); ?>">
			</div>
			<fieldset class="tck-fieldset">
				<legend><?php p($l->t('Checklist (optional)')); ?></legend>
				<p class="tck-hint"><?php p($l->t('Things the worker must tick or measure before the checkpoint counts as done.')); ?></p>
				<div data-tck-profile-items></div>
				<button type="button" class="tck-btn" data-tck-profile-add><?php p($l->t('Add checklist item')); ?></button>
				<p class="tck-field-error" id="tck-cp-profile-error" data-tck-err="profile" hidden></p>
			</fieldset>
			<template id="tck-profile-item-tpl">
				<div class="tck-profile-item">
					<div class="tck-field">
						<label><?php p($l->t('Item')); ?> <input name="pi_label" type="text" maxlength="190" required></label>
					</div>
					<div class="tck-field">
						<label><?php p($l->t('Answer type')); ?>
							<select name="pi_kind" class="tck-select">
								<option value="bool"><?php p($l->t('Yes / No')); ?></option>
								<option value="number"><?php p($l->t('Number')); ?></option>
								<option value="text"><?php p($l->t('Text')); ?></option>
								<option value="photo"><?php p($l->t('Photo')); ?></option>
							</select>
						</label>
					</div>
					<div class="tck-field tck-field--check">
						<label><input name="pi_required" type="checkbox"> <?php p($l->t('Required')); ?></label>
					</div>
					<div class="tck-profile-item__band" data-pi-band hidden>
						<div class="tck-field">
							<label><?php p($l->t('Min')); ?> <input name="pi_min" type="number" step="any" inputmode="decimal"></label>
						</div>
						<div class="tck-field">
							<label><?php p($l->t('Max')); ?> <input name="pi_max" type="number" step="any" inputmode="decimal"></label>
						</div>
						<div class="tck-field">
							<label><?php p($l->t('Unit')); ?> <input name="pi_unit" type="text" maxlength="16" placeholder="°C, bar, …"></label>
						</div>
					</div>
					<button type="button" class="tck-btn tck-btn--danger" data-pi-remove><?php p($l->t('Remove')); ?></button>
				</div>
			</template>
			<div class="tck-form-actions">
				<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Create checkpoint')); ?></button>
				<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	<?php endif; ?>
	<?php if ($checkpoints === []): ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No checkpoints yet')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Add one, print its QR code, and stick it where the guard must go.')); ?></p>
		</div>
		<?php if ($canManage && $templates !== []): ?>
			<form class="tck-form" data-tck-template-form data-site-id="<?php p($site->getId()); ?>" novalidate>
				<div class="tck-field">
					<label for="tck-template-key"><?php p($l->t('Or start from a template')); ?></label>
					<select id="tck-template-key" name="tkey" required>
						<?php foreach ($templates as $t): ?>
							<option value="<?php p((string)$t['tkey']); ?>"><?php p((string)$t['name']); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="tck-field-hint"><?php p($l->t('Creates the checkpoints and a draft plan for you to review.')); ?></p>
				</div>
				<div class="tck-form-actions">
					<button type="submit" class="tck-btn"><?php p($l->t('Use template')); ?></button>
				</div>
			</form>
		<?php endif; ?>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($checkpoints as $cp): ?>
				<li class="tck-list__row">
					<span class="tck-list__main">
						<span class="tck-list__title"><?php p($cp->getName()); ?></span>
						<span class="tck-list__sub"><?php $k = $checkpointKinds[(int)$cp->getId()] ?? 'qr'; p($kindLabel[$k] ?? $k); ?></span>
					</span>
					<?php if ($canManage): ?>
						<button type="button" class="tck-btn tck-btn--danger" data-tck-archive-cp="<?php p($cp->getId()); ?>" data-site-id="<?php p($site->getId()); ?>"><?php p($l->t('Archive')); ?></button>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<section id="tck-sec-plans" aria-labelledby="tck-plan-title">
	<div class="tck-list-head">
		<h2 id="tck-plan-title" class="tck-list-head__title"><?php p($l->t('Plans')); ?></h2>
		<span class="tck-list-head__count"><?php p($l->t('%n configured', ['%n' => count($plans)])); ?></span>
		<?php if ($canManage): ?>
			<div class="tck-list-head__actions">
				<button type="button" class="tck-btn tck-btn--primary" data-tck-new-plan><?php p($l->t('New plan')); ?></button>
			</div>
		<?php endif; ?>
	</div>
	<?php if ($canManage): ?>
		<form class="tck-form tck-hidden" id="tck-plan-form" data-tck-plan-form data-site-id="<?php p($site->getId()); ?>" novalidate>
			<div class="tck-field">
				<label for="tck-plan-name"><?php p($l->t('Plan name')); ?></label>
				<input id="tck-plan-name" name="name" type="text" required maxlength="190" autocomplete="off"
					value="<?php p($site->getName() . ' — ' . $l->t('Round')); ?>">
				<p class="tck-field-error" id="tck-plan-name-error" hidden></p>
			</div>
			<fieldset class="tck-fieldset">
				<legend><?php p($l->t('Checkpoints')); ?></legend>
				<?php foreach ($checkpoints as $cp): ?>
					<div class="tck-field tck-field--check">
						<input id="tck-plan-cp-<?php p($cp->getId()); ?>" type="checkbox" name="cp_ids" value="<?php p($cp->getId()); ?>" checked>
						<label for="tck-plan-cp-<?php p($cp->getId()); ?>"><?php p($cp->getName()); ?></label>
					</div>
				<?php endforeach; ?>
				<p class="tck-field-error" id="tck-plan-cps-error" data-tck-err="checkpoints" hidden></p>
			</fieldset>
			<div class="tck-field">
				<label for="tck-plan-type"><?php p($l->t('How often')); ?></label>
				<select id="tck-plan-type" name="rec_type" class="tck-select" data-tck-plan-type>
					<option value="daily"><?php p($l->t('Every day')); ?></option>
					<option value="weekly"><?php p($l->t('Every week')); ?></option>
					<option value="interval"><?php p($l->t('Every few hours')); ?></option>
				</select>
			</div>
			<div class="tck-field" data-tck-plan-when="daily weekly">
				<label for="tck-plan-time"><?php p($l->t('Start time')); ?></label>
				<input id="tck-plan-time" name="rec_time" type="time" value="18:00" required>
				<p class="tck-field-error" id="tck-plan-time-error" data-tck-err="time" hidden></p>
			</div>
			<fieldset class="tck-fieldset tck-hidden" data-tck-plan-when="weekly">
				<legend><?php p($l->t('Weekdays')); ?></legend>
				<?php $wdays = [0 => $l->t('Mon'), 1 => $l->t('Tue'), 2 => $l->t('Wed'), 3 => $l->t('Thu'), 4 => $l->t('Fri'), 5 => $l->t('Sat'), 6 => $l->t('Sun')]; ?>
				<?php foreach ($wdays as $d => $dLabel): ?>
					<div class="tck-field tck-field--check tck-field--inline">
						<input id="tck-plan-day-<?php p($d); ?>" type="checkbox" name="day_ids" value="<?php p($d); ?>"<?php if ($d < 5) p(' checked'); ?>>
						<label for="tck-plan-day-<?php p($d); ?>"><?php p($dLabel); ?></label>
					</div>
				<?php endforeach; ?>
				<p class="tck-field-error" id="tck-plan-days-error" data-tck-err="days" hidden></p>
			</fieldset>
			<div class="tck-field tck-hidden" data-tck-plan-when="interval">
				<label for="tck-plan-hours"><?php p($l->t('Every (hours)')); ?></label>
				<input id="tck-plan-hours" name="rec_hours" type="number" min="1" max="336" step="1" value="8">
				<p class="tck-field-error" id="tck-plan-hours-error" data-tck-err="interval_hours" hidden></p>
			</div>
			<div class="tck-field">
				<label for="tck-plan-duration"><?php p($l->t('Duration (minutes)')); ?></label>
				<input id="tck-plan-duration" name="duration_min" type="number" min="5" max="1440" step="5" value="60">
				<p class="tck-field-error" id="tck-plan-duration-error" data-tck-err="duration_min" hidden></p>
			</div>
			<div class="tck-field">
				<label for="tck-plan-checkin"><?php p($l->t('Check-in every (minutes, optional)')); ?></label>
				<input id="tck-plan-checkin" name="checkin_interval_min" type="number" min="0" max="480" step="5" value="" inputmode="numeric">
				<p class="tck-field-hint"><?php p($l->t('Worker safety: if nothing happens on the round for this long, the site manager is alerted. Leave empty to disable.')); ?></p>
				<p class="tck-field-error" id="tck-plan-checkin-error" data-tck-err="checkin_interval_min" hidden></p>
			</div>
			<div class="tck-form-actions">
				<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Create plan')); ?></button>
				<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	<?php endif; ?>
	<?php if ($plans === []): ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No plan yet')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('A plan says which checkpoints a round needs and when it runs.')); ?></p>
		</div>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($plans as $plan): ?>
				<li class="tck-list__row">
					<span class="tck-list__main">
						<span class="tck-list__title"><?php p((string)$plan['name']); ?></span>
						<span class="tck-list__sub"><?php p($l->t('v%d · %s', [(int)$plan['revision'], (string)$plan['summary']])); ?></span>
					</span>
					<span class="tck-list__aside">
						<?php print_unescaped(Badge::render($plan['active'] ? $l->t('Active') : $l->t('Inactive'), Badge::onOff($plan['active']))); ?>
						<?php if ($canManage): ?>
							<button type="button" class="tck-btn" data-tck-plan-state="<?php p((string)$plan['id']); ?>" data-active="<?php p($plan['active'] ? '1' : '0'); ?>">
								<?php p($plan['active'] ? $l->t('Deactivate') : $l->t('Activate')); ?>
							</button>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<section id="tck-sec-keys" aria-labelledby="tck-keys-title">
	<div class="tck-list-head">
		<h2 id="tck-keys-title" class="tck-list-head__title"><?php p($l->t('Keys')); ?></h2>
		<span class="tck-list-head__count"><?php p($l->t('%n registered', ['%n' => count($tckKeys)])); ?></span>
		<?php if ($canManage): ?>
			<div class="tck-list-head__actions">
				<button type="button" class="tck-btn tck-btn--primary" data-tck-new-key><?php p($l->t('Register key')); ?></button>
			</div>
		<?php endif; ?>
	</div>
	<?php if ($canManage): ?>
		<form class="tck-form tck-hidden" id="tck-key-form" data-tck-key-form data-site-id="<?php p($site->getId()); ?>" novalidate>
			<div class="tck-field">
				<label for="tck-key-label"><?php p($l->t('Key label')); ?></label>
				<input id="tck-key-label" name="label" type="text" required maxlength="190" autocomplete="off" placeholder="<?php p($l->t('e.g. Main door, Vehicle 3')); ?>">
				<p class="tck-field-error" id="tck-key-label-error" data-tck-err="label" hidden></p>
			</div>
			<div class="tck-form-actions">
				<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Save key')); ?></button>
				<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	<?php endif; ?>
	<?php if ($tckKeys === []): ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No keys registered')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Register a key to track who has it.')); ?></p>
		</div>
	<?php else: ?>
		<ul class="tck-list" data-tck-key-list data-site-id="<?php p($site->getId()); ?>">
			<?php foreach ($tckKeys as $k): ?>
				<li class="tck-list__row">
					<span class="tck-list__main">
						<span class="tck-list__title"><?php p((string)$k['label']); ?></span>
						<span class="tck-list__sub"><?php p($k['status'] === 'out' ? $l->t('Taken') : $l->t('In the register')); ?></span>
					</span>
					<span class="tck-list__aside">
						<?php print_unescaped(Badge::render($k['status'] === 'out' ? $l->t('Out') : $l->t('In'), $k['status'] === 'out' ? 'warning' : 'success')); ?>
						<button type="button" class="tck-btn" data-tck-key-act="<?php p((string)$k['id']); ?>" data-act="<?php p($k['status'] === 'out' ? 'return' : 'take'); ?>">
							<?php p($k['status'] === 'out' ? $l->t('Return') : $l->t('Take')); ?>
						</button>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ($tckKeyLog !== []): ?>
		<details class="tck-details" data-tck-key-log>
			<summary><?php p($l->t('Key movements')); ?></summary>
			<ul class="tck-list">
				<?php foreach (array_slice($tckKeyLog, 0, 20) as $kl): ?>
					<li class="tck-list__row">
						<span class="tck-list__main">
							<span class="tck-list__title"><?php p($kl['action'] === 'take' ? $l->t('Taken') : $l->t('Returned')); ?></span>
							<span class="tck-list__sub"><?php p((string)$kl['created_at']); ?></span>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
</section>

<section id="tck-sec-members" aria-labelledby="tck-mem-title">
	<div class="tck-list-head">
		<h2 id="tck-mem-title" class="tck-list-head__title"><?php p($l->t('Members')); ?></h2>
		<span class="tck-list-head__count"><?php p($l->t('%n assigned', ['%n' => count($members)])); ?></span>
		<?php if ($canManage): ?>
			<div class="tck-list-head__actions">
				<button type="button" class="tck-btn tck-btn--primary" data-tck-new-member><?php p($l->t('Add member')); ?></button>
			</div>
		<?php endif; ?>
	</div>
	<?php if ($canManage): ?>
		<form class="tck-form tck-hidden" id="tck-member-form" data-tck-member-form data-site-id="<?php p($site->getId()); ?>" novalidate>
			<div class="tck-field">
				<label for="tck-mem-uid"><?php p($l->t('User')); ?></label>
				<input id="tck-mem-uid" name="uid" type="text" required maxlength="64" autocomplete="off" placeholder="<?php p($l->t('Nextcloud user id')); ?>" data-tck-uid-picker>
				<p class="tck-field-error" id="tck-mem-uid-error" hidden></p>
			</div>
			<div class="tck-field">
				<label for="tck-mem-role"><?php p($l->t('Role')); ?></label>
				<select id="tck-mem-role" name="role" class="tck-select">
					<option value="worker"><?php p($l->t('Worker')); ?></option>
					<option value="supervisor"><?php p($l->t('Supervisor')); ?></option>
					<option value="planner"><?php p($l->t('Planner')); ?></option>
				</select>
			</div>
			<div class="tck-form-actions">
				<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Add member')); ?></button>
				<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	<?php endif; ?>
	<?php if ($members === []): ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No members yet')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Assign people so they can see and work this site.')); ?></p>
		</div>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($members as $m): ?>
				<li class="tck-list__row">
					<span class="tck-list__main">
						<span class="tck-list__title"><?php p((string)$m['displayName']); ?></span>
						<span class="tck-list__sub"><?php p((string)$m['uid']); ?></span>
					</span>
					<span class="tck-list__aside">
						<?php print_unescaped(Badge::render((string)$m['role'], 'info')); ?>
						<?php if ($canManage): ?>
							<button type="button" class="tck-btn tck-btn--danger" data-tck-remove-member="<?php p((string)$m['uid']); ?>" data-site-id="<?php p($site->getId()); ?>"><?php p($l->t('Remove')); ?></button>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<?php if ($canManage): ?>
<section id="tck-sec-live" aria-labelledby="tck-live-title">
	<div class="tck-list-head">
		<h2 id="tck-live-title" class="tck-list-head__title"><?php p($l->t('Live links')); ?></h2>
		<span class="tck-list-head__count"><?php p($l->t('%n shared', ['%n' => count($liveLinks)])); ?></span>
		<div class="tck-list-head__actions">
			<button type="button" class="tck-btn tck-btn--primary" data-tck-new-live><?php p($l->t('New live link')); ?></button>
		</div>
	</div>
	<p class="tck-hint"><?php p($l->t("A live link shows this site’s status to anyone with the URL — no login needed. Share it with the object’s owner.")); ?></p>
	<form class="tck-form tck-hidden" id="tck-live-form" data-tck-live-form data-site-id="<?php p($site->getId()); ?>" data-live-base="<?php p($liveBase); ?>" novalidate>
		<div class="tck-field">
			<label for="tck-live-label"><?php p($l->t('Label')); ?></label>
			<input id="tck-live-label" name="label" type="text" maxlength="190" autocomplete="off" placeholder="<?php p($l->t('e.g. Owner portal — Building A')); ?>">
		</div>
		<div class="tck-field">
			<label for="tck-live-exp"><?php p($l->t('Expires (optional)')); ?></label>
			<input id="tck-live-exp" name="expires_at" type="date">
		</div>
		<div class="tck-form-actions">
			<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Create link')); ?></button>
			<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
		</div>
	</form>
	<div class="tck-callout tck-callout--success tck-hidden" id="tck-live-created" role="status">
		<p class="tck-callout__title"><?php p($l->t('Link created — copy it now')); ?></p>
		<p><?php p($l->t('It will not be shown again.')); ?></p>
		<div class="tck-copy-row">
			<code id="tck-live-url" class="tck-copy-row__text"></code>
			<button type="button" class="tck-btn" id="tck-live-copy"><?php p($l->t('Copy')); ?></button>
		</div>
	</div>
	<?php if ($liveLinks === []): ?>
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No live links yet')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t("Create one to share this site’s status.")); ?></p>
		</div>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($liveLinks as $ll): ?>
				<li class="tck-list__row">
					<span class="tck-list__main">
						<span class="tck-list__title"><?php p($ll['label'] !== null && $ll['label'] !== '' ? (string)$ll['label'] : $l->t('Live link #%d', (int)$ll['id'])); ?></span>
						<span class="tck-list__sub"><?php
							if ($ll['revoked']) {
								p($l->t('Revoked'));
							} elseif ($ll['expiresAt'] !== null) {
								p($l->t('Expires %s', (string)$ll['expiresAt']));
							} else {
								p($l->t('Never expires'));
							}
						?></span>
					</span>
					<?php if ($ll['revoked']): ?>
						<?php print_unescaped(Badge::render($l->t('Revoked'), 'danger')); ?>
					<?php else: ?>
						<button type="button" class="tck-btn tck-btn--danger" data-tck-revoke-live="<?php p((string)$ll['id']); ?>"><?php p($l->t('Revoke')); ?></button>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
<section id="tck-sec-privacy" aria-labelledby="tck-privacy-title">
	<div class="tck-list-head">
		<h2 id="tck-privacy-title" class="tck-list-head__title"><?php p($l->t('Privacy')); ?></h2>
	</div>
	<div class="tck-field tck-field--check">
		<input id="tck-live-pos" type="checkbox" data-tck-live-pos
			data-site-id="<?php p($site->getId()); ?>"
			data-revision="<?php p((int)$site->getRevision()); ?>"
			<?php if ((int)$site->getLivePosition() === 1): ?>checked<?php endif; ?>>
		<label for="tck-live-pos"><?php p($l->t('Live positions during active rounds')); ?></label>
	</div>
	<p class="tck-hint"><?php p($l->t("When on, the positions of members on duty are recorded while a round is active on this site. Members see a permanent indicator on their device while sharing. Positions expire automatically.")); ?></p>
	<form class="tck-form" data-tck-erase-form data-site-id="<?php p($site->getId()); ?>" novalidate>
		<div class="tck-field">
			<label for="tck-erase-uid"><?php p($l->t('Erase personal data (GDPR)')); ?></label>
			<input id="tck-erase-uid" name="uid" type="text" maxlength="64" autocomplete="off" placeholder="<?php p($l->t('User ID, e.g. mmueller')); ?>" required>
			<p class="tck-field-error" id="tck-erase-uid-error" hidden></p>
			<p class="tck-field-hint"><?php p($l->t('Tombstones this person’s events, attestations, incidents, positions and evidence files on this site. Journaled and irreversible.')); ?></p>
		</div>
		<div class="tck-form-actions">
			<button type="submit" class="tck-btn tck-btn--danger"><?php p($l->t('Erase data')); ?></button>
		</div>
	</form>
</section>
<?php endif; ?>
<?php endif; ?>
