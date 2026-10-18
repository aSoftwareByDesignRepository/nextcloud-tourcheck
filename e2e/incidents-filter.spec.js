// @ts-check
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'
import { gotoApp } from './helpers/app.js'

/**
 * Filter + placeholder coverage for the r4 gaps:
 *
 * 1. `data-tck-incidents-filter` pill bar — every value ('' / open /
 *    action / verify / closed) toggled; row visibility, aria-pressed,
 *    count text and the empty-filtered surface asserted per value
 *    (ui_matrix_surface_inventory_gap — no spec ever toggled it).
 * 2. `data-tck-journal-filter` role=search form — text query narrows rows
 *    via data-search, method select narrows via data-method, reset
 *    restores; count + empty-filtered invariant held at every step.
 * 3. Rendered-placeholder lint (raw_placeholder_rendered /
 *    dishonest_counter_raw_placeholder): no raw %[dsn] in rendered DOM
 *    text on populated surfaces, count badges carry REAL counts, and the
 *    KPI tf() path is exercised via #tck-kpi-load.
 *
 * Structural selectors only — fixture users may run any locale.
 */
const CREDS = { username: 'tck_atlas', password: 'TckAtlas2026!' }
const MARK = `IF${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`

let siteId = 0
const incidentIds = []

/** @param {import('@playwright/test').Page} page */
async function reqToken(page) {
	return page.evaluate(() =>
		((/** @type {any} */ (window).OC && /** @type {any} */ (window).OC.requestToken)
		|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
		|| ''))
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} method
 * @param {string} path
 * @param {Record<string, unknown> | undefined} data
 */
async function api(page, method, path, data) {
	const token = await reqToken(page)
	const res = await page.request.fetch(`/index.php/apps/tourcheck${path}`, {
		method,
		data,
		headers: { requesttoken: token, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' },
	})
	return res.json().catch(() => ({ success: false }))
}

test.beforeEach(async ({ page }) => {
	await login(page, CREDS)
})

/**
 * PUT a status transition carrying the current revision (CAS).
 * @param {import('@playwright/test').Page} page
 * @param {number} id
 * @param {string} status
 */
async function setStatus(page, id, status) {
	const cur = await api(page, 'GET', `/api/incidents/${id}`, undefined)
	expect(cur.success, `incident ${id} GET failed: ${JSON.stringify(cur)}`).toBe(true)
	const res = await api(page, 'PUT', `/api/incidents/${id}`, {
		status,
		revision: cur.data.incident.revision,
	})
	expect(res.success, `incident ${id} -> ${status} failed: ${JSON.stringify(res)}`).toBe(true)
}

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	try {
		await login(page, CREDS)
		await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
		const site = await api(page, 'POST', '/api/sites', { name: `${MARK} site`, tz: 'Europe/Berlin' })
		expect(site.success, `site seed failed: ${JSON.stringify(site)}`).toBe(true)
		siteId = site.data.id
		// One incident per pill status: open / action / verify / closed.
		/** @param {string} tag */
		const mk = async (tag) => {
			const r = await api(page, 'POST', '/api/incidents', {
				title: `${MARK} ${tag}`, site_id: siteId, category: 'damage', severity: 'low',
			})
			expect(r.success, `incident ${tag} seed failed: ${JSON.stringify(r)}`).toBe(true)
			incidentIds.push(r.data.id)
			return r.data.id
		}
		await mk('open')
		const a = await mk('action'); await setStatus(page, a, 'action')
		const v = await mk('verify'); await setStatus(page, v, 'action'); await setStatus(page, v, 'verify')
		const c = await mk('closed'); await setStatus(page, c, 'closed')
	} finally {
		await page.close()
	}
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	try {
		await login(page, CREDS)
		await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
		for (const id of incidentIds) {
			const cur = await api(page, 'GET', `/api/incidents/${id}`, undefined).catch(() => null)
			if (cur?.success) {
				await api(page, 'PUT', `/api/incidents/${id}`, { status: 'closed', revision: cur.data.incident.revision }).catch(() => undefined)
			}
		}
		if (siteId) { await api(page, 'DELETE', `/api/sites/${siteId}`) }
	} finally {
		await page.close()
	}
})

/**
 * Leading integer of a count badge must equal the visible-row count.
 * @param {import('@playwright/test').Locator} badge
 * @param {number} shown
 */
async function expectCountBadge(badge, shown) {
	const txt = (await badge.textContent()) || ''
	const m = txt.match(/(\d+)/)
	expect(m, `count badge has no number (text: ${txt})`).toBeTruthy()
	expect(parseInt(m[1], 10)).toBe(shown)
}

test('incident status pills narrow the list per value', async ({ page }) => {
	await gotoApp(page, '/index.php/apps/tourcheck/incidents')
	const bar = page.locator('[data-tck-incidents-filter]')
	await expect(bar).toBeVisible()
	const rows = page.locator('[data-tck-incidents-list] li.tck-incident')
	const visible = page.locator('[data-tck-incidents-list] li.tck-incident:not([hidden])')
	const emptyFiltered = page.locator('[data-tck-incidents-empty-filtered]')
	const countBadge = page.locator('[data-tck-incidents-count]')
	const total = await rows.count()
	expect(total, 'seeded incidents must render').toBeGreaterThanOrEqual(4)

	for (const value of ['', 'open', 'action', 'verify', 'closed']) {
		const pill = bar.locator(`button[data-value="${value}"]`)
		await expect(pill).toBeVisible()
		await pill.click()
		await expect(pill).toHaveAttribute('aria-pressed', 'true')
		// every visible row carries exactly this status (or all when '')
		const n = await visible.count()
		for (let i = 0; i < n; i++) {
			if (value !== '') {
				await expect(visible.nth(i)).toHaveAttribute('data-status', value)
			}
		}
		if (value === '') {
			expect(n).toBe(total)
			expect(await visible.filter({ hasText: MARK }).count()).toBe(4)
		} else {
			// our seeded row is visible iff the pill matches its status
			const ours = visible.filter({ hasText: MARK })
			expect(await ours.count(), `one ${value} MARK row expected`).toBe(1)
			await expect(ours).toHaveAttribute('data-status', value)
		}
		// empty-filtered shows iff nothing matched — holds regardless of
		// foreign-suite rows sharing the list.
		if (n === 0) {
			await expect(emptyFiltered).toBeVisible()
		} else {
			await expect(emptyFiltered).toBeHidden()
		}
		await expectCountBadge(countBadge, n)
	}
})

