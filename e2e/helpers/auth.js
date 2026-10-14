import { readFileSync, renameSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

/**
 * Cross-test session cache.
 *
 * Every POST /login counts against the instance's anon-IP rate limit
 * (DatabaseBackend on this dev stack) — ~40 fresh form logins mid-suite
 * poison the bucket and every later login lands back on /login.
 * globalSetup performs ONE real login and stores the session cookies here;
 * login() injects them and only falls back to the form when the cached
 * session is missing or rejected. Same pattern as contractcheck e2e.
 */
const SESSION_CACHE = join(tmpdir(), 'tourcheck-e2e-sessions.json')

function readSessionCache() {
  try {
    const parsed = JSON.parse(readFileSync(SESSION_CACHE, 'utf8'))
    return parsed && typeof parsed === 'object' ? parsed : {}
  } catch {
    return {}
  }
}

function writeSessionCache(cache) {
  const tmp = `${SESSION_CACHE}.${process.pid}.${Date.now()}.tmp`
  writeFileSync(tmp, JSON.stringify(cache))
  renameSync(tmp, SESSION_CACHE)
}

export function rememberSessionCookies(username, cookies) {
  if (!username || !Array.isArray(cookies) || !cookies.length) {
    return
  }
  const cache = readSessionCache()
  cache[username] = { cookies, savedAt: Date.now() }
  writeSessionCache(cache)
}

function forgetSessionCookies(username) {
  const cache = readSessionCache()
  if (cache[username]) {
    delete cache[username]
    writeSessionCache(cache)
  }
}

/**
 * Shared dev stack: a sibling `occ upgrade` puts the instance in maintenance
 * mode for seconds — every route then answers the 503 interstitial. Poll
 * /status.php briefly; if it is still down after the bound, that is a real
 * environment fault, not a flake.
 */
async function waitOutMaintenance(page, timeoutMs = 120_000) {
	const deadline = Date.now() + timeoutMs
	for (;;) {
		const res = await page.context().request.get('/status.php', { timeout: 5_000 }).catch(() => null)
		const body = res && res.ok() ? await res.json().catch(() => null) : null
		if (body && body.maintenance === false) {
			return
		}
		if (Date.now() > deadline) {
			throw new Error('Nextcloud stayed in maintenance/upgrade mode >2min — finish `occ upgrade` before E2E')
		}
		await new Promise((resolve) => setTimeout(resolve, 2_000))
	}
}

/**
 * Attach a cached session and verify the server accepts it (a request that
 * 303s back to /login means the session is dead → evict + report false).
 */
async function tryReuseSession(page, username) {
  const cached = readSessionCache()[username]
  if (!cached || !Array.isArray(cached.cookies) || !cached.cookies.length) {
    return false
  }
  try {
    await page.context().addCookies(cached.cookies)
  } catch {
    return false
  }
  const res = await page.context().request.get('/', { maxRedirects: 0 }).catch(() => null)
  if (!res) {
    return false
  }
  const location = res.headers()['location'] || ''
  if (location.includes('/login')) {
    forgetSessionCookies(username)
    return false
  }
  return true
}

/**
 * Minimal TourCheck login helper — request-context login so the session
 * cookie lands in the page's own jar (same trick as whistlecheck e2e).
 */
export async function login(page, { username, password }) {
  if (await tryReuseSession(page, username)) {
    return
  }
  const request = page.context().request
  const loginPage = await request.get('/login')
  const html = await loginPage.text()
  const tokenMatch = html.match(/data-requesttoken="([^"]+)"/)
  if (!tokenMatch) {
    throw new Error('Login page did not yield a requesttoken')
  }
  const requesttoken = decodeURIComponent(tokenMatch[1])
  const res = await request.post('/login', {
    form: { user: username, password, requesttoken },
    headers: { Origin: process.env.NC_BASE_URL || 'http://localhost:8081' },
    maxRedirects: 0,
  })
  const location = res.headers()['location'] || ''
  if (res.status() !== 303 || location.startsWith('/login')) {
    throw new Error(`Login rejected for "${username}"`)
  }
  try {
    rememberSessionCookies(username, await page.context().cookies())
  } catch { /* best-effort cache */ }
}
