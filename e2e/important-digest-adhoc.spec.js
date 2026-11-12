// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { execFileSync } from 'child_process'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { login } from './helpers/auth.js'
import { gotoApp } from './helpers/app.js'

/**
 * F1/F3/F4/F5 feature coverage (2026-11-12 customer asks):
 *
 * 1. `important` flag — API create/update (incl. the form-encoded
 *    'false' trap), ★ badge rendered on the incidents page, form
 *    checkbox + supervisor toggle + send button present.
 * 2. Send-to-customer — endpoint reachable, soft-fail/success shape.
 * 3. Ad-hoc round — POST /api/sites/{id}/rounds happy path (planId null,
 *    adhoc 1), cross-site checkpoint rejected, past start rejected,
 *    composer form on the site page.
 * 4. Digest settings — fields rendered on the site-defaults section.
 * 5. Logbook — important flag create/update via ops API.
 *
 * Structural selectors + API asserts only — locale-agnostic.
 */
const CREDS = { username: 'tck_atlas', password: 'TckAtlas2026!' }
const MARK = `IW${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`

let siteId = 0
let siteId2 = 0
let cpId = 0


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

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	try {
		await login(page, CREDS)
		await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
		const site = await api(page, 'POST', '/api/sites', { name: `${MARK} site`, tz: 'Europe/Berlin' })
		expect(site.success, `site seed failed: ${JSON.stringify(site)}`).toBe(true)
		siteId = site.data.id
		await api(page, 'PUT', `/api/sites/${siteId}`, { client_contact: 'kunde@example.test', revision: site.data.revision })
		const site2 = await api(page, 'POST', '/api/sites', { name: `${MARK} site2`, tz: 'Europe/Berlin' })
		expect(site2.success).toBe(true)
		siteId2 = site2.data.id
		const cp = await api(page, 'POST', `/api/sites/${siteId}/checkpoints`, { name: `${MARK} gate` })
		expect(cp.success, `checkpoint seed failed: ${JSON.stringify(cp)}`).toBe(true)
		cpId = cp.data.checkpoint.id
	} finally {
		await page.close()
	}
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	try {
		await login(page, CREDS)
		await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
		for (const id of [siteId, siteId2]) {
			if (id) { await api(page, 'DELETE', `/api/sites/${id}`) }
		}
	} finally {
		await page.close()
	}
	// Site DELETE archives — it never removes round rows. The adhoc
	// fixtures this spec created stay 'scheduled' on the archived sites and
	// would sit in the DriveSegments "unclaimed" gap-suppression set
	// forever (test_teardown_orphan_leak). Remove exactly this run's rows.
	const ids = [siteId, siteId2].filter((n) => n > 0)
	if (ids.length) {
		const nextcloudRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')
		const sql =
			`DELETE rc FROM oc_tck_round_cp rc JOIN oc_tck_round r ON rc.round_id=r.id WHERE r.site_id IN (${ids.join(',')});` +
			` DELETE FROM oc_tck_round WHERE site_id IN (${ids.join(',')});`
		try {
			execFileSync(
				'docker',
				['compose', 'exec', '-T', 'mariadb', 'bash', '-lc',
					`mariadb -u root -p"\${MYSQL_ROOT_PASSWORD}" nextcloud -e '${sql}'`],
				{ cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 },
			)
		} catch (err) {
			process.stdout.write(`[afterAll] adhoc round cleanup failed (${err && err.message ? err.message : err})\n`)
		}
	}
})

test.beforeEach(async ({ page }) => {
	await login(page, CREDS)
	// API calls need OC.requestToken from a rendered app page — login() alone
	// can leave the page at about:blank.
	await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
})

