// @ts-check
/**
 * Map-tile settings (site-defaults): the provider picker must exist, always
 * show the effective state selected (schematic = privacy default), reveal the
 * custom URL field only for "custom", and persist through the settings API.
 * Also guards the nav role badge (was an orphan footer line).
 * Selectors are structural; no locale text is matched.
 */
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'
import { gotoApp, apiFetch } from './helpers/app.js'

const ADMIN = { username: 'tck_atlas', password: process.env.E2E_PASSWORD || 'TckAtlas2026!' }
const PAGE = '/index.php/apps/tourcheck/settings/site-defaults'
const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'

test.beforeEach(async ({ page }) => {
	await login(page, ADMIN)
})

/** Reset to schematic through the web API (APCu: occ writes wouldn't reach FPM). */
async function resetTiles(page) {
	await apiFetch(page, 'POST', '/api/admin/settings', { tile_provider: 'schematic' })
}

test.afterEach(async ({ page }) => {
	await resetTiles(page).catch(() => {})
})

test.describe('map tiles settings', () => {
	test('provider picker renders with schematic selected by default', async ({ page }) => {
		await resetTiles(page)
		await gotoApp(page, PAGE)
		await expect(page.locator('input[name="tile_provider"]')).toHaveCount(3)
		await expect(page.locator('#tck-tile-schematic')).toBeChecked()
		// custom URL field is disclosed only when "custom" is picked
		await expect(page.locator('[data-tck-tile-custom]')).toBeHidden()
	})

	test('custom provider discloses the URL field; other providers hide it', async ({ page }) => {
		await gotoApp(page, PAGE)
		await page.locator('#tck-tile-custom').check()
		await expect(page.locator('[data-tck-tile-custom]')).toBeVisible()
		await expect(page.locator('#tck-tile-url')).toBeEnabled()
		await page.locator('#tck-tile-osm').check()
		await expect(page.locator('[data-tck-tile-custom]')).toBeHidden()
		await expect(page.locator('#tck-tile-url')).toBeDisabled()
	})

	test('selecting OpenStreetMap persists the canonical template', async ({ page }) => {
		await gotoApp(page, PAGE)
		await page.locator('#tck-tile-osm').check()
		await page.locator('form[data-tck-settings="site"] [type="submit"]').click()
		await expect(page.locator('.tck-toast:not(.tck-toast--error)')).toBeVisible()
		await gotoApp(page, PAGE)
		await expect(page.locator('#tck-tile-osm')).toBeChecked()
		const cfg = await apiFetch(page, 'GET', '/api/admin/settings')
		expect(cfg.data.tileProvider).toBe('osm')
		expect(cfg.data.tileUrl).toBe(OSM_URL)
	})

	test('custom with empty URL fails inline — never reaches the API', async ({ page }) => {
		await gotoApp(page, PAGE)
		await page.locator('#tck-tile-custom').check()
		await page.locator('form[data-tck-settings="site"] [type="submit"]').click()
		await expect(page.locator('#tck-tile-url')).toHaveAttribute('aria-invalid', 'true')
		await expect(page.locator('#tck-tile-url-error')).toBeVisible()
	})

	test('custom with malformed URL is rejected inline', async ({ page }) => {
		await gotoApp(page, PAGE)
		await page.locator('#tck-tile-custom').check()
		await page.locator('#tck-tile-url').fill('http://tiles.example.com/{z}/{x}/{y}.png')
		await page.locator('form[data-tck-settings="site"] [type="submit"]').click()
		await expect(page.locator('#tck-tile-url')).toHaveAttribute('aria-invalid', 'true')
	})

	test('custom provider persists URL + attribution and reselects on reload', async ({ page }) => {
		await gotoApp(page, PAGE)
		await page.locator('#tck-tile-custom').check()
		await page.locator('#tck-tile-url').fill('https://tiles.example.com/{z}/{x}/{y}.png')
		await page.locator('#tck-tile-attribution').fill('© Example Maps')
		await page.locator('form[data-tck-settings="site"] [type="submit"]').click()
		await expect(page.locator('.tck-toast:not(.tck-toast--error)')).toBeVisible()
		await gotoApp(page, PAGE)
		await expect(page.locator('#tck-tile-custom')).toBeChecked()
		await expect(page.locator('#tck-tile-url')).toHaveValue('https://tiles.example.com/{z}/{x}/{y}.png')
		await expect(page.locator('#tck-tile-attribution')).toHaveValue('© Example Maps')
	})

	test('server-side: tile_url alone behaves as custom; unknown provider is 422', async ({ page }) => {
		await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
		const ok = await apiFetch(page, 'POST', '/api/admin/settings', {
			tile_url: 'https://tiles.example.com/{z}/{x}/{y}.png',
		})
		expect(ok.success).toBe(true)
		const bad = await apiFetch(page, 'POST', '/api/admin/settings', {
			tile_provider: 'nonsense',
		})
		expect(bad.success).toBe(false)
		const badUrl = await apiFetch(page, 'POST', '/api/admin/settings', {
			tile_provider: 'custom',
			tile_url: 'https://tiles.example.com/{z}/{x}.png',
		})
		expect(badUrl.success).toBe(false)
		expect(badUrl.fields.tile_url).toBeTruthy()
	})

	test('nav shows the role as a brand badge — no orphan footer line', async ({ page }) => {
		await gotoApp(page, PAGE)
		await expect(page.locator('.tck-brand__role')).toBeVisible()
		await expect(page.locator('.tck-nav__footer')).toHaveCount(0)
	})
})
