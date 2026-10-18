import { acquireSharedInstanceLock } from './shared-instance-lock.mjs'
// @ts-check
import { execFileSync } from 'child_process'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { request } from '@playwright/test'
import { rememberSessionCookies } from './helpers/auth.js'

const nextcloudRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

/**
 * Clear leftover Nextcloud login throttling before the suite starts.
 *
 * Probe scripts or a crashed previous run may have left bruteforce attempts /
 * ratelimit buckets behind, making POST /login answer 303 back to /login. The
 * rate limiter uses the DatabaseBackend on this dev stack
 * (oc_ratelimit_entries + oc_bruteforce_attempts), so truncating both tables
 * resets it. Test-environment hygiene only — dev MariaDB, never production.
 */
function clearLoginThrottling() {
	const sql =
		'DELETE FROM oc_bruteforce_attempts;' +
		' DELETE FROM oc_ratelimit_entries;'
	try {
		const out = execFileSync(
			'docker',
			[
				'compose', 'exec', '-T', 'mariadb',
				'bash', '-lc',
				`mariadb -u root -p"\${MYSQL_ROOT_PASSWORD}" nextcloud -e '${sql}'`,
			],
			{ cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 },
		)
		process.stdout.write(`[e2e globalSetup] login throttling cleared: ${String(out).trim()}\n`)
	} catch (err) {
		process.stdout.write(
			`[e2e globalSetup] could not clear login throttling (${err && err.message ? err.message : err})\n`,
		)
	}
}

/**
 * Perform ONE real login for the suite user and cache the session cookies —
 * workers reuse them via login() in helpers/auth.js so the suite does not
 * DoS itself on the anon-IP login rate limit.
 */
async function primeSession(baseURL, username, password) {
	const context = await request.newContext({ baseURL })
	try {
		const loginPage = await context.get('/login')
		const html = await loginPage.text()
		const tokenMatch = html.match(/data-requesttoken="([^"]*)"/)
		const requesttoken = tokenMatch ? tokenMatch[1] : ''
		const res = await context.post('/login', {
			form: { user: username, password, requesttoken },
			headers: { Origin: baseURL, requesttoken },
			maxRedirects: 0,
		})
		const location = res.headers()['location'] || ''
		if (res.status() !== 303 || location.includes('/login')) {
			throw new Error(`login POST for "${username}" returned ${res.status()} -> ${location || '(no location)'}`)
		}
		const state = await context.storageState()
		rememberSessionCookies(username, state.cookies)
		// Honour E2E_STORAGE_STATE so concurrent/isolated runs can pin their
		// own storage-state file instead of sharing the tmpdir cache — the
		// shared-farm gate requires the env override to exist.
		if (process.env.E2E_STORAGE_STATE) {
			const { writeFileSync, mkdirSync } = await import('node:fs')
			const { dirname: d } = await import('node:path')
			mkdirSync(d(process.env.E2E_STORAGE_STATE), { recursive: true })
			writeFileSync(process.env.E2E_STORAGE_STATE, JSON.stringify(state))
		}
		process.stdout.write(`[e2e globalSetup] primed session for "${username}"\n`)
	} finally {
		await context.dispose()
	}
}

export default async function globalSetup(config) {
	await acquireSharedInstanceLock('tourcheck')
	clearLoginThrottling()

	const baseURL = (config.projects[0] && config.projects[0].use && config.projects[0].use.baseURL)
		|| process.env.NC_BASE_URL
		|| 'http://localhost:8081'

	const users = [
		[process.env.NC_E2E_USER || 'tck_atlas', process.env.NC_E2E_PASS || 'TckAtlas2026!'],
	]

	for (const [username, password] of users) {
		try {
			await primeSession(baseURL, username, password)
		} catch (err) {
			// Non-fatal: login() still works via the form if priming failed.
			process.stdout.write(
				`[e2e globalSetup] session priming failed for "${username}" (${err && err.message ? err.message : err})\n`,
			)
		}
	}
}
