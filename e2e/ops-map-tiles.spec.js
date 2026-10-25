// @ts-check
/**
 * Ops live-map tiles: EVERY provider selection must actually render tiles.
 * The page's img-src CSP has to name each host the configured template can
 * resolve to — a `*.host` source never matches the apex, which is exactly
 * how OSM tiles used to be blocked while the URL looked whitelisted.
 *
 * Tile requests are intercepted (no external CDN dependency). A CSP-blocked
 * <img> never reaches the network stack, so an observed request is itself
 * proof img-src allowed it; naturalWidth>0 proves the bytes decoded.
 */
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'
import { gotoApp, apiFetch } from './helpers/app.js'

const ADMIN = { username: 'tck_atlas', password: process.env.E2E_PASSWORD || 'TckAtlas2026!' }
const APP = '/index.php/apps/tourcheck'
const OPS = `${APP}/ops`
const PIXEL = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64',
)

async function setTiles(page, body) {
	const res = await apiFetch(page, 'POST', '/api/admin/settings', body)
	expect(res.success).toBe(true)
}

async function stubTiles(page, origin) {
	let hits = 0
	await page.route(`${origin}/**`, (route) => {
		hits++
		return route.fulfill({ status: 200, contentType: 'image/png', body: PIXEL })
	})
	return () => hits
}

/** Wait until at least one tile <img> has decoded pixels. */
async function expectTilesLoaded(page) {
	await expect(page.locator('img.leaflet-tile').first()).toBeAttached()
	await page.waitForFunction(
		() => [...document.querySelectorAll('img.leaflet-tile')].some((i) => i.naturalWidth > 0),
	)
}

test.beforeEach(async ({ page }) => {
	await login(page, ADMIN)
	await gotoApp(page, `${APP}/dashboard`)
})

test.afterEach(async ({ page }) => {
	await apiFetch(page, 'POST', '/api/admin/settings', { tile_provider: 'schematic' }).catch(() => {})
})

test.describe('ops map tiles', () => {
	test('schematic selection renders grid + hint, no tile images', async ({ page }) => {
		await setTiles(page, { tile_provider: 'schematic' })
		await gotoApp(page, OPS)
		await expect(page.locator('#tck-map')).toBeVisible()
		// degrade-never-blank: the schematic canvas carries a grid, not a void
		await expect(page.locator('#tck-map')).toHaveClass(/tck-map--schematic/)
		const bg = await page.locator('#tck-map').evaluate((el) => getComputedStyle(el).backgroundImage)
		expect(bg).toContain('repeating-linear-gradient')
		await expect(page.locator('#tck-map-schematic')).toBeVisible()
		await expect(page.locator('img.leaflet-tile')).toHaveCount(0)
	})

	test('OSM selection: CSP names the apex host and tile images load', async ({ page }) => {
		await setTiles(page, { tile_provider: 'osm' })
		const hits = await stubTiles(page, 'https://tile.openstreetmap.org')
		const resp = await page.goto(OPS, { waitUntil: 'domcontentloaded' })
		const csp = (resp && resp.headers()['content-security-policy']) || ''
		expect(csp).toContain('https://tile.openstreetmap.org')
		await page.waitForSelector('#tck-main-content', { timeout: 10_000 })
		await expectTilesLoaded(page)
		expect(hits()).toBeGreaterThan(0)
		await expect(page.locator('#tck-map-schematic')).toBeHidden()
		await expect(page.locator('#tck-map')).not.toHaveClass(/tck-map--schematic/)
		// OSM policy needs a Referer — tile <img>s relax the page's no-referrer to origin
		await expect(page.locator('img.leaflet-tile').first())
			.toHaveAttribute('referrerpolicy', 'strict-origin-when-cross-origin')
	})

	test('custom provider: its host is whitelisted and tiles render', async ({ page }) => {
		await setTiles(page, {
			tile_provider: 'custom',
			tile_url: 'https://tiles.example.com/{z}/{x}/{y}.png',
			tile_attribution: '© Example Maps',
		})
		const hits = await stubTiles(page, 'https://tiles.example.com')
		const resp = await page.goto(OPS, { waitUntil: 'domcontentloaded' })
		const csp = (resp && resp.headers()['content-security-policy']) || ''
		expect(csp).toContain('https://tiles.example.com')
		await page.waitForSelector('#tck-main-content', { timeout: 10_000 })
		await expectTilesLoaded(page)
		expect(hits()).toBeGreaterThan(0)
	})

	test('unreachable provider degrades to the schematic grid — never blank', async ({ page }) => {
		await setTiles(page, {
			tile_provider: 'custom',
			tile_url: 'https://tiles.example.com/{z}/{x}/{y}.png',
		})
		await page.route('https://tiles.example.com/**', (route) => route.abort('failed'))
		await gotoApp(page, OPS)
		// several failed tiles → layer dropped, schematic takes over, user told why
		await expect(page.locator('#tck-map')).toHaveClass(/tck-map--schematic/, { timeout: 15_000 })
		await expect(page.locator('#tck-map-schematic')).toBeVisible()
		await expect(page.locator('img.leaflet-tile')).toHaveCount(0)
		// geometry still renders on the grid — the surface is not dead
		await expect(page.locator('.leaflet-overlay-pane svg')).toBeAttached()
	})

	test('tiles survive a site switch; deep link preselects the site', async ({ page }) => {
		await setTiles(page, { tile_provider: 'osm' })
		await stubTiles(page, 'https://tile.openstreetmap.org')
		await gotoApp(page, `${OPS}/1408`)
		const sel = page.locator('#tck-site-select')
		if (await sel.count()) {
			await expect(sel).toHaveValue('1408')
		}
		await expectTilesLoaded(page)
		if (await sel.count()) {
			await sel.selectOption('1')
			await expectTilesLoaded(page)
			// same template → one layer reused, never stacked
			await expect(page.locator('.leaflet-tile-pane .leaflet-layer')).toHaveCount(1)
		}
	})
})
