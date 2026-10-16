/**
 * Navigation + API guards for the shared dev stack.
 *
 * Phantom-404 class (documented in
 * bugs/20261014-e2e-double-submit-nav-race-and-concurrent-playwright.md):
 * a concurrent `occ upgrade`/app op wipes the APCu route cache — for a
 * beat every app route answers a bare Nextcloud 404 (empty body, no
 * #tck-main-content). The cache rebuilds on the next hit, so a bounded
 * retry recovers. A genuinely missing page retries identically and then
 * fails the same way — the guard changes nothing about real 404s.
 */

/**
 * Navigate to an app page and wait for the app shell. Retries when the
 * shell never renders (route-cache miss); a real missing/broken page
 * fails after the bound with the same signal a bare wait would give.
 */
export async function gotoApp(page, path, { contentSel = '#tck-main-content', retries = 2 } = {}) {
	for (let attempt = 0; attempt <= retries; attempt++) {
		await page.goto(path, { waitUntil: 'domcontentloaded' })
		const rendered = await page
			.waitForSelector(contentSel, { timeout: 10_000 })
			.then(() => true)
			.catch(() => false)
		if (rendered) {
			return
		}
	}
	throw new Error(`app shell ${contentSel} never rendered at ${path} after ${retries + 1} attempts`)
}

/**
 * Fetch wrapper for in-page API calls: retries once per attempt budget
 * when the response is an empty-body 404 (routing-table miss signature
 * — the app's own NOT_FOUND always carries a JSON body). Everything
 * else returns immediately, so real 404s still surface.
 */
export async function apiJson(page, buildFetch, { retries = 2 } = {}) {
	let last
	for (let attempt = 0; attempt <= retries; attempt++) {
		last = await buildFetch()
		if (!(last.status === 404 && String(last.body || '').trim() === '')) {
			return last
		}
	}
	return last
}

/**
 * Minimal app-API fetch for spec teardown (afterAll cleanup). Uses the
 * page's session cookies + the requesttoken from the currently loaded
 * app page — call gotoApp first so the token exists.
 */
export async function apiFetch(page, method, path, data) {
	const token = await page.evaluate(() =>
		(window.OC && window.OC.requestToken)
		|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
		|| '')
	const res = await page.request.fetch(`/index.php/apps/tourcheck${path}`, {
		method,
		data,
		headers: { requesttoken: token, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' },
	})
	return res.json().catch(() => ({ success: false }))
}
