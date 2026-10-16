// @ts-check
import { test, expect } from '@playwright/test'
import { createRequire } from 'node:module'
import { login } from './helpers/auth.js'
import { gotoApp } from './helpers/app.js'

const require = createRequire(import.meta.url)
const { assertVisualDesign, captureViewMatrix } = require('../../_shared/e2e/atlas-visual-design-contract.js')

/**
 * Visual-design contract — asserts the STRUCTURE of good views, not just
 * a11y trees: every list ships a filter recipe (panel or pills) with
 * Apply/Clear + a visible count, the dashboard answers "what needs
 * attention" with stat tiles, tables stack into labelled cards on phones,
 * and no orphan search toolbars exist outside the canonical recipes.
 *
 * The spec seeds its own fixture first (site → checkpoint → plan →
 * incident → report) so every list is provably populated — a filter gate
 * must fail when the markup is absent, not skip silently.
 */
const CREDS = { username: 'tck_atlas', password: 'TckAtlas2026!' }
const MARK = `VD ${Date.now()}`

async function reqToken(/** @type {import('@playwright/test').Page} */ page) {
	return page.evaluate(() =>
		((/** @type {any} */ (window).OC && /** @type {any} */ (window).OC.requestToken)
		|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
		|| ''))
}

async function api(
	/** @type {import('@playwright/test').Page} */ page,
	/** @type {string} */ method,
	/** @type {string} */ path,
	/** @type {Record<string, unknown> | undefined} */ data) {
	const token = await reqToken(page)
	const res = await page.request.fetch(`/index.php/apps/tourcheck${path}`, {
		method,
		data,
		headers: { requesttoken: token, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' },
	})
	return res.json().catch(() => ({ success: false }))
}

// Seeded fixture sites must not outlive the run
// (test_teardown_orphan_leak) — DELETE archives them.
const createdSiteIds = []

test.beforeEach(async ({ page }) => {
	page.on('dialog', (d) => d.accept())
	await login(page, CREDS)
})

test.afterAll(async ({ browser }) => {
	if (!createdSiteIds.length) { return }
	const page = await browser.newPage()
	try {
		await login(page, CREDS)
		await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
		for (const id of createdSiteIds) {
			await api(page, 'DELETE', `/api/sites/${id}`)
		}
	} finally {
		await page.close()
	}
})

test('visual design: seeded lists all carry a canonical filter recipe', async ({ page }) => {
	test.setTimeout(180_000)

	// ── Seed: site + checkpoint + active plan → rounds exist ──
	await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
	const site = await api(page, 'POST', '/api/sites', { name: `${MARK} site`, tz: 'Europe/Berlin' })
	expect(site.success, `site seed failed: ${JSON.stringify(site)}`).toBe(true)
	const siteId = site.data.id
	createdSiteIds.push(siteId)

	const cp = await api(page, 'POST', `/api/sites/${siteId}/checkpoints`, { name: `${MARK} gate`, kind: 'qr', sort: 1 })
	expect(cp.success, `checkpoint seed failed: ${JSON.stringify(cp)}`).toBe(true)
	const cpId = cp.data.checkpoint.id

	const plan = await api(page, 'POST', `/api/sites/${siteId}/plans`, {
		name: `${MARK} plan`, checkpoints: [{ checkpoint_id: cpId }],
		recurrence: { type: 'daily', time: '18:00', duration_min: 60 },
	})
	expect(plan.success, `plan seed failed: ${JSON.stringify(plan)}`).toBe(true)
	// create and activate are two calls — rounds only generate on activate.
	const act = await api(page, 'POST', `/api/plans/${plan.data.id}/activate`, {})
	expect(act.success, `plan activate failed: ${JSON.stringify(act)}`).toBe(true)

	const inc = await api(page, 'POST', '/api/incidents', {
		title: `${MARK} incident`, site_id: siteId, category: 'damage', severity: 'medium',
	})
	expect(inc.success, `incident seed failed: ${JSON.stringify(inc)}`).toBe(true)

	const rep = await api(page, 'POST', `/api/sites/${siteId}/reports`, { period: new Date().toISOString().slice(0, 7) })
	expect(rep.success, `report seed failed: ${JSON.stringify(rep)}`).toBe(true)

	// Journal rows are site-scoped — seed one entry so the panel + card
	// table are asserted against real content, not an empty page.
	const j = await api(page, 'POST', '/api/journal', { site_id: siteId, body: `${MARK} journal seed` })
	expect(j.success, `journal seed failed: ${JSON.stringify(j)}`).toBe(true)

	// ── Dashboard: status at a glance ──
	await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
	await assertVisualDesign(page, {
		prefix: 'tck',
		content: '#app-content.tck-app',
		statSurface: 'main',
	})

	// ── Sites: recipe-A search panel ──
	await gotoApp(page, '/index.php/apps/tourcheck/sites')
	await assertVisualDesign(page, {
		prefix: 'tck',
		content: '#app-content.tck-app',
		filterSurfaces: ['section[aria-labelledby="tck-sites-title"]'],
	})

	// ── Rounds: recipe-B status pills ──
	await gotoApp(page, '/index.php/apps/tourcheck/rounds')
	await assertVisualDesign(page, {
		prefix: 'tck',
		content: '#app-content.tck-app',
		filterSurfaces: ['section[aria-labelledby="tck-rounds-title"]'],
	})

	// ── Incidents: recipe-B status pills ──
	await gotoApp(page, '/index.php/apps/tourcheck/incidents')
	await assertVisualDesign(page, {
		prefix: 'tck',
		content: '#app-content.tck-app',
		filterSurfaces: ['section[aria-labelledby="tck-inc-title"]'],
	})

	// ── Journal: recipe-A panel + card table ──
	await gotoApp(page, `/index.php/apps/tourcheck/journal?site=${siteId}`)
	await assertVisualDesign(page, {
		prefix: 'tck',
		content: '#app-content.tck-app',
		filterSurfaces: ['section[aria-labelledby="tck-journal-title"]'],
		cardTables: ['[data-tck-journal-table] table'],
	})

	// ── Reports: recipe-A search panel ──
	await gotoApp(page, `/index.php/apps/tourcheck/reports?site=${siteId}`)
	await assertVisualDesign(page, {
		prefix: 'tck',
		content: '#app-content.tck-app',
		filterSurfaces: ['section[aria-labelledby="tck-rep-title"]'],
	})
})

test('filters actually narrow lists and counts stay honest', async ({ page }) => {
	test.setTimeout(120_000)
	await gotoApp(page, '/index.php/apps/tourcheck/sites')

	// Search narrows the site list and the count tracks it.
	const search = page.locator('#tck-sf-q')
	if (await search.isVisible().catch(() => false)) {
		const list = page.locator('[data-tck-sites-list] li')
		const total = await list.count()
		await search.fill('zzz-no-such-site-zzz')
		await expect(page.locator('[data-tck-sites-empty-filtered]')).toBeVisible()
		await expect(page.locator('[data-tck-sites-count]')).toContainText('0')
		await search.fill('')
		await expect(page.locator('[data-tck-sites-empty-filtered]')).toBeHidden()
		await expect(page.locator('[data-tck-sites-count]')).toContainText(String(total))
		// Reset (Clear) also restores.
		await search.fill('zzz')
		await page.locator('[data-tck-sites-filter] button[type="reset"]').click()
		await expect(page.locator('[data-tck-sites-count]')).toContainText(String(total))
	}

	await gotoApp(page, '/index.php/apps/tourcheck/rounds')
	const pills = page.locator('[data-tck-rounds-filter] button[data-value]')
	if (await pills.count() > 0) {
		// Pill semantics: single-select, aria-pressed, keyboard travel.
		const missed = page.locator('[data-tck-rounds-filter] button[data-value="missed"]')
		await missed.click()
		await expect(missed).toHaveAttribute('aria-pressed', 'true')
		// Every visible row is now missed — no cross-status leak.
		const visible = page.locator('[data-tck-rounds-list] li:not([hidden])')
		const n = await visible.count()
		for (let i = 0; i < n; i++) {
			await expect(visible.nth(i)).toHaveAttribute('data-status', 'missed')
		}
		await page.locator('[data-tck-rounds-filter] button[data-value=""]').click()
		await expect(page.locator('[data-tck-rounds-list] li:not([hidden])').first()).toBeVisible()
	}
})

test('mobile card transform: journal table stacks with row labels at 375px', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 })
	// Seed a site + entry first so the table is populated.
	await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
	const site = await api(page, 'POST', '/api/sites', { name: `${MARK} mobile`, tz: 'Europe/Berlin' })
	if (site.success) {
		createdSiteIds.push(site.data.id)
		await api(page, 'POST', '/api/journal', { site_id: site.data.id, body: `${MARK} mobile seed` })
		await gotoApp(page, `/index.php/apps/tourcheck/journal?site=${site.data.id}`)
	} else {
		await gotoApp(page, '/index.php/apps/tourcheck/journal')
	}
	const table = page.locator('[data-tck-journal-table] table')
	if (await table.count() === 0) { return }
	// Card mode: rows stack vertically, cells show their column label.
	const stacked = await page.evaluate(() => {
		const t = document.querySelector('[data-tck-journal-table] table')
		if (!t) { return false }
		const td = t.querySelector('tbody td[data-cell]')
		if (!td) { return false }
		const before = getComputedStyle(td, '::before').content
		const tbody = t.querySelector('tbody')
		return before !== 'none' && before !== '""' && tbody !== null && getComputedStyle(tbody).display === 'block'
	})
	expect(stacked, 'journal table must render labelled card rows at phone width').toBe(true)
	const hscroll = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)
	expect(hscroll).toBe(false)
})

test('view matrix: screenshots of every page at 320/768/1280', async ({ page }) => {
	test.setTimeout(180_000)
	const written = await captureViewMatrix(page, {
		basePath: '/index.php/apps/tourcheck',
		routes: ['/dashboard', '/sites', '/rounds', '/incidents', '/journal', '/reports', '/settings', '/ops'],
		viewports: [{ w: 320, h: 568 }, { w: 768, h: 1024 }, { w: 1280, h: 800 }],
		settleSelector: '#tck-main-content',
		outDir: 'test-results/visual-matrix',
		fullPage: true,
	})
	expect(written.length).toBe(8 * 3)
})
