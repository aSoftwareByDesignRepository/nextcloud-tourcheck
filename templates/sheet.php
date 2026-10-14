<?php
/** @var array $_ @var \OCP\IL10N $l */
$site = $_['site'] ?? null;
$items = (array)($_['items'] ?? []);
$printedAt = $_['printedAt'] ?? null;
?>
<!DOCTYPE html>
<html lang="<?php p(\OC::$server->getL10N('tourcheck')->getLanguageCode()); ?>">
<head>
	<meta charset="utf-8">
	<title><?php p(($site ? $site->getName() : '') . ' — ' . $l->t('QR sheet')); ?></title>
	<style>
		* { box-sizing: border-box; margin: 0; }
		body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #111; padding: 16px; }
		.sheet-head { margin-bottom: 20px; }
		.sheet-head h1 { font-size: 20pt; margin-bottom: 4px; }
		.sheet-head p { font-size: 10pt; color: #555; }
		.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
		.cp { border: 1.5px solid #333; border-radius: 8px; padding: 14px; text-align: center; page-break-inside: avoid; }
		.cp svg { width: 100%; height: auto; display: block; }
		.cp h2 { font-size: 12pt; margin-top: 10px; }
		.cp .instr { font-size: 9pt; color: #444; margin-top: 4px; }
		.cp .payload { font-size: 6.5pt; color: #666; margin-top: 8px; word-break: break-all; }
		.warn { border: 1.5px dashed #a33; border-radius: 8px; padding: 10px; font-size: 9.5pt; color: #a33; margin-bottom: 16px; }
		@media print {
			body { padding: 0; }
			.warn { display: none; }
		}
	</style>
</head>
<body>
	<div class="sheet-head">
		<h1><?php p($site ? $site->getName() : ''); ?> — <?php p($l->t('Checkpoint QR sheet')); ?></h1>
		<p><?php p($l->t('Printed %s — previously issued codes are no longer valid.', $printedAt ? $printedAt->format('Y-m-d H:i') : '')); ?></p>
	</div>
	<p class="warn"><?php p($l->t('Printing this sheet generated new codes. Replace all previously printed QR codes.')); ?></p>
	<div class="grid">
		<?php foreach ($items as $it): ?>
			<div class="cp">
				<?php print_unescaped($it['svg']); ?>
				<h2><?php p($it['name']); ?></h2>
				<?php if ($it['instructions'] !== null && $it['instructions'] !== ''): ?>
					<p class="instr"><?php p($it['instructions']); ?></p>
				<?php endif; ?>
				<p class="payload"><?php p($it['payload']); ?></p>
			</div>
		<?php endforeach; ?>
	</div>
</body>
</html>
