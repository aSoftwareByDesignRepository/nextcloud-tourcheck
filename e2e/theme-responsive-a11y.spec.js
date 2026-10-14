// @ts-check
/**
 * Theme × viewport × axe gauntlet for TourCheck (family-canonical pattern,
 * adapted from dutycheck). Real theme switches through the NC theming OCS
 * API — not simulated color overrides — so light/dark/high-contrast and
 * custom-accent are proven end to end.
 */
import { test, expect } from '@playwright/test'
import { AxeBuilder } from '@axe-core/playwright'
import { login } from './helpers/auth.js'
// @ts-ignore CJS shared contract
import { assertAtlasMobileNav } from '../../_shared/e2e/atlas-mobile-nav-contract.js'
import {
	USER_THEMES,
	setUserTheme,
	resetUserTheme,
	setAccentColor,
	resetAccentColor,
} from './helpers/theming.js'

const creds = {
	username: process.env.E2E_USERNAME || 'tck_atlas',
	password: process.env.E2E_PASSWORD || 'TckAtlas2026!',
}

const routes = [
	{ id: 'dashboard', path: '/apps/tourcheck/', ready: '#tck-main-content' },
	{ id: 'sites', path: '/apps/tourcheck/sites', ready: '#tck-main-content' },
	{ id: 'rounds', path: '/apps/tourcheck/rounds', ready: '#tck-main-content' },
	{ id: 'journal', path: '/apps/tourcheck/journal', ready: '#tck-main-content' },
	{ id: 'incidents', path: '/apps/tourcheck/incidents', ready: '#tck-main-content' },
	{ id: 'reports', path: '/apps/tourcheck/reports', ready: '#tck-main-content' },
	{ id: 'settings', path: '/apps/tourcheck/settings', ready: '#tck-main-content' },
	{ id: 'ops', path: '/apps/tourcheck/ops', ready: '#tck-main-content' },
]

const overflowViewports = [
	{ width: 320, height: 640 },
	{ width: 375, height: 812 },
	{ width: 414, height: 896 },
	{ width: 768, height: 1024 },
	{ width: 1024, height: 768 },
	{ width: 1280, height: 800 },
	{ width: 2560, height: 1440 },
]

const axeViewports = [
	{ width: 320, height: 640 },
	{ width: 768, height: 1024 },
	{ width: 1280, height: 800 },
]

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectNoHorizontalOverflow(page, label) {
	const overflow = await page.evaluate(() => {
		const doc = document.documentElement
		const app = document.querySelector('#app-content')
		const shell = document.querySelector('#app-content-wrapper')
		const check = (/** @type {Element | null} */ el) => el && el.scrollWidth > el.clientWidth + 1
		return {
			doc: check(doc),
			app: check(app),
			shell: check(shell),
			scrollW: doc.scrollWidth,
			clientW: doc.clientWidth,
		}
	})
	expect(
		overflow.doc || overflow.app,
		`horizontal overflow at ${label}: ${JSON.stringify(overflow)}`,
	).toBeFalsy()
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertThemeTokensResolved(page) {
	const tokens = await page.evaluate(() => {
		const app = document.querySelector('#app-content.tck-app') || document.body
		const cs = getComputedStyle(app)
		const bodyCs = getComputedStyle(document.body)
		return {
			bg: cs.getPropertyValue('--tck-bg-card').trim() || bodyCs.getPropertyValue('--color-main-background').trim(),
			text: cs.getPropertyValue('--tck-text').trim() || bodyCs.getPropertyValue('--color-main-text').trim(),
			primary: bodyCs.getPropertyValue('--color-primary-element').trim(),
			muted: cs.getPropertyValue('--tck-muted').trim(),
			tintInfo: cs.getPropertyValue('--tck-tint-info').trim(),
			tintSuccess: cs.getPropertyValue('--tck-tint-success').trim(),
			scrim: cs.getPropertyValue('--tck-scrim').trim() || bodyCs.getPropertyValue('--tck-scrim').trim(),
			touch: cs.getPropertyValue('--tck-touch').trim() || bodyCs.getPropertyValue('--tck-touch').trim(),
		}
	})
	expect(tokens.bg, 'theme background token').not.toEqual('')
	expect(tokens.text, 'theme text token').not.toEqual('')
	expect(tokens.primary, 'primary element token').not.toEqual('')
	expect(tokens.muted, 'muted token').not.toEqual('')
	expect(tokens.tintInfo, 'tint-info must resolve').not.toEqual('')
	expect(tokens.tintSuccess, 'tint-success must resolve').not.toEqual('')
	expect(
		/,\s*transparent\s*\)\s*$/i.test(tokens.tintInfo),
		`tint-info must mix into main-background, got: ${tokens.tintInfo}`,
	).toBeFalsy()
	expect(tokens.scrim, 'scrim token').not.toEqual('')
	expect(tokens.touch === '44px' || parseFloat(tokens.touch) >= 44, 'touch target token ≥44px').toBeTruthy()
}

/**
 * Dark / dark-HC: sidebar must use resolved NC surface tokens — no light island.
 * @param {import('@playwright/test').Page} page
 * @param {string} theme
 */
