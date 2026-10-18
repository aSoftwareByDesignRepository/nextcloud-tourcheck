// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/tourcheck/craft/web/. The companion gate
 * scripts/check-screenshot-matrix.py verifies completeness — a declared
 * matrix cell that produces no artifact fails the ready-gate craft stage.
 *
 * States:
 *   populated — seeded data visible (site→checkpoint→active plan→round,
 *               incident, report, journal row, live link, client grant)
 *   empty     — deterministic empty render: a no-match filter search
 *               (tourcheck list filters are JS-bound; there is no
 *               ?search= deep link). Pill-only lists (incidents, rounds)
 *               cannot empty deterministically — DB state decides which
 *               statuses have rows — so they declare populated only.
 *   current   — state-invariant chrome (settings sections, capture, kiosk,
 *               live public page)
 *
 * Extra craft (beyond matrix cells): open forms, field-error states, the
 * shared confirm dialog, light-highcontrast variants, the worker (empty
 * portfolio) view, the client portal and the uniform-404 door for
 * non-clients — all PNGs land in the same outDir and are recorded in
 * _manifest-ds-chrome.json.
 *
 * Run:
 *   npx playwright test e2e/capture-screenshot-matrix.spec.js
 */
import { test, expect } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login } from './helpers/auth.js'
import { gotoApp } from './helpers/app.js'
import { setUserTheme, resetUserTheme } from './helpers/theming.js'

const HERE = dirname(fileURLToPath(import.meta.url))
const MANIFEST = JSON.parse(readFileSync(join(HERE, 'screenshot-matrix.json'), 'utf8'))
const OUT = resolve(HERE, '../../../../.cursor/atlas-farm-v3/artifacts/tourcheck', MANIFEST.outDir)
const APP = '/index.php/apps/tourcheck'
const ADMIN = { username: 'tck_atlas', password: 'TckAtlas2026!' }
const WORKER = { username: 'tck_empty', password: 'TckEmpty2026!' }
const CLIENT = { username: 'tck_client1', password: 'TckClient2026!' }
const TS = Date.now()
const NO_MATCH = 'zz-nomatch-9x7q'

/** @type {{ siteId: number, roundId: number, liveToken: string, liveLinkId: number, incidentId: number, stationId: number, stationToken: string }} */
const seed = { siteId: 0, roundId: 0, liveToken: '', liveLinkId: 0, incidentId: 0, stationId: 0, stationToken: '' }
const written = []

async function reqToken(/** @type {import('@playwright/test').Page} */ page) {
	return page.evaluate(() =>
		(window.OC && window.OC.requestToken)
		|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
		|| '')
}