test('F1: important flag create, strict parsing, toggle', async ({ page }) => {
	const inc = await api(page, 'POST', '/api/incidents', {
		title: `${MARK} flagged`, site_id: siteId, category: 'security', severity: 'high', important: true,
	})
	expect(inc.success, `incident create failed: ${JSON.stringify(inc)}`).toBe(true)
	const id = inc.data.id
	expect(inc.data.important).toBe(1)

	const bad = await api(page, 'POST', '/api/incidents', {
		title: `${MARK} bad`, site_id: siteId, category: 'security', severity: 'low', important: 'yes',
	})
	expect(bad.success).toBe(false)
	expect(bad.fields && bad.fields.important).toBeTruthy()

	// form-encoded 'false' must UNFLAG, not flag (string truthiness trap)
	const off = await api(page, 'PUT', `/api/incidents/${id}`, {
		important: 'false', revision: inc.data.revision,
	})
	expect(off.success, `flag toggle failed: ${JSON.stringify(off)}`).toBe(true)
	expect(off.data.important).toBe(0)

	const on = await api(page, 'PUT', `/api/incidents/${id}`, {
		important: true, revision: off.data.revision,
	})
	expect(on.success).toBe(true)
	expect(on.data.important).toBe(1)
})

test('F1: incidents page shows star badge, form checkbox + controls', async ({ page }) => {
	await gotoApp(page, '/index.php/apps/tourcheck/incidents')
	await expect(page.locator('#tck-inc-imp')).toHaveCount(1)
	const row = page.locator('li.tck-incident', { hasText: `${MARK} flagged` })
	await expect(row).toHaveCount(1)
	await expect(row.locator('.tck-badge', { hasText: 'Important' })).toHaveCount(1)
	await row.locator('[data-tck-incident-toggle]').click()
	await expect(row.locator('[data-tck-inc-important]')).toHaveCount(1)
	await expect(row.locator('[data-tck-inc-send]')).toHaveCount(1)
})

test('F3: send dialog — recipient field, prefill, inline error', async ({ page }) => {
	await gotoApp(page, '/index.php/apps/tourcheck/incidents')
	const row = page.locator('li.tck-incident', { hasText: `${MARK} flagged` })
	await row.locator('[data-tck-incident-toggle]').click()
	await row.locator('[data-tck-inc-send]').click()
	const dlg = page.locator('dialog[data-tck-send-dialog]')
	await expect(dlg).toHaveJSProperty('open', true)
	// Prefilled from the site's client_contact (seeded in beforeAll)
	await expect(dlg.locator('[data-tck-send-input]')).toHaveValue('kunde@example.test')
	// Invalid recipient → inline error, dialog stays open
	await dlg.locator('[data-tck-send-input]').fill('not-an-email')
	await dlg.locator('[data-tck-send-ok]').click()
	await expect(dlg.locator('[data-tck-send-error]')).toBeVisible()
	await expect(dlg).toHaveJSProperty('open', true)
	// Open modal + visible field error must stay WCAG-clean
	const axeRes = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
	const appOnly = axeRes.violations.filter(
		(v) => !v.nodes.some((n) => n.html.includes('unified-search')))
	expect(appOnly, `axe send-dialog: ${JSON.stringify(appOnly.map((v) => v.id))}`).toEqual([])
	// Escape cancels cleanly
	await page.keyboard.press('Escape')
	await expect(dlg).toHaveJSProperty('open', false)
})

test('F3: send incident to customer returns send outcome', async ({ page }) => {
	const inc = await api(page, 'POST', '/api/incidents', {
		title: `${MARK} send`, site_id: siteId, category: 'other', severity: 'low', important: true,
	})
	expect(inc.success, `incident create failed: ${JSON.stringify(inc)}`).toBe(true)
	const id = inc.data.id
	const res = await api(page, 'POST', `/api/incidents/${id}/send`, {})
	// Endpoint reachable + shaped; MailHog on the dev stack makes sent:true
	expect(res.success, `send failed: ${JSON.stringify(res)}`).toBe(true)
	expect(typeof res.data.sent).toBe('boolean')
	if (res.data.sent) {
		expect(res.data.sent_to).toContain('@')
	}
	const invalid = await api(page, 'POST', `/api/incidents/${id}/send`, { recipient: 'not-an-email' })
	expect(invalid.success).toBe(false)
	expect(invalid.fields && invalid.fields.recipient).toBeTruthy()
})

