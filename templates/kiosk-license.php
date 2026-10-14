<?php
/** @var \OCP\IL10N $l */
$reason = $_['reason'] ?? 'license_required';
?>
<div class="tck-guest-shell">
	<main class="tck-guest-card" aria-labelledby="tck-kl-title">
		<h1 id="tck-kl-title"><?php p($l->t('Station not licensed')); ?></h1>
		<?php if ($reason === 'no_device'): ?>
			<p><?php p($l->t('The station device limit for this license is reached. Remove a station or upgrade the license to activate this device.')); ?></p>
		<?php else: ?>
			<p><?php p($l->t('This station needs an active station license. Ask your administrator to apply or renew the license in the TourCheck settings.')); ?></p>
		<?php endif; ?>
	</main>
</div>