async function api(
	/** @type {import('@playwright/test').Page} */ page,
	/** @type {string} */ method,
	/** @type {string} */ path,
	/** @type {Record<string, unknown> | undefined} */ data) {
	const token = await reqToken(page)
	const res = await page.request.fetch(`${APP}${path}`, {
		method,
		data,
		headers: { requesttoken: token, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' },
	})
	return res.json().catch(() => ({ success: false }))
}

/**
 * `#app-content` scrolls internally — a viewport-height shot only captures
 * the fold. Grow the viewport to full content height before shooting.
 */
async function shot(/** @type {import('@playwright/test').Page} */ page, /** @type {string} */ name, /** @type {number} */ vpHeight) {
	const contentH = await page.evaluate(() =>
		(document.querySelector('#app-content') || document.documentElement).scrollHeight)
	const shotH = Math.min(Math.max(contentH + 60, vpHeight), 8192)
	if (shotH !== vpHeight) {
		await page.setViewportSize({ width: page.viewportSize()?.width || 1280, height: shotH })
		await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
	}
	const file = join(OUT, `${name}.png`)
	await page.screenshot({ path: file })
	const size = existsSync(file) ? statSync(file).size : 0
	expect(size, `${name}.png too small — likely blank/error frame`).toBeGreaterThan(4096)
	written.push({ id: name, path: `craft/web/${name}.png`, bytes: size })
}

/**
 * Reach the deterministic "empty" render on JS-bound filters: a no-match
 * search text on the recipe-A panels that have one.
 */
async function applyEmptyState(/** @type {import('@playwright/test').Page} */ page, /** @type {string} */ surfaceId) {
	// A filtered-empty render must hide the list itself — [hidden] beats
	// display:flex on .tck-list via the .tck-app [hidden] guard in tck.css.
	if (surfaceId === 'sites') {
		await page.locator('#tck-sf-q').fill(NO_MATCH)
		await page.locator('[data-tck-sites-filter] [type="submit"]').click()
		await expect(page.locator('[data-tck-sites-empty-filtered]')).toBeVisible()
		await expect(page.locator('[data-tck-sites-list]')).toBeHidden()
	} else if (surfaceId === 'journal') {
		await page.locator('#tck-jf-q').fill(NO_MATCH)
		await page.locator('[data-tck-journal-filter] [type="submit"]').click()
		await expect(page.locator('[data-tck-journal-empty-filtered]')).toBeVisible()
		await expect(page.locator('[data-tck-journal-table]')).toBeHidden()
	} else if (surfaceId === 'reports') {
		await page.locator('#tck-rf-q').fill(NO_MATCH)
		await page.locator('[data-tck-reports-filter] [type="submit"]').click()
		await expect(page.locator('[data-tck-reports-empty-filtered]')).toBeVisible()
		await expect(page.locator('[data-tck-reports-list]')).toBeHidden()
	}
}

function resolvePath(/** @type {string} */ p) {
	return p
		.replace('{siteId}', String(seed.siteId))
		.replace('{roundId}', String(seed.roundId))
		.replace('{liveToken}', seed.liveToken)
		.replace('{stationToken}', seed.stationToken)
}

function shotName(/** @type {string} */ sid, /** @type {string} */ state, /** @type {string} */ vpName, /** @type {string} */ theme) {
	return `${MANIFEST.prefix}-${sid}-${state}-${vpName}${theme === 'dark' ? '-dark' : ''}`
}

function* matrixCells(/** @type {string} */ theme) {
	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states) {
			for (const vp of MANIFEST.viewports) {
				if (theme === 'dark' && (!surface.dark || vp.name !== 'desktop' || state !== surface.states[0])) { continue }
				yield { surface, state, vp }
			}
		}
	}
}

