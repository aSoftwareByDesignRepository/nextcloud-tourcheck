// @ts-check
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'

/**
 * Viewport regression contract (UI-master pass): no horizontal overflow at
 * any NC-relevant width on the surfaces most likely to clip — dashboard
 * (stat strip), rounds (pill filter), journal (card-transform table),
 * site detail (chipbar + sections). 320/375/414 mobile, 768 tablet
 * (card-transform boundary), 1024/1280 desktop.
 *
 * Complements the axe matrix in theme-responsive-a11y.spec.js (320/768/1280)
 * with the widths NC actually hits in the field (375/414 phones, 1024
 * boundary where the nav drawer disengages).
 */
const CREDS = { username: 'tck_atlas', password: 'TckAtlas2026!' }
const WIDTHS = [320, 375, 414, 768, 1024, 1280]
const PAGES = ['/', '/rounds', '/journal', '/sites/1']

test.beforeEach(async ({ page }) => {
	await login(page, CREDS)
})

for (const path of PAGES) {
	for (const width of WIDTHS) {
		test(`no horizontal overflow: ${path} @ ${width}px`, async ({ page }) => {
			await page.setViewportSize({ width, height: 800 })
			await page.goto(`/index.php/apps/tourcheck${path === '/' ? '' : path}`, { waitUntil: 'domcontentloaded' })
			const res = await page.evaluate(() => {
				const doc = document.documentElement
				const app = document.querySelector('#app-content')
				return {
					doc: doc.scrollWidth > doc.clientWidth + 1,
					app: app ? app.scrollWidth > app.clientWidth + 1 : false,
					scrollW: doc.scrollWidth, clientW: doc.clientWidth,
				}
			})
			expect(res.doc || res.app, `h-overflow ${path}@${width}: ${JSON.stringify(res)}`).toBeFalsy()
		})
	}
}

/** Exactly 768px is where card-transform (≤768) met wrap-scroll (≥768)
 *  before the boundary fix — pin the partition: stacked cards AND no
 *  active scroll region on the same paint. */
test('768px boundary: cards stack AND wrap is inert', async ({ page }) => {
	await page.setViewportSize({ width: 768, height: 1024 })
	await page.goto('/index.php/apps/tourcheck/journal', { waitUntil: 'domcontentloaded' })
	const probe = await page.evaluate(() => {
		const wrap = document.querySelector('.tck-table-wrap')
		const td = document.querySelector('.tck-table--cards tbody td')
		return {
			wrapScrolls: wrap ? wrap.scrollWidth > wrap.clientWidth + 1 : null,
			cardMode: td ? getComputedStyle(td).display : null,
			wrapOverflow: wrap ? getComputedStyle(wrap).overflowX : null,
		}
	})
	expect(probe.cardMode, 'card transform must be active at 768').not.toBe('table-cell')
	expect(probe.wrapScrolls, 'wrap must not scroll at 768').not.toBe(true)
})