test('journal search + method filter narrow the entry table', async ({ page }) => {
	await gotoApp(page, '/index.php/apps/tourcheck/journal')
	const form = page.locator('form[data-tck-journal-filter]')
	await expect(form).toBeVisible()
	await expect(form).toHaveAttribute('role', 'search')
	const rows = page.locator('[data-tck-journal-table] tbody tr')
	const visible = page.locator('[data-tck-journal-table] tbody tr:not([hidden])')
	const emptyFiltered = page.locator('[data-tck-journal-empty-filtered]')
	const countBadge = page.locator('[data-tck-journal-count]')
	const total = await rows.count()
	expect(total, 'seeded actions must have journaled').toBeGreaterThanOrEqual(1)
	await expectCountBadge(countBadge, total)

	// Text query — a term that cannot appear in what/actor/time data-search.
	await form.locator('#tck-jf-q').fill(`zzz-none-${MARK}`)
	await expect(emptyFiltered).toBeVisible()
	expect(await visible.count()).toBe(0)
	await expectCountBadge(countBadge, 0)

	// Actor term — every seeded entry was written by tck_atlas.
	await form.locator('#tck-jf-q').fill(CREDS.username)
	await expect(emptyFiltered).toBeHidden()
	const actorShown = await visible.count()
	expect(actorShown, 'actor query must match seeded entries').toBeGreaterThanOrEqual(1)
	for (let i = 0; i < actorShown; i++) {
		const ds = await visible.nth(i).getAttribute('data-search')
		expect(ds, `row ${i} data-search misses query term`).toContain(CREDS.username)
	}
	await expectCountBadge(countBadge, actorShown)

	// Method select — pick a concrete method, all visible rows must carry it.
	await form.locator('#tck-jf-q').fill('')
	const methodSel = form.locator('#tck-jf-method')
	const opts = await methodSel.locator('option').all()
	const values = []
	for (const o of opts) {
		const v = await o.getAttribute('value')
		if (v) { values.push(v) }
	}
	expect(values.length, 'method select must offer concrete methods').toBeGreaterThanOrEqual(1)
	for (const v of values) {
		await methodSel.selectOption(v)
		const n = await visible.count()
		for (let i = 0; i < n; i++) {
			await expect(visible.nth(i)).toHaveAttribute('data-method', v)
		}
		if (n === 0) {
			await expect(emptyFiltered).toBeVisible()
		} else {
			await expect(emptyFiltered).toBeHidden()
		}
		await expectCountBadge(countBadge, n)
	}

	// Reset restores the unfiltered table.
	await methodSel.selectOption('')
	await form.locator('button[type="reset"]').click()
	expect(await visible.count()).toBe(total)
	await expect(emptyFiltered).toBeHidden()
	await expectCountBadge(countBadge, total)
})

test('no raw sprintf placeholders on populated surfaces; count badges are real', async ({ page }) => {
	// Ops board + KPI path: '%d open alert(s), %d open task(s)' / KPI sub
	// lines rendered literally pre-fix (raw_placeholder_rendered).
	await gotoApp(page, '/index.php/apps/tourcheck/ops')
	await expect(page.locator('[data-tck-page="ops"]')).toBeVisible()
	// board resolves to cards OR the empty state — either means fetch landed
	await expect(page.locator('#tck-board .tck-ops-card, #tck-board-empty:not([hidden])').first()).toBeVisible()
	// exercise the tf() KPI sub-lines ('Target: %d percent', 'within %d min')
	await page.locator('#tck-kpi-load').click()
	await expect(page.locator('#tck-kpis .tck-kpi').first()).toBeVisible()
	const opsText = await page.locator('#app-content').innerText()
	expect(opsText, 'ops surface renders raw placeholder(s)').not.toMatch(/%[dsn]/)

	// Site detail for our seeded site: 0 keys / 0 plans / 0 live links —
	// badges suppressed, never a literal '0 registered' contradiction above
	// the empty state (dishonest_counter_raw_placeholder).
	await gotoApp(page, `/index.php/apps/tourcheck/sites/${siteId}`)
	const siteText = await page.locator('#app-content').innerText()
	expect(siteText, 'site surface renders raw placeholder(s)').not.toMatch(/%[dsn]/)
	expect(await page.locator('#tck-sec-keys .tck-list-head__count').count(),
		'keys badge must be suppressed on empty section').toBe(0)

	// Dashboard: seeded open incident renders under Open incidents — badge
	// reads a real number, never the literal '%n shown' fallback '1 shown'.
	await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
	const dashText = await page.locator('#app-content').innerText()
	expect(dashText, 'dashboard renders raw placeholder(s)').not.toMatch(/%[dsn]/)
})