test.describe('SCREENSHOT_MATRIX capture', () => {
	test.setTimeout(600_000)

	test.beforeAll(async ({ browser }) => {
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		try {
			await login(page, ADMIN)
			await gotoApp(page, `${APP}/dashboard`)

			const site = await api(page, 'POST', '/api/sites', { name: `MX ${TS} site`, tz: 'Europe/Berlin' })
			expect(site.success, `site seed: ${JSON.stringify(site)}`).toBe(true)
			seed.siteId = site.data.id

			const cp = await api(page, 'POST', `/api/sites/${seed.siteId}/checkpoints`, { name: `MX ${TS} gate`, kind: 'qr', sort: 1 })
			expect(cp.success, `checkpoint seed: ${JSON.stringify(cp)}`).toBe(true)
			const cpId = cp.data.checkpoint.id

			// Station for the /kiosk member-grid craft — station_token auth +
			// a device seat; revoked in afterAll so the license cap stays free.
			const st = await api(page, 'POST', `/api/sites/${seed.siteId}/stations`, { label: `MX ${TS} station` })
			expect(st.success, `station seed: ${JSON.stringify(st)}`).toBe(true)
			seed.stationId = st.data.station.id
			seed.stationToken = st.data.secret
			for (const [name, pin] of [['Ada Beispiel', '123456'], ['Boris Muster', '234567'], ['Clara Vorne', '345678']]) {
				const m = await api(page, 'PUT', `/api/stations/${seed.stationId}/members`, { display_name: name, pin })
				expect(m.success, `member seed ${name}: ${JSON.stringify(m)}`).toBe(true)
			}

			const plan = await api(page, 'POST', `/api/sites/${seed.siteId}/plans`, {
				name: `MX ${TS} plan`, checkpoints: [{ checkpoint_id: cpId }],
				recurrence: { type: 'daily', time: '18:00', duration_min: 60 },
			})
			expect(plan.success, `plan seed: ${JSON.stringify(plan)}`).toBe(true)
			const act = await api(page, 'POST', `/api/plans/${plan.data.id}/activate`, {})
			expect(act.success, `plan activate: ${JSON.stringify(act)}`).toBe(true)

			const inc = await api(page, 'POST', '/api/incidents', {
				title: `MX ${TS} incident`, site_id: seed.siteId, category: 'damage', severity: 'medium',
			})
			expect(inc.success, `incident seed: ${JSON.stringify(inc)}`).toBe(true)
			seed.incidentId = inc.data.id ?? inc.data.incident?.id ?? 0

			const rep = await api(page, 'POST', `/api/sites/${seed.siteId}/reports`, { period: new Date().toISOString().slice(0, 7) })
			expect(rep.success, `report seed: ${JSON.stringify(rep)}`).toBe(true)

			const j = await api(page, 'POST', '/api/journal', { site_id: seed.siteId, body: `MX ${TS} journal seed` })
			expect(j.success, `journal seed: ${JSON.stringify(j)}`).toBe(true)

			const live = await api(page, 'POST', `/api/sites/${seed.siteId}/live-links`, { label: `MX ${TS}` })
			expect(live.success, `live-link seed: ${JSON.stringify(live)}`).toBe(true)
			seed.liveToken = live.data.token
			seed.liveLinkId = live.data.link.id

			// Client portal scope for the seeded site → populated portal craft.
			const grant = await api(page, 'POST', `/api/sites/${seed.siteId}/client-grants`, { client_uid: CLIENT.username })
			expect(grant.success, `client grant: ${JSON.stringify(grant)}`).toBe(true)

			const rounds = await api(page, 'GET', `/api/rounds?site_id=${seed.siteId}`)
			const list = Array.isArray(rounds.data) ? rounds.data : (rounds.data?.rounds || [])
			expect(list.length, 'plan activation produced no rounds').toBeGreaterThan(0)
			seed.roundId = list[0].id
		} finally {
			await ctx.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		if (!seed.siteId) { return }
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		try {
			await login(page, ADMIN)
			await gotoApp(page, `${APP}/dashboard`)
			if (seed.liveLinkId) {
				await api(page, 'POST', `/api/live-links/${seed.liveLinkId}/revoke`, {})
			}
			if (seed.stationId) {
				await api(page, 'POST', `/api/stations/${seed.stationId}/revoke`, {})
			}
			await api(page, 'DELETE', `/api/sites/${seed.siteId}`)
			await api(page, 'DELETE', `/api/sites/${seed.siteId}/client-grants/${CLIENT.username}`)
			// Theme is per-user state — always hand the fixture user back light.
			await resetUserTheme(page).catch(() => {})
		} finally {
			await ctx.close()
		}
	})

	test('captures every manifest cell', async ({ page, browser }) => {
		mkdirSync(OUT, { recursive: true })
		await login(page, ADMIN)

		// Portal surfaces render for the client fixture, not the admin.
		let clientPage = null
		const pageFor = async (surface) => {
			if (!surface.clientUser) { return page }
			if (!clientPage) {
				const ctx = await browser.newContext()
				clientPage = await ctx.newPage()
				await login(clientPage, CLIENT)
			}
			return clientPage
		}

		// The theme is per-user server state — a killed dark run leaves the
		// fixture user dark, and the light pass would silently capture dark.
		// Navigate first: setUserTheme evaluates in-page and needs a real URL.
		await gotoApp(page, `${APP}/dashboard`, { contentSel: '#tck-main-content' })
		for (const theme of ['light', 'dark']) {
			await setUserTheme(page, theme)
			for (const { surface, state, vp } of matrixCells(theme)) {
				const target = await pageFor(surface)
				await target.setViewportSize({ width: vp.width, height: vp.height })
				await gotoApp(target, `${APP}${resolvePath(surface.path)}`, {
					contentSel: surface.settle || '#tck-main-content',
				})
				if (state === 'empty') { await applyEmptyState(target, surface.id) }
				await target.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
				await shot(target, shotName(surface.id, state, vp.name, theme), vp.height)
			}
		}
		if (clientPage) { await clientPage.context().close() }
	})

	test('captures dialogs, forms, error states and theme variants', async ({ page }) => {
		test.setTimeout(300_000)
		await login(page, ADMIN)
		await page.setViewportSize({ width: 1280, height: 800 })

		// ── Create forms revealed ──
		await gotoApp(page, `${APP}/sites`)
		await page.locator('[data-tck-new-site]').click()
		await expect(page.locator('#tck-site-form')).toBeVisible()
		await shot(page, 'tck-sites-form-desktop', 800)

		// Field-level errors: submit the empty required name.
		await page.locator('#tck-site-name').fill('')
		await page.locator('#tck-site-form button[type="submit"]').click()
		await expect(page.locator('#tck-site-name[aria-invalid="true"]')).toBeVisible()
		await expect(page.locator('#tck-site-name-error')).toBeVisible()
		await shot(page, 'tck-sites-form-errors-desktop', 800)
		await page.locator('#tck-site-form [data-tck-cancel]').click()

		// ── Site detail forms ──
		await gotoApp(page, `${APP}/sites/${seed.siteId}`)
		await page.locator('[data-tck-new-cp]').click()
		await expect(page.locator('#tck-cp-form')).toBeVisible()
		await shot(page, 'tck-site-checkpoint-form-desktop', 800)
		await page.locator('#tck-cp-form [data-tck-cancel]').click()

		await page.locator('[data-tck-new-plan]').click()
		await expect(page.locator('#tck-plan-form')).toBeVisible()
		await shot(page, 'tck-site-plan-form-desktop', 800)
		await page.locator('#tck-plan-form [data-tck-cancel]').click()

		// ── Shared confirm dialog (§3.9) on a destructive action ──
		await page.locator('[data-tck-archive-cp]').first().click()
		await expect(page.locator('dialog[data-tck-confirm-dialog][open]')).toBeVisible()
		await shot(page, 'tck-confirm-dialog-desktop', 800)
		await page.locator('dialog[data-tck-confirm-dialog] [data-tck-confirm-cancel]').click()
		await expect(page.locator('dialog[data-tck-confirm-dialog][open]')).toBeHidden()

		// ── Incident create form + field errors ──
		await gotoApp(page, `${APP}/incidents`)
		await page.locator('[data-tck-new-incident]').first().click()
		await expect(page.locator('#tck-incident-form')).toBeVisible()
		await shot(page, 'tck-incident-form-desktop', 800)
		await page.locator('#tck-incident-form button[type="submit"]').click()
		await expect(page.locator('#tck-incident-form [aria-invalid="true"]').first()).toBeVisible()
		await shot(page, 'tck-incident-form-errors-desktop', 800)
		await page.locator('#tck-incident-form [data-tck-cancel]').click()

		// ── Report generate + schedule forms ──
		await gotoApp(page, `${APP}/reports?site=${seed.siteId}`)
		await page.locator('[data-tck-new-report]').click()
		await expect(page.locator('#tck-report-form')).toBeVisible()
		await shot(page, 'tck-report-form-desktop', 800)
		await page.locator('#tck-report-form [data-tck-cancel]').click()

		// ── High-contrast variants (matrix covers dark at desktop) ──
		await setUserTheme(page, 'light-highcontrast')
		await gotoApp(page, `${APP}/dashboard`)
		await shot(page, 'tck-dashboard-current-desktop-hc', 800)
		await gotoApp(page, `${APP}/incidents`)
		await shot(page, 'tck-incidents-populated-desktop-hc', 800)
		await resetUserTheme(page)

		// ── Uniform-404 door: admin (non-client) must not see the portal ──
		await gotoApp(page, `${APP}/portal`, { contentSel: 'body', retries: 0 })
		await expect(page.locator('#tck-main-content')).toHaveCount(0)
		await shot(page, 'tck-portal-denied-desktop', 800)
	})

	test('captures the empty-portfolio worker view', async ({ browser }) => {
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		try {
			await login(page, WORKER)
			await page.setViewportSize({ width: 1280, height: 800 })
			await gotoApp(page, `${APP}/dashboard`, { contentSel: '#tck-main-content, #app-content' })
			await shot(page, 'tck-worker-dashboard-desktop', 800)
			await gotoApp(page, `${APP}/sites`, { contentSel: '#tck-main-content, #app-content' })
			await shot(page, 'tck-worker-sites-desktop', 800)
			await page.setViewportSize({ width: 320, height: 800 })
			await gotoApp(page, `${APP}/dashboard`, { contentSel: '#tck-main-content, #app-content' })
			await shot(page, 'tck-worker-dashboard-mobile', 800)
		} finally {
			await ctx.close()
		}
	})

	test.afterAll(async () => {
		if (!written.length) { return }
		writeFileSync(join(OUT, '_manifest-ds-chrome.json'), JSON.stringify({
			app: 'tourcheck',
			lane: 'ds_chrome',
			captured_at: new Date().toISOString(),
			manifest: 'nextcloud/apps/tourcheck/e2e/screenshot-matrix.json',
			spec: 'nextcloud/apps/tourcheck/e2e/capture-screenshot-matrix.spec.js',
			files: written,
		}, null, 1) + '\n')
	})
})
