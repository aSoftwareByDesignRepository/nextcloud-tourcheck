import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'

/**
 * Red-team sweep — BOLA/oracle/CSRF/role-boundary attacks against the live
 * web API. Tiers:
 *   atlas  — app admin (owns site 1)
 *   empty  — tourcheck_worker group → app access, zero site membership
 *   probe1 — zero app access (app must be invisible)
 * Invariants under test:
 *  - uniform 404: a request for a real-but-forbidden id is byte-identical
 *    to the same request for a nonexistent id (no existence oracle);
 *  - global list endpoints never leak site-1 entities to non-members;
 *  - a site WORKER can read but never mutate (manage ops → uniform 404);
 *  - a site-B ADMIN cannot touch site-A objects (object confusion);
 *  - missing CSRF token on a mutation is rejected;
 *  - admin endpoints stay admin-only for every lower tier.
 */
const ADMIN = { username: 'tck_atlas', password: 'TckAtlas2026!' }
const EMPTY = { username: 'tck_empty', password: 'TckEmpty2026!' }
const PROBE = { username: 'tck_probe1', password: 'TckProbe2026!' }

const J = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }

async function rt(page) {
	return await page.evaluate(() => document.querySelector('head')?.dataset.requesttoken
		|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken'))
}
async function hdr(page) {
	return { requesttoken: await rt(page), ...J }
}
const api = (p) => `/index.php/apps/tourcheck/api${p}`

/** fire request; return {status, body} for comparison */
async function fire(req, method, url, headers, data) {
	const res = await req.fetch(url, { method, headers, data: data ? JSON.stringify(data) : undefined })
	let body = ''
	try { body = JSON.stringify(await res.json()) } catch { body = (await res.text()).slice(0, 200) }
	return { status: res.status(), body }
}

test.describe('redteam: authorization boundary matrix', () => {
	test('uniform 404 oracle: real-id vs fake-id responses are identical for a no-access user', async ({ page }) => {
		await login(page, ADMIN)
		const ah = await hdr(page)
		const req = page.context().request

		// Enumerate real ids as admin — the attacker's "knowledge".
		const sites = await req.get(api('/sites'), { headers: ah }).then(r => r.json())
		const siteId = sites.data[0].id
		const cps = await req.get(api(`/sites/${siteId}/checkpoints`), { headers: ah }).then(r => r.json())
		const cpId = cps.data?.[0]?.id ?? 1
		const plans = await req.get(api(`/sites/${siteId}/plans`), { headers: ah }).then(r => r.json())
		const planId = plans.data?.[0]?.id ?? 1
		const stations = await req.get(api(`/sites/${siteId}/stations`), { headers: ah }).then(r => r.json())
		const stationId = stations.data?.[0]?.id ?? 1
		const keys = await req.get(api(`/sites/${siteId}/keys`), { headers: ah }).then(r => r.json())
		const keyId = keys.data?.[0]?.id ?? 1
		const incidents = await req.get(api('/incidents'), { headers: ah }).then(r => r.json())
		const incidentId = incidents.data?.[0]?.id ?? 1
		const rounds = await req.get(api('/rounds'), { headers: ah }).then(r => r.json())
		const roundId = rounds.data?.[0]?.id ?? 1
		const reports = await req.get(api(`/sites/${siteId}/reports`), { headers: ah }).then(r => r.json())
		const reportId = reports.data?.[0]?.id ?? 1
		const live = await req.get(api(`/sites/${siteId}/live-links`), { headers: ah }).then(r => r.json())
		const liveId = live.data?.[0]?.id ?? 1

		// Build the attack matrix: every id-bearing route, real + 999999 fake.
		const F = 999999
		const matrix = [
			['GET', `/sites/${siteId}`, `/sites/${F}`],
			['GET', `/checkpoints/${cpId}`, `/checkpoints/${F}`, 'PUT'],
			['GET', `/plans/${planId}`, `/plans/${F}`],
			['GET', `/rounds/${roundId}`, `/rounds/${F}`],
			['GET', `/incidents/${incidentId}`, `/incidents/${F}`],
			['GET', `/reports/${reportId}/download`, `/reports/${F}/download`],
			['GET', `/stations/${stationId}/members`, `/stations/${F}/members`],
			['POST', `/stations/${stationId}/revoke`, `/stations/${F}/revoke`],
			['POST', `/keys/${keyId}/take`, `/keys/${F}/take`],
			['POST', `/live-links/${liveId}/revoke`, `/live-links/${F}/revoke`],
			['POST', `/rounds/${roundId}/skip`, `/rounds/${F}/skip`],
			['GET', `/sites/${siteId}/journal`, `/sites/${F}/journal`],
			['GET', `/sites/${siteId}/export`, `/sites/${F}/export`],
			['GET', `/sites/${siteId}/keys`, `/sites/${F}/keys`],
			['GET', `/sites/${siteId}/report-schedules`, `/sites/${F}/report-schedules`],
		]

		// ── Tier 1: zero app access ────────────────────────────────────
		await login(page, PROBE)
		const ph = await hdr(page).catch(() => null)
		// Probe may not even reach the app page → no requesttoken: use an
		// explicit bogus token — a missing token must still not 200.
		const probeHdrs = ph ?? { requesttoken: 'bogus', ...J }
		for (const row of matrix) {
			const [method, realPath, fakePath] = row
			const r = await fire(req, method, api(realPath), probeHdrs, method === 'PUT' ? {} : undefined)
			const f = await fire(req, method, api(fakePath), probeHdrs, method === 'PUT' ? {} : undefined)
			expect(r.status, `probe ${method} ${realPath}`).not.toBe(200)
			expect(r.status, `probe oracle ${method} ${realPath}`).toBe(f.status)
			expect(r.body, `probe oracle body ${method} ${realPath}`).toBe(f.body)
		}
		// Global lists for a zero-membership user: open access-mode may
		// return 200 — but the payload must contain zero site entities.
		for (const p of ['/rounds', '/incidents', '/sites', '/drive-segments']) {
			const r = await fire(req, 'GET', api(p), probeHdrs)
			if (r.status === 200) {
				const body = JSON.parse(r.body)
				expect(body.data ?? [], `probe ${p} returned entities`).toEqual([])
			}
		}
		const dash = await fire(req, 'GET', api('/dashboard'), probeHdrs)
		if (dash.status === 200) {
			const d = JSON.parse(dash.body).data
			expect(d.sites, 'probe dashboard sites').toEqual([])
			expect(d.rounds.next, 'probe dashboard rounds').toEqual([])
		}

		// ── Tier 2: app access, zero site membership ───────────────────
		await login(page, EMPTY)
		const eh = await hdr(page)
		for (const row of matrix) {
			const [method, realPath, fakePath] = row
			const r = await fire(req, method, api(realPath), eh, method === 'PUT' ? {} : undefined)
			const f = await fire(req, method, api(fakePath), eh, method === 'PUT' ? {} : undefined)
			expect(r.status, `empty ${method} ${realPath}`).not.toBe(200)
			expect(r.status, `empty oracle ${method} ${realPath}`).toBe(f.status)
			expect(r.body, `empty oracle body ${method} ${realPath}`).toBe(f.body)
		}

		// Global list endpoints must not leak ANY site entities to a
		// zero-membership user — parse the payload, don't substring-grep.
		for (const p of ['/rounds', '/incidents', '/sites']) {
			const r = await fire(req, 'GET', api(p), eh)
			if (r.status === 200) {
				expect(JSON.parse(r.body).data ?? [], `global ${p} returned entities`).toEqual([])
			}
		}
		const dash2 = await fire(req, 'GET', api('/dashboard'), eh)
		if (dash2.status === 200) {
			const d = JSON.parse(dash2.body).data
			expect(d.sites, 'empty-user dashboard sites').toEqual([])
			expect(d.rounds.next, 'empty-user dashboard rounds').toEqual([])
		}
		const ds = await fire(req, 'GET', api('/drive-segments'), eh)
		expect([403, 404], 'worker drive-segments').toContain(ds.status)

		// Admin endpoints must be admin-only.
		for (const [m, p] of [['GET', '/admin/settings'], ['GET', '/admin/users/search?q=atlas'], ['POST', '/admin/license']]) {
			const r = await fire(req, m, api(p), eh)
			expect([403, 404], `worker hit admin ${m} ${p}`).toContain(r.status)
		}
	})

	test('site worker tier: reads allowed, every mutation is a uniform 404', async ({ page }) => {
		await login(page, ADMIN)
		const ah = await hdr(page)
		const req = page.context().request
		const sites = await req.get(api('/sites'), { headers: ah }).then(r => r.json())
		const siteId = sites.data[0].id
		const cps = await req.get(api(`/sites/${siteId}/checkpoints`), { headers: ah }).then(r => r.json())
		const cpId = cps.data[0].id
		const stations = await req.get(api(`/sites/${siteId}/stations`), { headers: ah }).then(r => r.json())
		const stationId = stations.data[0].id

		// Grant the empty user a WORKER membership on site 1.
		const grant = await req.put(api(`/sites/${siteId}/members`), {
			headers: ah, data: JSON.stringify({ uid: EMPTY.username, role: 'worker' }),
		})
		expect(grant.status()).toBe(200)

		try {
			await login(page, EMPTY)
			const eh = await hdr(page)

			// Reads allowed at view level.
			const read = await fire(req, 'GET', api(`/sites/${siteId}`), eh)
			expect(read.status, 'worker read site').toBe(200)

			// Every manage surface must be uniform 404 (not 403-with-oracle).
			const F = 999999
			const writes = [
				['PUT', `/checkpoints/${cpId}`, { site_id: siteId, name: 'pwned' }, `/checkpoints/${F}`],
				['DELETE', `/checkpoints/${cpId}?site_id=${siteId}`, null, `/checkpoints/${F}?site_id=${siteId}`],
				['POST', `/checkpoints/${cpId}/rotate-token?site_id=${siteId}`, null, `/checkpoints/${F}/rotate-token?site_id=${siteId}`],
				['POST', `/sites/${siteId}/erase`, { uid: 'victim' }, `/sites/${F}/erase`],
				['POST', `/sites/${siteId}/checkpoints`, { name: 'x' }, `/sites/${F}/checkpoints`],
				['PUT', `/sites/${siteId}/members`, { uid: 'atlas_tck_probe', role: 'admin' }, `/sites/${F}/members`],
				['POST', `/sites/${siteId}/verify-chain`, null, `/sites/${F}/verify-chain`],
				['POST', `/sites/${siteId}/anchor`, null, `/sites/${F}/anchor`],
				['PUT', `/sites/${siteId}/report-schedules`, { cadence: 'monthly', audience: 'x', recipients: 'a@b.c', active: false }, `/sites/${F}/report-schedules`],
				['GET', `/stations/${stationId}/members`, null, `/stations/${F}/members`],
				['POST', `/stations/${stationId}/pairing-code`, null, `/stations/${F}/pairing-code`],
				['PUT', `/stations/${stationId}/members`, { display_name: 'x', pin: '1234' }, `/stations/${F}/members`],
			]
			for (const [method, realPath, data, fakePath] of writes) {
				const r = await fire(req, method, api(realPath), eh, data)
				const f = await fire(req, method, api(fakePath), eh, data)
				expect(r.status, `worker write ${method} ${realPath}`).not.toBe(200)
				expect(r.status, `worker write oracle ${method} ${realPath}`).toBe(f.status)
			}
		} finally {
			// De-escalate: remove the membership.
			await login(page, ADMIN)
			const ah2 = await hdr(page)
			await req.delete(api(`/sites/${siteId}/members/${EMPTY.username}`), { headers: ah2 })
		}
	})

	test('CSRF: browser-forged mutation without requesttoken is rejected', async ({ page }) => {
		await login(page, ADMIN)
		const req = page.context().request
		// A cross-origin <form> cannot set OCS-APIREQUEST — this is the real
		// browser-CSRF contract: session cookie + no token → 412.
		const res = await req.post(api('/sites'), {
			headers: { 'Content-Type': 'application/json' },
			data: JSON.stringify({ name: 'csrf-probe' }),
		})
		expect([403, 412], 'missing requesttoken on POST').toContain(res.status())
	})

	test('input boundary: strictId garbage rejected uniformly, never 500', async ({ page }) => {
		await login(page, ADMIN)
		const ah = await hdr(page)
		const req = page.context().request
		const sites = await req.get(api('/sites'), { headers: ah }).then(r => r.json())
		const siteId = sites.data[0].id

		for (const bad of ['2.9', '2e3', ' 5', 'abc', '', '1;DROP', '0x10', '92233720368547758079999']) {
			const r = await fire(req, 'POST', api(`/checkpoints/1/rotate-token?site_id=${encodeURIComponent(bad)}`), ah)
			expect([400, 404, 422], `site_id=${JSON.stringify(bad)}`).toContain(r.status)
		}
		// Mass-assignment probe: extra privileged fields must be ignored, not honored.
		const r = await fire(req, 'PUT', api(`/sites/${siteId}/members`), ah, {
			uid: 'atlas_tck_probe', role: 'worker', is_admin: true, admin: true, site_role: 'admin', group: 'tourcheck_admin',
		})
		expect([200, 400, 404, 422], 'mass-assignment surface').toContain(r.status)
		if (r.status === 200) {
			const role = JSON.parse(r.body)?.data?.role
			expect(role, 'extra fields must not escalate role').toBe('worker')
			// cleanup
			await req.delete(api(`/sites/${siteId}/members/atlas_tck_probe`), { headers: ah })
		}
	})

	test('expensive supervisor endpoint is rate-limited (verify-chain)', async ({ page }) => {
		await login(page, ADMIN)
		const ah = await hdr(page)
		const req = page.context().request
		const sites = await req.get(api('/sites'), { headers: ah }).then(r => r.json())
		const siteId = sites.data[0].id
		// 5/min per site+uid is the bucket — the 6th must 429, not rehash.
		let lastStatus = 0
		for (let i = 0; i < 6; i++) {
			lastStatus = (await req.post(api(`/sites/${siteId}/verify-chain`), { headers: ah })).status()
		}
		expect(lastStatus, 'verify-chain must 429 after 5 rapid calls').toBe(429)
		// A different site keeps its own budget — the limiter is per site+uid,
		// not a global deny-all for the supervisor.
		if (sites.data[1]) {
			const other = await req.post(api(`/sites/${sites.data[1].id}/verify-chain`), { headers: ah })
			expect(other.status(), 'different site must have fresh budget').not.toBe(429)
		}
	})

	test('CAS race: two concurrent updates on the same revision — exactly one wins', async ({ page }) => {
		await login(page, ADMIN)
		const req = page.context().request
		const ah = await hdr(page)
		// Seed a scratch site so the race can't corrupt fixture state.
		const created = await req.post(api('/sites'), { headers: ah, data: { name: `CasRace-${Date.now()}` } })
		expect(created.status()).toBe(201)
		const site = (await created.json()).data
		const base = site.revision

		// Fire both writers on the SAME base revision, truly concurrent.
		const [a, b] = await Promise.all([
			req.fetch(api(`/sites/${site.id}`), { method: 'PUT', headers: ah, data: JSON.stringify({ address: 'Writer A', revision: base }) }),
			req.fetch(api(`/sites/${site.id}`), { method: 'PUT', headers: ah, data: JSON.stringify({ address: 'Writer B', revision: base }) }),
		])
		const statuses = [a.status(), b.status()].sort()
		expect(statuses, 'exactly one writer must win; the loser gets 409').toEqual([200, 409])

		// Final state: revision bumped exactly once — no silent last-writer-wins.
		const after = await req.get(api(`/sites/${site.id}`), { headers: ah })
		expect((await after.json()).data.revision).toBe(base + 1)

		await req.delete(api(`/sites/${site.id}`), { headers: ah })
	})

	test('restricted door oracle: excluded user sees what a nonexistent app returns', async ({ page }) => {
		// probe1 has zero app access — but the default access_mode is 'open',
		// so flip the app to restricted first, then the door must answer
		// exactly like a missing app (404). A 303/403 diff discloses existence.
		const { execFileSync } = await import('child_process')
		const occ = (args) => execFileSync('docker', [
			'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args,
		], { cwd: new URL('../../../', import.meta.url).pathname, encoding: 'utf8', timeout: 60_000 })
		occ(['config:app:set', 'tourcheck', 'access_mode', '--value=restricted'])
		try {
			await login(page, PROBE)
			const req = page.context().request
			const absent = await req.get('/index.php/apps/tckdefinitelymissing/', { maxRedirects: 0 })
			// APCu appconfig can lag the config:set — poll the door until it
			// matches the absent-app status (never a fixed sleep).
			let door = 0
			for (let i = 0; i < 20; i++) {
				door = (await req.get('/index.php/apps/tourcheck/', { maxRedirects: 0 })).status()
				if (door === absent.status()) break
				await page.waitForTimeout(250)
			}
			expect(door, 'app door must be indistinguishable from absent').toBe(absent.status())
			await page.goto('/index.php/apps/tourcheck/')
			await expect(page.locator('body')).not.toContainText(/tourcheck/i)
		} finally {
			occ(['config:app:set', 'tourcheck', 'access_mode', '--value=open'])
		}
	})

	test('unauthenticated: API returns 401/redirect, never data', async ({ browser }) => {
		const ctx = await browser.newContext()
		const req = ctx.request
		for (const p of ['/sites', '/rounds', '/incidents', '/admin/settings', '/dashboard']) {
			const res = await req.get(api(p), { headers: J, maxRedirects: 0 })
			expect(res.status(), `anon ${p}`).not.toBe(200)
		}
		await ctx.close()
	})
})
