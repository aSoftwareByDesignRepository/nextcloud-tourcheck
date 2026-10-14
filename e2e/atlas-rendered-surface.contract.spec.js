// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — asserts the *rendered* truth of each
 * app page surface: content lists keep markers, selects are vertically
 * centred, icons/SVGs have non-zero boxes, form controls aren't
 * text-centered by inherited shell alignment. DOM-level specs pass on
 * pages that are visually broken; this is the pixel-adjacent class.
 */
import { test } from '@playwright/test'
import { assertAtlasRenderedSurface } from '../../_shared/e2e/atlas-rendered-surface-contract.js'
import { login } from './helpers/auth.js'
import { gotoApp } from './helpers/app.js'

const ADMIN = { username: 'tck_atlas', password: process.env.E2E_PASSWORD || 'TckAtlas2026!' }

const SURFACES = [
	['/index.php/apps/tourcheck/', 'dashboard'],
	['/index.php/apps/tourcheck/sites', 'sites'],
	['/index.php/apps/tourcheck/rounds', 'rounds'],
	['/index.php/apps/tourcheck/journal', 'journal'],
	['/index.php/apps/tourcheck/incidents', 'incidents'],
	['/index.php/apps/tourcheck/reports', 'reports'],
	['/index.php/apps/tourcheck/settings', 'settings'],
]

/**
 * Intentionally marker-less lists: nav, breadcrumbs, card-row lists
 * (.tck-list items are bordered row cards, not bullet lists), coach steps,
 * kiosk member grid, action menus. Content bullet lists must NOT appear
 * here — they must show markers.
 */
const LIST_ALLOW = [
	'.tck-nav',
	'.tck-breadcrumb',
	'.tck-list',
	'.tck-coach__steps',
	'.tck-kiosk__grid',
	'.tck-menu',
	'.tck-actions',
	'.tck-modal',
].join(', ')

test.describe('Rendered-surface contract', () => {
	for (const [routePath, name] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${routePath})`, async ({ page }) => {
			await page.setViewportSize({ width: 1280, height: 800 })
			await login(page, ADMIN)
			await gotoApp(page, routePath)

			await assertAtlasRenderedSurface(page, {
				content: '#tck-main-content',
				navExclude: '#app-navigation, nav, .tck-nav, .tck-breadcrumb, .tck-modal',
				listAllow: LIST_ALLOW,
			})
		})
	}
})
