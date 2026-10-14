<?php /** @var array $_ @var \OCP\IL10N $l */ $v = (array)($_['settings'] ?? []); $open = ($v['accessMode'] ?? 'open') === 'open'; ?>
<form class="tck-form" data-tck-settings="access" novalidate>
	<fieldset class="tck-fieldset">
		<legend><?php p($l->t('Who can open TourCheck')); ?></legend>
		<div class="tck-field tck-field--check">
			<input id="tck-acc-open" name="access_open" type="checkbox" value="1"<?php if ($open) p(' checked'); ?>>
			<label for="tck-acc-open"><?php p($l->t('Open for all users')); ?></label>
			<p class="tck-field-hint"><?php p($l->t('Off = only the groups and users below (plus admins) can open the app.')); ?></p>
		</div>
		<div class="tck-field">
			<label for="tck-acc-groups"><?php p($l->t('Allowed groups')); ?></label>
			<input id="tck-acc-groups" name="allowed_groups" type="text" value="<?php p(implode(', ', (array)($v['allowedGroups'] ?? []))); ?>" placeholder="<?php p($l->t('group ids, comma separated')); ?>" data-tck-gid-picker>
			<p class="tck-field-error" id="tck-acc-groups-error" hidden></p>
		</div>
		<div class="tck-field">
			<label for="tck-acc-users"><?php p($l->t('Allowed users')); ?></label>
			<input id="tck-acc-users" name="allowed_users" type="text" value="<?php p(implode(', ', (array)($v['allowedUsers'] ?? []))); ?>" placeholder="<?php p($l->t('user ids, comma separated')); ?>" data-tck-uid-picker>
			<p class="tck-field-error" id="tck-acc-users-error" hidden></p>
		</div>
	</fieldset>
	<fieldset class="tck-fieldset">
		<legend><?php p($l->t('Delegated app admins')); ?></legend>
		<div class="tck-field">
			<label for="tck-acc-admins"><?php p($l->t('App admin users')); ?></label>
			<input id="tck-acc-admins" name="app_admins" type="text" value="<?php p(implode(', ', (array)($v['appAdmins'] ?? []))); ?>" placeholder="<?php p($l->t('user ids, comma separated')); ?>" data-tck-uid-picker>
			<p class="tck-field-hint"><?php p($l->t('May manage sites, plans, and settings without being Nextcloud admin.')); ?></p>
			<p class="tck-field-error" id="tck-acc-admins-error" hidden></p>
		</div>
	</fieldset>
	<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Save')); ?></button>
</form>
