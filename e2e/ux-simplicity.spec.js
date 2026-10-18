// @ts-check
import { test, expect } from '@playwright/test'
import { AxeBuilder } from '@axe-core/playwright'
import { login } from './helpers/auth.js'

/**
 * UX-simplification proofs:
 * - empty-state auto-open: a marked create form opens itself, focused,
 *   and Cancel still collapses it (no trap);
 * - kiosk PIN pad honours the CONFIGURED pin length — a non-6 length used
 *   to render 6 dots and never submit: a guaranteed dead end.
 */
const CREDS = { username: 'tck_atlas', password: 'TckAtlas2026!' }

/**
 * Kiosk specs must never lean on seeded station ids — fixture rows get
 * revoked by parallel lanes/teardown. Seed our own station + member and
 * revoke in `finally` (active stations consume license seats).
 * @returns {Promise<{stationId: number, secret: string, memberId: number}>}
 */
async function seedKioskStation(page, apiHeaders, pin) {
	const mk = await page.request.post('/index.php/apps/tourcheck/api/sites/1/stations', {
		headers: apiHeaders,
		data: { label: `ux-kiosk-${Date.now()}-${Math.random().toString(36).slice(2, 7)}` },
	})
	expect(mk.ok(), 'seed kiosk station').toBeTruthy()
	const st = (await mk.json()).data
	// kiosk/confirm counts against the station's BOUND checkpoint — an
	// unbound station can never reach the confirm stage.
	const bind = await page.request.post(`/index.php/apps/tourcheck/api/stations/${st.station.id}/checkpoint`, {
		headers: apiHeaders,
		data: { site_id: 1, checkpoint_id: 1 },
	})
	expect(bind.ok(), 'bind station checkpoint').toBeTruthy()
	const up = await page.request.put(`/index.php/apps/tourcheck/api/stations/${st.station.id}/members`, {
		headers: apiHeaders,
		data: { display_name: 'Atlas Wache', pin },
	})
	expect(up.ok(), 'seed member PIN').toBeTruthy()
	return { stationId: st.station.id, secret: st.secret, memberId: (await up.json()).data.id }
}

async function revokeKioskStation(page, apiHeaders, stationId) {
	await page.request.post(`/index.php/apps/tourcheck/api/stations/${stationId}/revoke`, { headers: apiHeaders }).catch(() => {})
}

test.beforeEach(async ({ page }) => {
	page.on('dialog', (d) => d.accept())
	await login(page, CREDS)
})

