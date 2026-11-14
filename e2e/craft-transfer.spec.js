// @ts-check
/**
 * ATLAS web craft capture — transfer settings surfaces. Writes PNGs to
 * .cursor/atlas-farm-v3/artifacts/tourcheck/craft/web/.
 * Flow: settings/transfer load → uniform file error → real
 * export→preview→report dialog → apply → progress dialog → after-apply,
 * plus the replace-confirm binding leg (footer + token row inside the
 * dialog client box at 1280 AND 375) and the positional t() proof
 * (rendered counters carry values, never %d/%s/%1$s residue).
 * Ported from kanzleicheck e2e/craft-transfer.spec.js (tck- prefix).
 */
import { test, expect } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { execSync } from 'node:child_process'
import { login } from './helpers/auth.js'
import { gotoApp } from './helpers/app.js'
import { assertAtlasRenderedSurface } from '../../_shared/e2e/atlas-rendered-surface-contract.js'

const APP = '/index.php/apps/tourcheck'
const ADMIN = { username: 'tck_atlas', password: process.env.E2E_PASSWORD || 'TckAtlas2026!' }
const PASS = 'atlas-e2e-passphrase-2026-11'
const DIRNAME = path.dirname(fileURLToPath(import.meta.url))
const OUT = path.resolve(DIRNAME, '../../../../.cursor/atlas-farm-v3/artifacts/tourcheck/craft/web')
const COMPOSE = '/home/alex/Development/nextcloud-dev/nextcloud/docker-compose.yml'
fs.mkdirSync(OUT, { recursive: true })

function occ(args) {
	return execSync(
		`docker compose -f ${COMPOSE} exec -T -u www-data nextcloud php occ ${args}`,
		{ encoding: 'utf8', timeout: 60000 }).trim()
}
function db(sql) {
	// SQL via stdin — shell -e quoting eats inner quotes (probe-harness lesson)
	return execSync(
		`docker compose -f ${COMPOSE} exec -T mariadb bash -lc 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" nextcloud -N'`,
		{ encoding: 'utf8', timeout: 30000, input: sql }).trim()
}
async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false })
}

const LIST_ALLOW = [
	'.tck-nav',
	'.tck-breadcrumb',
	'.tck-list',
	'.tck-coach__steps',
	'.tck-kiosk__grid',
	'.tck-menu',
	'.tck-actions',
	'.tck-modal',
	'.tck-transfer-group',
].join(', ')