async function assertDarkNavMatchesThemeTokens(page, theme) {
	if (!theme.includes('dark')) return
	const nav = await page.evaluate(() => {
		const navEl = document.querySelector('#app-navigation')
		if (!navEl) return null
		const bodyCs = getComputedStyle(document.body)
		const navCs = getComputedStyle(navEl)
		const parseRgb = (/** @type {unknown} */ v) => {
			const s = String(v).trim()
			const m = s.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i)
			if (m) return [Number(m[1]), Number(m[2]), Number(m[3])]
			const hx = s.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i)
			if (hx) {
				let h = hx[1]
				if (h.length === 3) h = h.split('').map((c) => c + c).join('')
				return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)]
			}
			return null
		}
		const probe = document.createElement('div')
		probe.style.backgroundColor = 'var(--color-main-background)'
		document.body.appendChild(probe)
		const probeCs = getComputedStyle(probe)
		const surfaceRgb = parseRgb(probeCs.backgroundColor)
		const navRgb = parseRgb(navCs.backgroundColor)
		const link = navEl.querySelector('.tck-nav__link')
		const linkColor = link ? getComputedStyle(link).color : ''
		probe.remove()
		return {
			surfaceToken: bodyCs.getPropertyValue('--color-main-background').trim(),
			navBg: navCs.backgroundColor,
			linkColor,
			surfaceRgb,
			navRgb,
		}
	})
	if (!nav) { throw new Error('dark theme must render #app-navigation') }
	expect(nav.surfaceToken, 'body --color-main-background must resolve').not.toEqual('')
	if (!nav.navRgb) { throw new Error(`nav background must parse (got ${nav.navBg})`) }
	const lum = (0.2126 * nav.navRgb[0] + 0.7152 * nav.navRgb[1] + 0.0722 * nav.navRgb[2]) / 255
	expect(lum, `dark nav must stay dark (lum=${lum}, bg=${nav.navBg})`).toBeLessThan(0.45)
	if (nav.surfaceRgb) {
		const dist = Math.hypot(
			nav.surfaceRgb[0] - nav.navRgb[0],
			nav.surfaceRgb[1] - nav.navRgb[1],
			nav.surfaceRgb[2] - nav.navRgb[2],
		)
		expect(
			dist,
			`nav bg must match resolved --color-main-background (dist=${dist})`,
		).toBeLessThan(48)
	}
	expect(nav.linkColor, 'nav links must resolve a color').not.toEqual('')
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertTouchTargets(page) {
	const result = await page.evaluate(() => {
		const nodes = [
			...document.querySelectorAll(
				'#app-content.tck-app button, #app-content.tck-app .button, '
				+ '.tck-nav__link, .tck-nav-toggle, .tck-field-error, '
				+ '#app-content.tck-app input[type="submit"], #app-content.tck-app a.button',
			),
		].slice(0, 60)
		const undersized = []
		for (const el of nodes) {
			const style = getComputedStyle(el)
			if (style.display === 'none' || style.visibility === 'hidden') continue
			const rect = el.getBoundingClientRect()
			if (rect.width === 0 && rect.height === 0) continue
			const minH = Math.max(rect.height, parseFloat(style.minHeight) || 0)
			const minW = Math.max(rect.width, parseFloat(style.minWidth) || 0)
			const isBar = rect.width >= 120
			if (minH < 40 || (!isBar && minW < 40)) {
				undersized.push({
					tag: el.tagName,
					cls: String(el.className).slice(0, 80),
					w: Math.round(minW),
					h: Math.round(minH),
				})
			}
		}
		return { ok: undersized.length === 0, undersized }
	})
	expect(result.ok, JSON.stringify(result.undersized)).toBeTruthy()
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function runAxe(page, label) {
	await page.locator('.tck-toasts .tck-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
	const results = await new AxeBuilder({ page })
		.include('#content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.tck-toasts')
		.exclude('.toastify')
		.analyze()
	// unified-search is NC core chrome outside the app surface (family carve-out).
	const violations = results.violations.filter(
		(v) => !v.nodes.some((n) => n.html.includes('unified-search')),
	)
	expect(
		violations,
		`axe violations at ${label}:\n${JSON.stringify(violations, null, 2)}`,
	).toEqual([])
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 */
async function gotoReady(page, path) {
	await page.goto(path, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#tck-main-content')).toBeVisible({ timeout: 30_000 })
	await page.waitForFunction(() => {
		const body = getComputedStyle(document.body)
		return body.getPropertyValue('--color-main-text').trim() !== ''
			&& body.getPropertyValue('--color-main-background').trim() !== ''
	}, null, { timeout: 10_000 }).catch(() => {})
}

test.describe('TourCheck theme × viewport a11y matrix', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(300_000)

	for (const theme of USER_THEMES) {
		for (const route of routes) {
			test(`${theme}: ${route.id}`, async ({ page }) => {
				await login(page, creds)
				await gotoReady(page, route.path)
				await setUserTheme(page, theme)
				await expect(page.locator(route.ready)).toBeVisible({ timeout: 30_000 })
				await assertThemeTokensResolved(page)
				await assertDarkNavMatchesThemeTokens(page, theme)

				for (const viewport of overflowViewports) {
					await page.setViewportSize(viewport)
					await expectNoHorizontalOverflow(page, `${theme}/${route.id}@${viewport.width}px`)
				}
				await page.setViewportSize({ width: 1280, height: 800 })
				await assertTouchTargets(page)
				for (const viewport of axeViewports) {
					await page.setViewportSize(viewport)
					await runAxe(page, `${theme}/${route.id}@${viewport.width}px`)
				}
			})
		}
	}

	test('reset to default theme', async ({ page }) => {
		await login(page, creds)
		await gotoReady(page, '/apps/tourcheck/')
		await resetUserTheme(page)
	})
})

test.describe('TourCheck custom accent colour', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	test('primary tokens follow instance accent and stay AA', async ({ page }) => {
		await login(page, creds)
		await gotoReady(page, '/apps/tourcheck/')

		const readPrimary = () => page.evaluate(() => {
			const probe = getComputedStyle(document.body).getPropertyValue('--color-primary-element').trim()
			const tint = getComputedStyle(document.querySelector('#app-content.tck-app') || document.body)
				.getPropertyValue('--tck-tint-info').trim()
			return { variable: probe, tintInfo: tint }
		})

		const before = await readPrimary()
		expect(before.variable, 'NC must expose --color-primary-element').not.toEqual('')

		setAccentColor('#971003')
		try {
			await expect.poll(async () => {
				await page.reload({ waitUntil: 'load' })
				return (await readPrimary()).variable
			}, { timeout: 60_000, intervals: [1_000, 2_000, 3_000] }).not.toEqual(before.variable)

			await expect(page.locator('#tck-main-content')).toBeVisible({ timeout: 30_000 })
			const after = await readPrimary()
			expect(after.tintInfo, 'tint-info must still resolve after accent change').not.toEqual('')
			expect(/,\s*transparent\s*\)\s*$/i.test(after.tintInfo)).toBeFalsy()
			await runAxe(page, 'custom-accent/dashboard@1280px')
		} finally {
			resetAccentColor()
		}
	})
})

test.describe('TourCheck visual shell metrics', () => {
	const metricViewports = [
		{ name: 'mobile-320', width: 320, height: 640 },
		{ name: 'tablet-768', width: 768, height: 1024 },
		{ name: 'desktop-1440', width: 1440, height: 900 },
	]

	for (const theme of ['light', 'dark']) {
		for (const vp of metricViewports) {
			test(`shell metrics @ ${theme} ${vp.name}`, async ({ page }) => {
				await login(page, creds)
				await gotoReady(page, '/apps/tourcheck/')
				await setUserTheme(page, theme)
				await page.setViewportSize({ width: vp.width, height: vp.height })

				const metrics = await page.evaluate(() => {
					const shell = document.querySelector('#app-content-wrapper.tck-shell')
					const header = document.querySelector('.tck-page-header')
					const nav = document.querySelector('#app-navigation')
					const title = document.querySelector('#tck-page-title, .tck-page-header h1')
					const navToggle = document.querySelector('.tck-nav-toggle')
					const navToggleStyle = navToggle ? getComputedStyle(navToggle) : null
					const shellRect = shell?.getBoundingClientRect()
					const headerRect = header?.getBoundingClientRect()
					const titleRect = title?.getBoundingClientRect()
					return {
						shellWidth: shellRect ? Math.round(shellRect.width) : 0,
						headerVisible: !!(headerRect && headerRect.height > 0),
						titleClipped: titleRect
							? titleRect.right > (shellRect?.right ?? window.innerWidth) + 1
							: false,
						navPresent: !!nav,
						viewport: window.innerWidth,
						toggleDisplay: navToggleStyle?.display ?? 'absent',
					}
				})

				expect(metrics.shellWidth, 'shell must fill usable content width').toBeGreaterThan(200)
				expect(metrics.headerVisible, 'page header must render').toBeTruthy()
				expect(metrics.titleClipped, 'page title must not clip outside shell').toBeFalsy()
				expect(metrics.navPresent, 'navigation element must exist').toBeTruthy()
				if (metrics.viewport < 1024) {
					expect(
						metrics.toggleDisplay,
						'mobile nav toggle must be visible below 1024px',
					).not.toEqual('none')
				} else {
					expect(
						metrics.toggleDisplay,
						'nav toggle must stay hidden on desktop',
					).toEqual('none')
				}
				await expectNoHorizontalOverflow(page, `${theme}/${vp.name}`)
			})
		}
	}
})

test.describe('ATLAS_MOBILE_NAV_CONTRACT', () => {
	test('mobile nav: toggle, drawer, usable link, no h-pan', async ({ page }) => {
		await login(page, creds)
		await page.setViewportSize({ width: 375, height: 812 })
		await gotoReady(page, '/apps/tourcheck/')
		await assertAtlasMobileNav(page, {
			toggle: page.locator('#tck-nav-toggle'),
			nav: page.locator('#app-navigation'),
			openClass: /tck-nav--open/,
		})
	})
})
