<?php
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
/** @var array $_ @var \OCP\IL10N $l */
use OCA\TourCheck\Support\Badge;
$sites = (array)($_['sites'] ?? []);
?>
<section aria-labelledby="tck-sites-title">
	<div class="tck-list-head">
		<h2 id="tck-sites-title" class="tck-list-head__title"><?php p($l->t('Your sites')); ?></h2>
		<span class="tck-list-head__count" data-tck-sites-count role="status"></span>
		<?php if (!empty($_['canManage'])): ?>
			<span class="tck-list-head__actions">
				<button type="button" class="tck-btn tck-btn--primary" data-tck-new-site><?php p($l->t('New site')); ?></button>
			</span>
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
		<div class="tck-empty-state">
			<p class="tck-empty-state__title"><?php p($l->t('No sites yet')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('A site is one object you guard — a building, a plant, a property.')); ?></p>
		</div>
	<?php else: ?>
		<section class="tck-filter-panel" aria-labelledby="tck-sites-filter-title">
			<header class="tck-filter-panel__head">
				<h2 id="tck-sites-filter-title" class="tck-sr-only"><?php p($l->t('Filter sites')); ?></h2>
			</header>
			<div class="tck-filter-panel__body">
				<form class="tck-filter-panel__form" role="search" data-tck-sites-filter novalidate
					aria-label="<?php p($l->t('Filter sites')); ?>">
					<div class="tck-filter-grid tck-filter-grid--search">
						<div class="tck-filter-field">
							<label class="tck-filter-field__label" for="tck-sf-q"><?php p($l->t('Search')); ?></label>
							<div class="tck-filter-field__control">
								<input id="tck-sf-q" name="q" type="search" class="tck-select"
									placeholder="<?php p($l->t('Site name…')); ?>" autocomplete="off">
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
		<div class="tck-empty-state" data-tck-sites-empty-filtered hidden>
			<p class="tck-empty-state__title"><?php p($l->t('Nothing matches')); ?></p>
			<p class="tck-empty-state__text"><?php p($l->t('Clear the search to see all sites again.')); ?></p>
		</div>
		<ul class="tck-list" data-tck-sites-list>
			<?php foreach ($sites as $site): ?>
				<li class="tck-list__row" data-search="<?php p(strtolower($site->getName() . ' ' . $site->getTz())); ?>">
					<a class="tck-list__main" href="<?php p($urlGenerator->linkToRoute('tourcheck.page.site', ['siteId' => $site->getId()])); ?>">
						<span class="tck-list__title"><?php p($site->getName()); ?></span>
						<span class="tck-list__sub"><?php p($site->getTz()); ?></span>
					</a>
					<?php print_unescaped(Badge::render($site->getActive() === 1 ? $l->t('Active') : $l->t('Archived'), Badge::onOff($site->getActive() === 1))); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
