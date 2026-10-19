// @ts-check
/**
 * Load-failure contract: a failed fetch must render an inline error + a
 * way forward (Retry), never a silent blank panel. Covers the two
 * fetch-and-render surfaces that previously returned early on
 * `!j.success` (ops.js loaders, portal.js loaders).
 *
 * Structural selectors only — fixture locales are arbitrary.
 */
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'
import { gotoApp, apiFetch } from './helpers/app.js'

const APP = '/index.php/apps/tourcheck'
const MARK = `loadfail-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`
const ADMIN = { username: 'tck_atlas', password: 'TckAtlas2026!' }
const CLIENT = { username: 'tck_client1', password: 'TckClient2026!' }

const fail500 = (/** @type {import('@playwright/test').Route} */ route) =>
	route.fulfill({ status: 500, contentType: 'application/json', body: '{"success":false}' })

test.describe('load failure renders error + retry', () => {
	test.describe.configure({ mode: 'serial' })
	let siteId = 0

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			await login(page, ADMIN)
			await gotoApp(page, `${APP}/`)
			const created = await apiFetch(page, 'POST', '/api/sites', { name: `${MARK} site` })
			siteId = created?.data?.id || 0
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		if (!siteId) { return }
		const page = await browser.newPage()
		try {
			await login(page, ADMIN)
			await gotoApp(page, `${APP}/`)
			await apiFetch(page, 'DELETE', `/api/sites/${siteId}`)
		} finally {
			await page.close()
		}
	})

	test('ops tasks: 500 renders inline error + retry, retry recovers', async ({ page }) => {
		test.skip(!siteId, 'fixture site missing')
		await login(page, ADMIN)
		await page.route('**/api/sites/*/tasks', fail500)
		await gotoApp(page, `${APP}/ops`)
		const tasks = page.locator('#tck-tasks')
		await expect(tasks.locator('.tck-load-fail .tck-field-error')).toBeVisible({ timeout: 15_000 })
		const retry = tasks.locator('.tck-load-fail button.tck-btn')
		await expect(retry).toBeVisible()
		await page.unroute('**/api/sites/*/tasks')
		await retry.click()
		// Recovery: real load completes -> rows or the empty state; error
		// block gone either way.
		await expect(page.locator('#tck-tasks .tck-list__row, #tck-tasks-empty').first()).toBeVisible({ timeout: 15_000 })
		await expect(tasks.locator('.tck-load-fail')).toHaveCount(0)
	})

	test('portal site list: 500 renders inline error + retry, retry recovers', async ({ page }) => {
		test.skip(!siteId, 'fixture site missing')
		await login(page, CLIENT)
		await page.route('**/api/portal/sites', fail500)
		await page.goto(`${APP}/portal`, { waitUntil: 'domcontentloaded' })
		const list = page.locator('#tck-portal-site-list')
		await expect(list.locator('.tck-load-fail .tck-field-error')).toBeVisible({ timeout: 15_000 })
		const retry = list.locator('.tck-load-fail button.tck-btn')
		await expect(retry).toBeVisible()
		await page.unroute('**/api/portal/sites')
		await retry.click()
		// Real load completes -> a site row or the empty state, block gone.
		await expect(page.locator('#tck-portal-site-list .tck-list__row, #tck-portal-empty').first()).toBeVisible({ timeout: 15_000 })
		await expect(list.locator('.tck-load-fail')).toHaveCount(0)
	})
})
