<?php
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
/** @var array $_ @var \OCP\IL10N $l */
$sites = (array)($_['sites'] ?? []);
?>
<section aria-labelledby="tck-sites-title">
	<div class="tck-section-head">
		<h2 id="tck-sites-title" class="tck-section-title"><?php p($l->t('Your sites')); ?></h2>
		<?php if (!empty($_['canManage'])): ?>
			<button type="button" class="tck-btn tck-btn--primary" data-tck-new-site><?php p($l->t('New site')); ?></button>
		<?php endif; ?>
	</div>
	<?php if (!empty($_['canManage'])): ?>
		<form class="tck-form tck-hidden" id="tck-site-form" data-tck-site-form<?php if ($sites === []) p(' data-tck-auto-open'); ?> novalidate>
			<div class="tck-field">
				<label for="tck-site-name"><?php p($l->t('Name')); ?></label>
				<input id="tck-site-name" name="name" type="text" required maxlength="120" autocomplete="off">
				<p class="tck-field-error" id="tck-site-name-error" hidden></p>
			</div>
			<div class="tck-field">
				<label for="tck-site-tz"><?php p($l->t('Timezone')); ?></label>
				<input id="tck-site-tz" name="timezone" type="text" value="<?php p((string)($_['defaultTz'] ?? 'Europe/Berlin')); ?>" required maxlength="64" list="tck-tz-list">
				<datalist id="tck-tz-list">
					<?php foreach (['Europe/Berlin','Europe/Vienna','Europe/Zurich','Europe/London','Europe/Paris','Europe/Madrid','Europe/Rome','Europe/Amsterdam','Europe/Warsaw','Europe/Copenhagen','Europe/Stockholm','Europe/Oslo','Europe/Lisbon','UTC'] as $tz): ?>
						<option value="<?php p($tz); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<p class="tck-field-error" id="tck-site-tz-error" hidden></p>
			</div>
			<div class="tck-field">
				<label for="tck-site-addr"><?php p($l->t('Address (optional)')); ?></label>
				<input id="tck-site-addr" name="address" type="text" maxlength="250" autocomplete="off">
			</div>
			<div class="tck-field">
				<label for="tck-site-briefing"><?php p($l->t('Site notes for workers (optional)')); ?></label>
				<textarea id="tck-site-briefing" name="briefing" maxlength="4000" rows="3" class="tck-select"
					placeholder="<?php p($l->t('e.g. Alarm code 4711, dog in yard, site contact 0171…')); ?>"></textarea>
				<p class="tck-field-hint"><?php p($l->t('Shown once when a round starts at this site.')); ?></p>
			</div>
			<div class="tck-form-actions">
				<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Create site')); ?></button>
				<button type="button" class="tck-btn" data-tck-cancel><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	<?php endif; ?>
	<?php if ($sites === []): ?>
		<p class="tck-empty"><?php p($l->t('No sites yet. A site is one object you guard — a building, a plant, a property.')); ?></p>
	<?php else: ?>
		<ul class="tck-list">
			<?php foreach ($sites as $site): ?>
				<li class="tck-list__row">
					<a class="tck-list__main" href="<?php p($urlGenerator->linkToRoute('tourcheck.page.site', ['siteId' => $site->getId()])); ?>">
						<span class="tck-list__title"><?php p($site->getName()); ?></span>
						<span class="tck-list__sub"><?php p($site->getTz()); ?></span>
					</a>
					<span class="tck-badge tck-badge--<?php p($site->getActive() === 1 ? 'ok' : 'warn'); ?>"><?php p($site->getActive() === 1 ? $l->t('Active') : $l->t('Archived')); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
