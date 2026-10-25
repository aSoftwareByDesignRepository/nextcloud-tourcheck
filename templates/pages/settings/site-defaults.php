<?php
/** @var array $_ @var \OCP\IL10N $l */
use OCA\TourCheck\Constants;

$v = (array)($_['settings'] ?? []);
$tileProvider = (string)($v['tileProvider'] ?? Constants::TILE_PROVIDER_SCHEMATIC);
$tileUrl = (string)($v['tileUrl'] ?? '');
$tileAttribution = (string)($v['tileAttribution'] ?? '');
?>
<form class="tck-form" data-tck-settings="site" novalidate>
	<div class="tck-field">
		<label for="tck-def-tz"><?php p($l->t('Default timezone')); ?></label>
		<input id="tck-def-tz" name="default_timezone" type="text" value="<?php p((string)($v['defaultTimezone'] ?? 'Europe/Berlin')); ?>" maxlength="64">
		<p class="tck-field-hint"><?php p($l->t('Used for new sites and scheduling.')); ?></p>
		<p class="tck-field-error" id="tck-def-tz-error" hidden></p>
	</div>
	<fieldset class="tck-fieldset">
		<legend><?php p($l->t('Map tiles')); ?></legend>
		<p class="tck-field-hint"><?php p($l->t('Background map on the Operations live map.')); ?></p>
		<div class="tck-field tck-field--check">
			<input id="tck-tile-schematic" name="tile_provider" type="radio" value="<?php p(Constants::TILE_PROVIDER_SCHEMATIC); ?>"<?php if ($tileProvider === Constants::TILE_PROVIDER_SCHEMATIC) p(' checked'); ?>>
			<label for="tck-tile-schematic"><?php p($l->t('Built-in schematic map')); ?></label>
			<p class="tck-field-hint"><?php p($l->t('Default — no external requests. Nothing leaves this server.')); ?></p>
		</div>
		<div class="tck-field tck-field--check">
			<input id="tck-tile-osm" name="tile_provider" type="radio" value="<?php p(Constants::TILE_PROVIDER_OSM); ?>"<?php if ($tileProvider === Constants::TILE_PROVIDER_OSM) p(' checked'); ?>>
			<label for="tck-tile-osm"><?php p($l->t('OpenStreetMap')); ?></label>
			<p class="tck-field-hint"><?php p($l->t('Free public basemap — the browser loads map images from tile.openstreetmap.org when the map opens. Subject to the OpenStreetMap tile usage policy; attribution is shown automatically.')); ?></p>
		</div>
		<div class="tck-field tck-field--check">
			<input id="tck-tile-custom" name="tile_provider" type="radio" value="<?php p(Constants::TILE_PROVIDER_CUSTOM); ?>"<?php if ($tileProvider === Constants::TILE_PROVIDER_CUSTOM) p(' checked'); ?>>
			<label for="tck-tile-custom"><?php p($l->t('Custom tile server')); ?></label>
		</div>
		<div data-tck-tile-custom<?php if ($tileProvider !== Constants::TILE_PROVIDER_CUSTOM) p(' hidden'); ?>>
			<div class="tck-field">
				<label for="tck-tile-url"><?php p($l->t('Tile URL template')); ?></label>
				<input id="tck-tile-url" name="tile_url" type="url" inputmode="url" autocomplete="off" spellcheck="false" maxlength="500"
					value="<?php p($tileProvider === Constants::TILE_PROVIDER_CUSTOM ? $tileUrl : ''); ?>" placeholder="https://…/{z}/{x}/{y}.png"<?php if ($tileProvider !== Constants::TILE_PROVIDER_CUSTOM) p(' disabled'); ?>>
				<p class="tck-field-hint"><?php p($l->t('https:// URL with {z}, {x} and {y} placeholders — e.g. https://tile.openstreetmap.org/{z}/{x}/{y}.png')); ?></p>
				<p class="tck-field-error" id="tck-tile-url-error" hidden></p>
			</div>
			<div class="tck-field">
				<label for="tck-tile-attribution"><?php p($l->t('Attribution (optional)')); ?></label>
				<input id="tck-tile-attribution" name="tile_attribution" type="text" maxlength="300"<?php if ($tileProvider !== Constants::TILE_PROVIDER_CUSTOM) p(' disabled'); ?>
					value="<?php p($tileProvider === Constants::TILE_PROVIDER_CUSTOM ? $tileAttribution : ''); ?>" placeholder="<?php p($l->t('© Your map provider')); ?>">
				<p class="tck-field-hint"><?php p($l->t('Shown in the map corner — most providers require it.')); ?></p>
			</div>
		</div>
	</fieldset>
	<button type="submit" class="tck-btn tck-btn--primary"><?php p($l->t('Save')); ?></button>
</form>