test('F5: ad-hoc round create, IDOR + past-start guards', async ({ page }) => {
	const startsAt = new Date(Date.now() + 3600_000).toISOString()
	const ok = await api(page, 'POST', `/api/sites/${siteId}/rounds`, {
		starts_at: startsAt, duration_min: 60, checkpoint_ids: [cpId],
	})
	expect(ok.success, `adhoc create failed: ${JSON.stringify(ok)}`).toBe(true)
	expect(ok.data.planId).toBeNull()
	expect(ok.data.adhoc).toBe(1)
	expect(ok.data.status).toBe('scheduled')

	const foreign = await api(page, 'POST', `/api/sites/${siteId2}/rounds`, {
		starts_at: startsAt, duration_min: 60, checkpoint_ids: [cpId],
	})
	expect(foreign.success).toBe(false)
	expect(foreign.fields && foreign.fields.checkpoint_ids).toBeTruthy()

	const past = await api(page, 'POST', `/api/sites/${siteId}/rounds`, {
		starts_at: new Date(Date.now() - 7200_000).toISOString(), duration_min: 60, checkpoint_ids: [cpId],
	})
	expect(past.success).toBe(false)
	expect(past.fields && past.fields.starts_at).toBeTruthy()

	const empty = await api(page, 'POST', `/api/sites/${siteId}/rounds`, {
		starts_at: startsAt, duration_min: 60, checkpoint_ids: [],
	})
	expect(empty.success).toBe(false)
	expect(empty.fields && empty.fields.checkpoint_ids).toBeTruthy()
})

test('F5: naive datetime-local is interpreted in the site timezone', async ({ page }) => {
	// Site tz is Europe/Berlin (CET/CEST). A naive 'YYYY-MM-DDTHH:mm' must
	// be stored with the Berlin offset, not UTC.
	const naive = '2030-01-15T09:00'
	const res = await api(page, 'POST', `/api/sites/${siteId}/rounds`, {
		starts_at: naive, duration_min: 30, checkpoint_ids: [cpId],
	})
	expect(res.success, `naive-tz create failed: ${JSON.stringify(res)}`).toBe(true)
	const stored = String(res.data.scheduledStart ?? res.data.scheduled_start ?? '')
	expect(stored).toContain('2030-01-15T09:00')
	expect(stored).toMatch(/\+01:00/)
})

test('F5: site page exposes the one-off round composer', async ({ page }) => {
	await gotoApp(page, `/index.php/apps/tourcheck/sites/${siteId}`)
	await page.locator('[data-tck-new-adhoc]').click()
	await expect(page.locator('#tck-adhoc-start')).toBeVisible()
	await expect(page.locator('#tck-adhoc-duration')).toBeVisible()
	await expect(page.locator(`#tck-adhoc-cp-${cpId}`)).toBeChecked()
})

test('F2: digest settings render on site-defaults', async ({ page }) => {
	await gotoApp(page, '/index.php/apps/tourcheck/settings/site-defaults')
	await expect(page.locator('#tck-digest-on')).toHaveCount(1)
	await expect(page.locator('#tck-digest-hour')).toHaveValue(/\d+/)
	await expect(page.locator('#tck-digest-mail')).toHaveCount(1)
})

test('F1: logbook important flag via ops API', async ({ page }) => {
	const lb = await api(page, 'POST', `/api/sites/${siteId}/logbook`, {
		kind: 'note', body: `${MARK} wichtig entry`, important: true,
	})
	expect(lb.success, `logbook create failed: ${JSON.stringify(lb)}`).toBe(true)
	expect(lb.data.important).toBe(1)
	const upd = await api(page, 'PUT', `/api/logbook/${lb.data.id}`, {
		body: `${MARK} wichtig entry`, important: false, revision: lb.data.revision,
	})
	expect(upd.success).toBe(true)
	expect(upd.data.important).toBe(0)
})
