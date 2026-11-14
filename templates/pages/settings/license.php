<?php

declare(strict_types=1);

/**
 * TourCheck Settings → License (TCK2 companion + stations).
 *
 * The license UX is the canonical shared Check-family license page
 * (templates/common/license-page.php, synced from _shared/license-page).
 * PageController feeds every canonical param — stations are managed in
 * their own settings section (deviceManageUrl link), so this template
 * only renders the shared page.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

include __DIR__ . '/../../common/license-page.php';
