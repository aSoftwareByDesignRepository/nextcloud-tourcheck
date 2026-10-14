// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall settings surfaces must scroll to
 * their last control. Guards CSS Overflow L3 unpaired overflow-x:clip
 * truncating bottom-of-page Save CTAs (the class that clipped dutycheck's
 * license seat table).
 *
 * TourCheck's tall surfaces are the settings sections — stations (PIN
 * policy + lockout) and license (seat ledger + upload).
 */
import { test } from '@playwright/test'
import { assertAtlasVerticalScrollReachable } from '../../_shared/e2e/atlas-vertical-scroll-contract.js'
import { login } from './helpers/auth.js'
import { gotoApp } from './helpers/app.js'

const ADMIN = { username: 'tck_atlas', password: process.env.E2E_PASSWORD || 'TckAtlas2026!' }

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	for (const [section, target] of [
		['stations', '[data-tck-settings="stations"] button[type="submit"]'],
		['license', '[data-tck-license] button[type="submit"]'],
		['retention', '[data-tck-settings="retention"] button[type="submit"]'],
	]) {
		test(`settings ${section} scrolls to its last control`, async ({ page }) => {
			await page.setViewportSize({ width: 1280, height: 640 })
			await login(page, ADMIN)
			await gotoApp(page, `/index.php/apps/tourcheck/settings/${section}`)
			await assertAtlasVerticalScrollReachable(page, {
				scrollport: '#app-content',
				target,
				bottomSlopPx: 12,
			})
		})

		test(`settings ${section} stays reachable at phone height`, async ({ page }) => {
			await page.setViewportSize({ width: 390, height: 667 })
			await login(page, ADMIN)
			await gotoApp(page, `/index.php/apps/tourcheck/settings/${section}`)
			await assertAtlasVerticalScrollReachable(page, {
				scrollport: '#app-content',
				target,
				bottomSlopPx: 16,
			})
		})
	}
})
