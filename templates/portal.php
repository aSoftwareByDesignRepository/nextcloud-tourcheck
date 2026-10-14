<?php
/**
 * Client portal — aggregate-only read view for tourcheck_client members.
 * Deliberately NOT the staff shell: no nav, no worker names, no trails.
 * @var array $_ @var \OCP\IL10N $l
 */
script('tourcheck', 'portal');
style('tourcheck', 'tck-tokens');
style('tourcheck', 'tck');
style('tourcheck', 'portal');

$siteId = (int)($_['siteId'] ?? 0);
?>
<div id="app-content" class="tck-app tck-app--portal tck-portal"
	 data-tck-portal-site="<?php p($siteId); ?>">
	<div id="tck-live-region" class="tck-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div class="tck-shell tck-shell--constrained tck-page-stack">
		<header class="tck-page-header" role="group" aria-labelledby="tck-portal-title">
			<div class="tck-page-header__main">
				<div class="tck-page-header__text">
					<h1 id="tck-portal-title"><?php p($l->t('Site report')); ?></h1>
					<p class="tck-page-header__lead"><?php p($l->t('Status, service quality and incidents for your premises.')); ?></p>
				</div>
			</div>
		</header>
		<main id="tck-main-content" tabindex="-1">
			<section id="tck-portal-sites" aria-labelledby="tck-portal-sites-title"<?php if ($siteId > 0) { p(' class="tck-hidden"'); } ?>>
				<h2 id="tck-portal-sites-title" class="tck-list-head__title"><?php p($l->t('Your sites')); ?></h2>
				<ul class="tck-list" id="tck-portal-site-list"></ul>
				<p class="tck-empty-state__text" id="tck-portal-empty" hidden><?php p($l->t('No sites assigned yet — your service provider will connect them.')); ?></p>
			</section>

			<div id="tck-portal-detail"<?php if ($siteId === 0) { p(' class="tck-hidden"'); } ?>>
				<nav class="tck-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
					<ol>
						<li><a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('tourcheck.portal.index')); ?>"><?php p($l->t('Site report')); ?></a></li>
						<li aria-current="page"><span id="tck-portal-site-name"></span></li>
					</ol>
				</nav>

				<section aria-labelledby="tck-portal-status-title">
					<h2 id="tck-portal-status-title" class="tck-list-head__title"><?php p($l->t('Current status')); ?></h2>
					<div id="tck-portal-overview" class="tck-kpi-grid" aria-live="polite"></div>
				</section>

				<section aria-labelledby="tck-portal-kpi-title">
					<div class="tck-list-head">
						<h2 id="tck-portal-kpi-title" class="tck-list-head__title"><?php p($l->t('Service quality')); ?></h2>
						<div class="tck-list-head__actions tck-kpi-range">
							<label class="tck-sr-only" for="tck-portal-kpi-from"><?php p($l->t('From')); ?></label>
							<input id="tck-portal-kpi-from" type="date">
							<label class="tck-sr-only" for="tck-portal-kpi-to"><?php p($l->t('To')); ?></label>
							<input id="tck-portal-kpi-to" type="date">
							<button type="button" class="tck-btn" id="tck-portal-kpi-load"><?php p($l->t('Load')); ?></button>
						</div>
					</div>
					<div id="tck-portal-kpis" class="tck-kpi-grid" aria-live="polite"></div>
				</section>

				<section aria-labelledby="tck-portal-incidents-title">
					<h2 id="tck-portal-incidents-title" class="tck-list-head__title"><?php p($l->t('Reported incidents')); ?></h2>
					<ul class="tck-list" id="tck-portal-incidents"></ul>
					<p class="tck-empty-state__text" id="tck-portal-incidents-empty" hidden><?php p($l->t('No incidents reported in the visible period.')); ?></p>
				</section>

				<section aria-labelledby="tck-portal-reports-title">
					<h2 id="tck-portal-reports-title" class="tck-list-head__title"><?php p($l->t('Reports')); ?></h2>
					<ul class="tck-list" id="tck-portal-reports"></ul>
					<p class="tck-empty-state__text" id="tck-portal-reports-empty" hidden><?php p($l->t('No reports shared yet — your service provider publishes them here.')); ?></p>
				</section>
			</div>
		</main>
	</div>
</div>
