// @ts-check
/**
 * Every settings section must render its own partial — never the
 * "Unknown settings section" fallback. Guards the slug→partial-name
 * lookup (site-defaults' hyphenated partial was unreachable for months:
 * preg_replace stripped the dash, the capture spec only proved pixels).
 * Selectors are structural; no locale text is matched.
 */
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'
import { gotoApp } from './helpers/app.js'

const ADMIN = { username: 'tck_atlas', password: process.env.E2E_PASSWORD || 'TckAtlas2026!' }

const SECTIONS = [
	['site-defaults', 'form[data-tck-settings="site"]'],
	['evidence', 'form[data-tck-settings="evidence"]'],
	['retention', 'form[data-tck-settings="retention"]'],
	['branding', 'form[data-tck-settings="branding"]'],
	['stations', 'form[data-tck-settings="stations"]'],
	['access', 'form[data-tck-settings="access"]'],
	['license', 'form[data-tck-license]'],
	['feedback', '#tck-fb-title'],
]

test.beforeEach(async ({ page }) => {
	await login(page, ADMIN)
})

test.describe('settings sections render their partials', () => {
	for (const [section, marker] of SECTIONS) {
		test(`settings/${section} renders its own partial`, async ({ page }) => {
			await gotoApp(page, `/index.php/apps/tourcheck/settings/${section}`)
			await expect(page.locator(marker)).toBeVisible()
		})
	}
})