test.describe('craft transfer', () => {
	test('transfer surface + report/progress dialogs 1280/375', async ({ page, request }) => {
		test.setTimeout(240_000)
		const auth = 'Basic ' + Buffer.from(`${ADMIN.username}:${ADMIN.password}`).toString('base64')

		const exp = await request.post(`/index.php/apps/tourcheck/api/admin/transfer/export`, {
			headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, data: { passphrase: PASS },
		})
		expect(exp.status()).toBe(200)
		const bytes = await exp.body()
		expect(bytes.length).toBeGreaterThan(200)

		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, ADMIN)
		await gotoApp(page, `${APP}/settings/transfer`)
		await expect(page.locator('#tck-transfer-export-card')).toBeVisible({ timeout: 20000 })
		await page.waitForTimeout(600)
		await shot(page, 'tck-transfer-light-1280')

		// uniform file error — wrong passphrase on a real container
		await page.locator('#tck-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#tck-transfer-import-passphrase').fill('wrong-passphrase-000')
		await expect(page.locator('#tck-transfer-result-live')).not.toBeEmpty({ timeout: 30000 })
		await shot(page, 'tck-transfer-fileerror-light-1280')

		// correct passphrase → preview → report dialog (interpolated counters —
		// positional t() must render digits, never %d/%s residue)
		await page.locator('#tck-transfer-import-passphrase').fill(PASS)
		const report = page.locator('#tck-transfer-report')
		await expect(report).toBeVisible({ timeout: 30000 })
		await expect(page.locator('#tck-transfer-report-verdict')).toContainText(/\d/)
		const rawTokens = await report.evaluate(
			(d) => (d.textContent || '').match(/%\d*\$?[sd]|%\{[a-z_]+\}/g))
		expect(rawTokens, `raw positional tokens rendered: ${rawTokens}`).toBeNull()
		await shot(page, 'tck-transfer-report-dialog-light-1280')

		// apply → progress dialog stays open while the job waits queued
		await page.locator('#tck-transfer-confirm').click()
		const progress = page.locator('#tck-transfer-progress')
		await expect(progress).toBeVisible({ timeout: 10000 })
		await expect(page.locator('#tck-transfer-progress-text')).not.toBeEmpty()
		await shot(page, 'tck-transfer-progress-light-1280')

		const jobId = db("SELECT id FROM oc_jobs WHERE class LIKE '%TransferApplyJob%' ORDER BY id DESC LIMIT 1")
		expect(jobId).toMatch(/^\d+$/)
		const before = await request.get(`/index.php/apps/tourcheck/api/admin/transfer/status`, { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } })
		const priorReceiptId = (((await before.json()).data || {}).lastReceipt || {}).id || ''
		occ(`background-job:execute ${jobId}`)

		let receipt = {}
		for (let i = 0; i < 60; i++) {
			const s = await request.get(`/index.php/apps/tourcheck/api/admin/transfer/status`, { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } })
			receipt = (((await s.json()).data || {}).lastReceipt) || {}
			if (receipt.id && receipt.id !== priorReceiptId
				&& (receipt.result === 'applied' || receipt.result === 'failed')) break
			await new Promise((r) => setTimeout(r, 1000))
		}
		expect(receipt.result).toBe('applied')

		// page reloads itself on done — settle, then capture post-apply state
		await page.waitForLoadState('domcontentloaded')
		await expect(page.locator('#tck-transfer-last')).toBeVisible({ timeout: 20000 })
		await page.waitForTimeout(600)
		await shot(page, 'tck-transfer-after-apply-light-1280')
	})

	test('transfer surface mobile 375', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 })
		await login(page, ADMIN)
		await gotoApp(page, `${APP}/settings/transfer`)
		await expect(page.locator('#tck-transfer-export-card')).toBeVisible({ timeout: 20000 })
		await page.waitForTimeout(600)
		await shot(page, 'tck-transfer-light-375')
	})

	test('replace-confirm token row + footer bound inside dialog box (binding leg)', async ({ page, request }) => {
		test.setTimeout(180_000)
		const auth = 'Basic ' + Buffer.from(`${ADMIN.username}:${ADMIN.password}`).toString('base64')

		// Replace preview requires an NC SYSTEM admin (FS-2 — the probe's
		// replace_admin_required leg proves the door-admin-only fixture is
		// denied). The template renders the Replace radio DISABLED
		// server-side for non-sysadmins, so the promotion must land before
		// the page loads — promote for the duration of this leg only.
		occ(`group:adduser admin ${ADMIN.username}`)
		try {
			const exp = await request.post(`/index.php/apps/tourcheck/api/admin/transfer/export`, {
				headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, data: { passphrase: PASS },
			})
			expect(exp.status()).toBe(200)
			const bytes = await exp.body()
			expect(bytes.length).toBeGreaterThan(200)

			await page.setViewportSize({ width: 1280, height: 800 })
			await login(page, ADMIN)
			await gotoApp(page, `${APP}/settings/transfer`)
			await expect(page.locator('#tck-transfer-export-card')).toBeVisible({ timeout: 20000 })

			// Full-form pinned-control sweep (cramped_form_controls): every text
			// control on the transfer surface must escape the NC-core 130px pin.
			await assertAtlasRenderedSurface(page, {
				content: '#tck-main-content',
				navExclude: '#app-navigation, nav, .tck-nav, .tck-breadcrumb, .tck-modal',
				listAllow: LIST_ALLOW,
			})

			// Replace mode → real preview → report dialog footer shows the
			// token row. Preview needs no maintenance mode (a warning callout
			// renders instead) — the dialog is closed, never applied.
			await page.locator('#tck-transfer-import-form input[type="radio"][value="replace"]').check()
			await page.locator('#tck-transfer-file').setInputFiles({
				name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
			})
			await page.locator('#tck-transfer-import-passphrase').fill(PASS)
			const report = page.locator('#tck-transfer-report')
			await expect(report).toBeVisible({ timeout: 30000 })
			const tokenRow = page.locator('#tck-transfer-replace-confirm')
			await expect(tokenRow).toBeVisible()
			const token = page.locator('#tck-transfer-replace-token')
			await expect(token).toBeVisible()

			// cramped_form_controls fix live: the input escapes the core 130px pin.
			const w = await token.evaluate((el) => el.getBoundingClientRect().width)
			expect(w, `token input ${Math.round(w)}px still on the NC-core 130px pin`).toBeGreaterThan(140)

			// required_field_or_step_undiscoverable: the report dialog's footer —
			// token row + Cancel + Apply import — must paint INSIDE the dialog's
			// client box at every viewport; .tck-dialog__body absorbs overflow.
			const assertDialogFooterPainted = async (where) => {
				const g = await report.evaluate((d) => {
					const cs = getComputedStyle(d)
					const r = d.getBoundingClientRect()
					const bt = parseFloat(cs.borderTopWidth) || 0
					const bl = parseFloat(cs.borderLeftWidth) || 0
					const client = { top: r.top + bt, left: r.left + bl, bottom: r.top + bt + d.clientHeight, right: r.left + bl + d.clientWidth }
					const f = d.querySelector('.tck-dialog__footer').getBoundingClientRect()
					const a = d.querySelector('#tck-transfer-confirm').getBoundingClientRect()
					return { client, dScrollH: d.scrollHeight, dClientH: d.clientHeight,
						footer: { top: f.top, bottom: f.bottom, left: f.left, right: f.right },
						apply: { top: a.top, bottom: a.bottom, left: a.left, right: a.right } }
				})
				for (const [name, box] of Object.entries({ footer: g.footer, apply: g.apply })) {
					expect(box.bottom, `${where}: ${name} bottom ${Math.round(box.bottom)} hangs below dialog client box ${Math.round(g.client.bottom)}`).toBeLessThanOrEqual(g.client.bottom + 0.5)
					expect(box.top, `${where}: ${name} top above dialog client box`).toBeGreaterThanOrEqual(g.client.top - 0.5)
					expect(box.right, `${where}: ${name} right outside dialog client box`).toBeLessThanOrEqual(g.client.right + 0.5)
					expect(box.left, `${where}: ${name} left outside dialog client box`).toBeGreaterThanOrEqual(g.client.left - 0.5)
				}
				expect(g.dScrollH - g.dClientH,
					`${where}: dialog shell scrolls (scrollHeight ${g.dScrollH} > clientHeight ${g.dClientH}) — .tck-dialog__body must absorb the overflow`)
					.toBeLessThanOrEqual(1)
			}
			await assertDialogFooterPainted('1280')

			await assertAtlasRenderedSurface(page, {
				content: '#tck-main-content',
				navExclude: '#app-navigation, nav, .tck-nav, .tck-breadcrumb, .tck-modal',
				listAllow: LIST_ALLOW,
			})

			await shot(page, 'tck-transfer-replace-confirm-light-1280')
			await page.setViewportSize({ width: 375, height: 812 })
			await page.waitForTimeout(300)
			await assertDialogFooterPainted('375')
			await shot(page, 'tck-transfer-replace-confirm-light-375')
			// Never apply — replace is destructive; close the dialog instead.
			await page.keyboard.press('Escape')
			await expect(report).toBeHidden()
		} finally {
			occ(`group:removeuser admin ${ADMIN.username}`)
		}
	})

	test('zh_TW Traditional render + zh-CN boundary leg', async ({ page }) => {
		// Donor catalogs carry real Traditional zh_TW strings — switch the
		// fixture admin's lang, prove the transfer surface renders Traditional
		// (never Simplified leakage), then prove the zh_CN boundary and
		// restore 'en' so later legs are locale-stable.
		const auth = 'Basic ' + Buffer.from(`${ADMIN.username}:${ADMIN.password}`).toString('base64')
		const fetchSection = async () => {
			const r = await page.request.get(`${APP}/settings/transfer`, {
				headers: { Authorization: auth, 'OCS-APIRequest': 'true' },
			})
			expect(r.status()).toBe(200)
			return r.text()
		}
		try {
			occ(`user:setting ${ADMIN.username} core lang zh_TW`)
			const tw = await fetchSection()
			expect(tw).toContain('傳輸')
			expect(tw).toContain('匯出配置')
			expect(tw).toContain('應用匯入')
			expect(tw).not.toContain('导出配置')
			expect(tw).not.toContain('传输')

			occ(`user:setting ${ADMIN.username} core lang zh_CN`)
			const cn = await fetchSection()
			expect(cn).toContain('导出配置')
			expect(cn).toContain('传输')
			expect(cn).not.toContain('匯出配置')
			expect(cn).not.toContain('傳輸')
		} finally {
			occ(`user:setting ${ADMIN.username} core lang en`)
		}
	})
})
