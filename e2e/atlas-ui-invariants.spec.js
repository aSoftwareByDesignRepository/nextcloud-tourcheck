// @ts-check
/**
 * ATLAS_UI_INVARIANTS — shared-contract coverage for TourCheck web surfaces.
 *
 * Wires apps/_shared/e2e/atlas-ui-invariants.js onto representative surfaces
 * inside a dedicated throwaway site (created + archived by this spec,
 * unique `atlasui-` mark — no shared fixture mutation):
 *   a11y-dom sweep, console errors, raw i18n keys, stored-xss via site
 *   name+briefing, O(1) list requests, double-submit guard,
 *   form-survival-on-5xx.
 */
import { test, expect } from '@playwright/test'
import {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertNoInjection,
	assertNoRawI18nKeys,
	countApiRequests,
	trackConsoleErrors,
	assertFormSurvivesFailure,
} from '../../_shared/e2e/atlas-ui-invariants.js'
import { login } from './helpers/auth.js'
import { gotoApp, apiJson } from './helpers/app.js'

const APP = '/index.php/apps/tourcheck'
const CONTENT = '#tck-main-content'
const MARK = `atlasui-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`
const ADMIN = { username: 'tck_atlas', password: process.env.E2E_PASSWORD || 'TckAtlas2026!' }

/** session-cookie JSON call into the app API (browser context = real session + CSRF token). */
async function api(page, method, path, body) {
	return apiJson(page, () => page.evaluate(async ({ method: m, path: p, body: b }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '';
		const res = await fetch(p, {
			method: m,
			credentials: 'same-origin',
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			body: b === undefined ? undefined : JSON.stringify(b),
		});
		return { status: res.status, body: await res.text() };
	}, { method, path: `${APP}${path}`, body }));
}

let siteId = 0
let siteName = ''

test.describe('TourCheck UI invariants (atlas-ui-invariants)', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			await login(page, ADMIN)
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
			siteName = `${MARK} ${ATLAS_XSS_PAYLOADS[0]}`
			const created = await api(page, 'POST', '/api/sites', {
				name: siteName,
				briefing: `${ATLAS_XSS_PAYLOADS[1]} ${ATLAS_XSS_PAYLOADS[2]}`,
			})
			siteId = JSON.parse(created.body)?.data?.id || 0
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		if (!siteId) return
		const page = await browser.newPage()
		try {
			await login(page, ADMIN)
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
			const del = await api(page, 'DELETE', `/api/sites/${siteId}`)
			expect(
				del.status,
				`fixture site ${siteId} archive returned ${del.status}: ${del.body.slice(0, 200)}`,
			).toBe(200)
		} finally {
			await page.close()
		}
	})

	test('site fixture created', async () => {
		expect(siteId, `fixture site create failed (name ${siteName})`).toBeGreaterThan(0)
	})

	for (const [label, path] of [
		['sites list', '/sites'],
		['site detail', `/sites/${'${siteId}'}`],
		['rounds', '/rounds'],
		['incidents', '/incidents'],
		['journal', '/journal'],
	]) {
		test(`a11y-dom sweep ${label}`, async ({ page }) => {
			test.skip(!siteId, 'fixture site missing')
			await login(page, ADMIN)
			await gotoApp(page, `${APP}${path.replace('${siteId}', String(siteId))}`, { contentSel: CONTENT })
			const findings = await assertA11yDom(page, { content: CONTENT })
			expect(findings, `a11y-dom findings on ${label}:\n${findings.join('\n')}`).toEqual([])
		})

		test(`console errors + raw i18n keys ${label}`, async ({ page }) => {
			test.skip(!siteId, 'fixture site missing')
			const errs = trackConsoleErrors(page)
			await login(page, ADMIN)
			await gotoApp(page, `${APP}${path.replace('${siteId}', String(siteId))}`, { contentSel: CONTENT })
			assertNoConsoleErrors(errs, { allow: [/favicon/i] })
			await assertNoRawI18nKeys(page, { content: CONTENT })
		})
	}

	test('stored-xss: payload in site name/briefing never executes or leaks raw', async ({ page }) => {
		test.skip(!siteId, 'fixture site missing')
		for (const p of ['/sites', `/sites/${siteId}`]) {
			await login(page, ADMIN)
			await gotoApp(page, `${APP}${p}`, { contentSel: CONTENT })
			await assertNoInjection(page)
		}
	})

	test('n+1: /sites list issues a bounded number of app API requests', async ({ page }) => {
		test.skip(!siteId, 'fixture site missing')
		await login(page, ADMIN)
		const hits = await countApiRequests(page, async () => {
			await gotoApp(page, `${APP}/sites`, { contentSel: CONTENT })
		}, '/apps/tourcheck/api/')
		expect(hits.length, `site list fired ${hits.length} API requests (N+1)`).toBeLessThanOrEqual(3)
	})

	test('double-submit: site create fires one POST', async ({ page }) => {
		test.skip(!siteId, 'fixture site missing')
		await login(page, ADMIN)
		await gotoApp(page, `${APP}/sites`, { contentSel: CONTENT })
		await page.locator('[data-tck-new-site]').click()
		const nameInput = page.locator('#tck-site-form [name="name"]')
		await nameInput.fill(`${MARK}-dup`)
		await assertNoDuplicateSubmit(page, {
			mutatingUrl: /\/api\/sites$/,
			method: 'POST',
			// A successful create navigates to /sites/{id} — the button detaches
			// mid-click. That detachment is itself the dedupe (no second click is
			// possible), so tolerate it; the row count below proves exactly one
			// create happened.
			submit: () => page.locator('#tck-site-form button[type="submit"]').click({ timeout: 8_000 }).catch(() => undefined),
		})
		const dupRows = []
		const sites = await api(page, 'GET', '/api/sites')
		for (const s of JSON.parse(sites.body)?.data || []) {
			if (String(s.name).startsWith(`${MARK}-dup`)) {
				dupRows.push(s)
			}
		}
		expect(dupRows.length, `expected exactly 1 created ${MARK}-dup row, found ${dupRows.length}`).toBe(1)
		for (const s of dupRows) {
			await api(page, 'DELETE', `/api/sites/${s.id}`)
		}
	})

	test('site create form survives a 500 (fields retained, error rendered)', async ({ page }) => {
		test.skip(!siteId, 'fixture site missing')
		await login(page, ADMIN)
		await gotoApp(page, `${APP}/sites`, { contentSel: CONTENT })
		await page.locator('[data-tck-new-site]').click()
		await assertFormSurvivesFailure(page, {
			failUrl: /\/api\/sites$/,
			method: 'POST',
			fields: { '#tck-site-form [name="name"]': `${MARK}-500` },
			submit: () => page.locator('#tck-site-form button[type="submit"]').click(),
			errorSel: '.tck-toast--error, .tck-toast[role="alert"]',
		})
	})
})