test('empty-state guidance: marked create form auto-opens with focus, cancel still works', async ({ page }) => {
	// The server only emits data-tck-auto-open on truly empty lists; on the
	// seeded box we inject the marker to prove the client wiring end-to-end.
	await page.route('**/apps/tourcheck/sites', async (route) => {
		const res = await route.fetch()
		const body = (await res.text()).replace('data-tck-site-form', 'data-tck-site-form data-tck-auto-open')
		await route.fulfill({ response: res, body })
	})
	await page.goto('/index.php/apps/tourcheck/sites', { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#tck-site-form')).toBeVisible()
	await expect(page.locator('#tck-site-name')).toBeFocused()
	await page.locator('#tck-site-form [data-tck-cancel]').click()
	await expect(page.locator('#tck-site-form')).not.toBeVisible()
	// Toggle still re-opens — the button was never removed.
	await page.locator('[data-tck-new-site]').click()
	await expect(page.locator('#tck-site-form')).toBeVisible()
})

test('kiosk: configured PIN length renders N dots and auto-submits — no dead pad', async ({ page }) => {
	// pin_length=4 used to be a guaranteed dead end: the pad rendered 6 dots
	// and only submitted at length 6. Proves data-pin-len wiring + the full
	// confirm round-trip at a non-default length.
	try {
		await page.goto('/index.php/apps/tourcheck/dashboard', { waitUntil: 'domcontentloaded' })
		const token = await page.evaluate(() =>
			(window.OC && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '')
		const apiHeaders = { requesttoken: token, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }

		// Must go through the web API: appconfig is APCu-cached inside the
		// FPM process, so occ (CLI APCu) writes a value the web never sees.
		const cfg = await page.request.post('/index.php/apps/tourcheck/api/admin/settings', {
			headers: apiHeaders,
			data: { pin_length: 4 },
		})
		expect(cfg.ok(), 'set pin_length=4 via settings API').toBeTruthy()

		const stn = await seedKioskStation(page, apiHeaders, '1234')
		try {
		await page.goto(`/index.php/apps/tourcheck/kiosk?station_token=${encodeURIComponent(stn.secret)}`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-tck-kiosk]')).toHaveAttribute('data-pin-len', '4')
		await page.locator(`[data-tck-member="${stn.memberId}"]`).click()
		await expect(page.locator('[data-tck-stage="pin"]')).toBeVisible()
		await expect(page.locator('[data-tck-dots] > *')).toHaveCount(4)
		for (const d of ['1', '2', '3', '4']) {
			await page.locator(`[data-tck-key="${d}"]`).click()
		}
		// PIN-full → optional signature stage (NH-06 web/companion parity);
		// Skip carries no signature and lands on confirm. The old code dead-
		// ended on the pin stage at non-6 lengths.
		await expect(page.locator('[data-tck-stage="sign"]')).toBeVisible({ timeout: 15000 })
		await page.locator('[data-tck-sign-skip]').click()
		await expect(page.locator('[data-tck-stage="confirm"]')).toBeVisible({ timeout: 15000 })
		} finally {
			await revokeKioskStation(page, apiHeaders, stn.stationId)
		}
	} finally {
		// Restore the default through the same API so the FPM-side
		// appconfig cache is updated (occ would leave a stale 4 cached).
		const token = await page.evaluate(() => window.OC?.requestToken || '')
		await page.request.post('/index.php/apps/tourcheck/api/admin/settings', {
			headers: { requesttoken: token, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' },
			data: { pin_length: 6 },
		}).catch(() => {})
	}
})

test('kiosk signature: drawn ink rides the confirm POST (NH-06 web parity)', async ({ page }) => {
	await page.goto('/index.php/apps/tourcheck/dashboard', { waitUntil: 'domcontentloaded' })
	const token = await page.evaluate(() =>
		(window.OC && window.OC.requestToken)
		|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
		|| '')
	const apiHeaders = { requesttoken: token, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }

	const stn = await seedKioskStation(page, apiHeaders, '123456')
	try {
	await page.goto(`/index.php/apps/tourcheck/kiosk?station_token=${encodeURIComponent(stn.secret)}`, { waitUntil: 'domcontentloaded' })
	await page.locator(`[data-tck-member="${stn.memberId}"]`).click()
	for (const d of ['1', '2', '3', '4', '5', '6']) {
		await page.locator(`[data-tck-key="${d}"]`).click()
	}
	await expect(page.locator('[data-tck-stage="sign"]')).toBeVisible({ timeout: 15000 })

	// Confirm is gated on ink — disabled until a stroke lands.
	const confirmBtn = page.locator('[data-tck-sign-confirm]')
	await expect(confirmBtn).toBeDisabled()
	const pad = page.locator('[data-tck-signpad]')
	const box = await pad.boundingBox()
	expect(box).toBeTruthy()
	await page.mouse.move(box.x + 40, box.y + 60)
	await page.mouse.down()
	await page.mouse.move(box.x + 200, box.y + 120, { steps: 8 })
	await page.mouse.move(box.x + 320, box.y + 80, { steps: 8 })
	await page.mouse.up()
	await expect(confirmBtn).toBeEnabled()

	let posted = null
	page.on('request', (r) => {
		if (r.url().includes('/api/kiosk/confirm')) { posted = r.postDataJSON() }
	})
	await confirmBtn.click()
	await expect(page.locator('[data-tck-stage="confirm"]')).toBeVisible({ timeout: 15000 })
	expect(posted?.signature_b64, 'signature_b64 must ride the confirm body').toMatch(/^[A-Za-z0-9+/=]{100,}$/)
	} finally {
		await revokeKioskStation(page, apiHeaders, stn.stationId)
	}
})

test('kiosk a11y: member grid + signature stage pass axe (public surface)', async ({ page }) => {
	await page.goto('/index.php/apps/tourcheck/dashboard', { waitUntil: 'domcontentloaded' })
	const token = await page.evaluate(() => window.OC?.requestToken || '')
	const apiHeaders = { requesttoken: token, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
	const stn = await seedKioskStation(page, apiHeaders, '123456')
	try {
	await page.goto(`/index.php/apps/tourcheck/kiosk?station_token=${encodeURIComponent(stn.secret)}`, { waitUntil: 'domcontentloaded' })
	const gridScan = await new AxeBuilder({ page }).include('[data-tck-kiosk]').analyze()
	expect(gridScan.violations, JSON.stringify(gridScan.violations, null, 2)).toEqual([])

	await page.locator(`[data-tck-member="${stn.memberId}"]`).click()
	await page.locator('[data-tck-pin-input]').fill('123456')
	await expect(page.locator('[data-tck-stage="sign"]')).toBeVisible({ timeout: 15000 })
	const signScan = await new AxeBuilder({ page }).include('[data-tck-stage="sign"]').analyze()
	expect(signScan.violations, JSON.stringify(signScan.violations, null, 2)).toEqual([])
	} finally {
		await revokeKioskStation(page, apiHeaders, stn.stationId)
	}
})

test('schedule toggle: a live schedule can be silenced — no orphan dead end', async ({ page }) => {
	// Site 1 exists on the seeded box and tck_atlas manages it.
	await page.goto('/index.php/apps/tourcheck/dashboard', { waitUntil: 'domcontentloaded' })
	const token = await page.evaluate(() => window.OC?.requestToken || '')
	const apiHeaders = { requesttoken: token, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }

	const recipient = `e2e-sched-${Date.now()}@example.invalid`
	const mk = await page.request.put('/index.php/apps/tourcheck/api/sites/1/report-schedules', {
		headers: apiHeaders,
		data: { cadence: 'weekly', recipient, audience: 'internal', active: true },
	})
	expect(mk.ok(), 'seed schedule').toBeTruthy()

	await page.goto('/index.php/apps/tourcheck/reports?site=1', { waitUntil: 'domcontentloaded' })
	const row = page.locator('li.tck-list__row', { hasText: recipient })
	const toggle = row.locator('[data-tck-toggle-sched]')
	// data-active carries the state — the label is locale-dependent.
	await expect(toggle).toHaveAttribute('data-active', '1')

	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		toggle.click(),
	])
	const rowAfter = page.locator('li.tck-list__row', { hasText: recipient })
	await expect(rowAfter.locator('[data-tck-toggle-sched]')).toHaveAttribute('data-active', '0')
})

test('cron banner: healthy cron shows no false warning, and the surface exists', async ({ page }) => {
	// last_cron_run_at is fresh on this box (cron container alive) — the
	// admin-only stale banner must NOT render (no always-on false positive).
	await page.goto('/index.php/apps/tourcheck/dashboard', { waitUntil: 'domcontentloaded' })
	await expect(page.locator('[data-tck-cron-stale]')).toHaveCount(0)
})

test('duty chip: fail-closed — hidden without effective data, never an error surface', async ({ page }) => {
	// F17: the section only reveals itself when the advertised capability
	// answers {effective:true}. Flag off OR a non-planner user (403)
	// must both leave it hidden — verified on this box in both states.
	await page.goto('/index.php/apps/tourcheck/dashboard', { waitUntil: 'domcontentloaded' })
	const section = page.locator('[data-tck-duty]')
	await expect(section).toHaveCount(1)
	// Give the (possible) fetch a beat, then: still hidden, no error text.
	await page.waitForTimeout(800)
	await expect(section).toHaveClass(/tck-hidden/)
})

test('capture: network drop surfaces an error — no silent dead scan', async ({ page }) => {
	await page.goto('/index.php/apps/tourcheck/capture', { waitUntil: 'domcontentloaded' })
	await page.route('**/api/capture/resolve', (route) => route.abort())
	await page.locator('[data-tck-scan-input]').fill('deadbeef')
	await page.locator('[data-tck-scan-input]').press('Enter')
	await expect(page.locator('[data-tck-reject]')).toBeVisible()
	// Any non-empty localized error text counts — never match EN|DE literals.
	await expect(page.locator('[data-tck-reject]')).toContainText(/\S/)
})

test('required fields: instant inline error, no network round-trip', async ({ page }) => {
	await page.goto('/index.php/apps/tourcheck/incidents', { waitUntil: 'domcontentloaded' })
	await page.locator('[data-tck-new-incident]').click()
	await expect(page.locator('#tck-incident-form')).toBeVisible()
	let apiFired = false
	page.on('request', (r) => { if (r.url().includes('/api/')) apiFired = true })
	await page.locator('#tck-incident-form [type="submit"]').click()
	await expect(page.locator('#tck-inc-form-title-error')).toBeVisible()
	await expect(page.locator('#tck-inc-form-title-error')).toContainText(/./)
	expect(apiFired, 'empty required field must not fire a request').toBe(false)
	await expect(page.locator('#tck-inc-form-title')).toHaveAttribute('aria-invalid', 'true')
	await expect(page.locator('#tck-inc-form-title')).toBeFocused()
})
