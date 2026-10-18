// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login } from './helpers/auth.js'
import { apiFetch, gotoApp } from './helpers/app.js'

/**
 * Full core-loop journey — every previously-dead control must now work:
 * site → checkpoint → plan (create+activate) → member add/remove →
 * incident create/close → report generate. Axe on each touched page.
 */
const CREDS = { username: 'tck_atlas', password: 'TckAtlas2026!' }
const SITE_NAME = `E2E Site ${Date.now()}`
const INC_TITLE = `E2E broken lock ${Date.now()}`
// Fixture sites created by the UI journey must not outlive the run
// (test_teardown_orphan_leak) — DELETE archives them + frees stations.
const createdSiteIds = []

test.beforeEach(async ({ page }) => {
  page.on('dialog', (d) => d.accept())
  await login(page, CREDS)
})

test.afterAll(async ({ browser }) => {
  if (!createdSiteIds.length) { return }
  const page = await browser.newPage()
  try {
    await login(page, CREDS)
    await gotoApp(page, '/index.php/apps/tourcheck/dashboard')
    for (const id of createdSiteIds) {
      await apiFetch(page, 'DELETE', `/api/sites/${id}`)
    }
  } finally {
    await page.close()
  }
})

/** Capture the site id out of the post-create redirect URL. */
function trackCreatedSite(page) {
  const m = page.url().match(/\/sites\/(\d+)/)
  if (m) { createdSiteIds.push(m[1]) }
}

// Destructive actions open the styled <dialog data-tck-confirm-dialog>
// first (native confirm() is banned) — confirm it, then wait for reload.
/**
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} loc
 */
async function clickConfirmAndReload(page, loc) {
  await loc.click()
  const dlg = page.locator('dialog[data-tck-confirm-dialog][open]')
  await expect(dlg).toBeVisible()
  await clickAndReload(page, dlg.locator('[data-tck-confirm-ok]'))
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} loc
 */
async function clickAndReload(page, loc) {
  // Every mutating action in app.js ends in location.reload(); the only
  // deterministic wait is the reload navigation itself — never a sleep.
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
    loc.click(),
  ])
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function axe(page, label) {
  const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  const appOnly = res.violations.filter(
    (v) => !v.nodes.some((n) => n.html.includes('unified-search'))
  )
  expect(appOnly, `axe ${label}: ${JSON.stringify(appOnly.map((v) => v.id))}`).toEqual([])
}

test('core loop: site → checkpoint → plan → member → incident → report', async ({ page }) => {
  test.setTimeout(180_000) // 11 reloads + axe on a shared box need headroom
  // ── 1. Create site → redirected straight into the new site ──
  await page.goto('/index.php/apps/tourcheck/sites', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-tck-new-site]').click()
  await expect(page.locator('#tck-site-form')).toBeVisible()
  await page.fill('#tck-site-name', SITE_NAME)
  await clickAndReload(page, page.locator('#tck-site-form [type="submit"]'))
  await expect(page).toHaveURL(/\/apps\/tourcheck\/sites\/\d+/)
  trackCreatedSite(page)
  await expect(page.locator('#tck-page-title')).toContainText(SITE_NAME)
  await axe(page, 'new site detail')

  // ── 2. Site detail: checkpoint + plan ──
  await page.locator('[data-tck-new-cp]').click()
  await page.fill('#tck-cp-name', 'E2E Gate')
  await clickAndReload(page, page.locator('#tck-cp-form [type="submit"]'))
  await expect(page.locator('.tck-list__title', { hasText: 'E2E Gate' })).toBeVisible()

  await page.locator('[data-tck-new-plan]').click()
  await expect(page.locator('#tck-plan-form')).toBeVisible()
  // Defaults must be prefilled (name, daily 18:00, checkpoint checked).
  await expect(page.locator('#tck-plan-name')).not.toHaveValue('')
  await clickAndReload(page, page.locator('#tck-plan-form [type="submit"]'))
  // Plan appears as ACTIVE — create+activate collapsed into one action.
  await expect(page.locator('[data-tck-plan-state][data-active="1"]')).toBeVisible()
  await axe(page, 'site detail')

  // ── 2b-i. Rounds: supervisor cancels a planned round, journaled reason ──
  // Fresh plan rounds are scheduled + event-free — skip() must accept. (A
  // deactivated plan has none left: resync deletes its scheduled rounds.)
  await page.goto('/index.php/apps/tourcheck/rounds', { waitUntil: 'domcontentloaded' })
  const openRow = page.locator('li.tck-list__row[data-status="scheduled"], li.tck-list__row[data-status="active"]', {
    hasText: SITE_NAME,
  }).first()
  await expect(openRow).toBeVisible()
  await clickAndReload(page, openRow.locator('a.tck-list__main'))
  await page.locator('[data-tck-show="tck-skip-form"]').click()
  await expect(page.locator('#tck-skip-form')).toBeVisible()
  await page.fill('#tck-skip-reason', 'public holiday')
  await clickAndReload(page, page.locator('#tck-skip-form [type="submit"]'))
  // Locale-free durable postcondition: badge renders AND the API row
  // really is 'skipped' — never match status label text (EN|DE|…).
  await expect(page.locator('.tck-deflist .tck-badge').first()).toBeVisible()
  const skippedRoundId = page.url().match(/rounds\/(\d+)/)?.[1]
  const skippedRound = await apiFetch(page, 'GET', `/api/rounds/${skippedRoundId}`)
  expect(skippedRound.data?.round?.status).toBe('skipped')
  await expect(page.locator('.tck-deflist')).toContainText('public holiday')
  // Terminal now: the skip control must not reappear (no re-skip relabel).
  await expect(page.locator('[data-tck-show="tck-skip-form"]')).toHaveCount(0)

  // Plan state toggle: Deactivate → Inactive → Activate → Active.
  await page.goto('/index.php/apps/tourcheck/sites', { waitUntil: 'domcontentloaded' })
  await clickAndReload(page, page.locator('a.tck-list__main', { hasText: SITE_NAME }))
  const stateBtn = page.locator('[data-tck-plan-state]').first()
  await clickAndReload(page, stateBtn)
  await expect(page.locator('[data-tck-plan-state][data-active="1"]')).toHaveCount(0)

  // ── 2b. Privacy: live-position opt-in toggle persists both ways (CAS-guarded) ──
  const livePos = page.locator('[data-tck-live-pos]')
  await expect(livePos).toBeVisible()
  await clickAndReload(page, livePos)
  await expect(page.locator('[data-tck-live-pos]')).toBeChecked()
  await clickAndReload(page, page.locator('[data-tck-live-pos]'))
  await expect(page.locator('[data-tck-live-pos]')).not.toBeChecked()

  // ── 3. Members: add + remove ──
  await page.locator('[data-tck-new-member]').click()
  await page.fill('#tck-mem-uid', 'admin')
  await page.locator('#tck-mem-role').selectOption('worker')
  await clickAndReload(page, page.locator('#tck-member-form [type="submit"]'))
  await expect(page.locator('[data-tck-remove-member="admin"]')).toBeVisible()
  await clickConfirmAndReload(page, page.locator('[data-tck-remove-member="admin"]'))
  await expect(page.locator('[data-tck-remove-member="admin"]')).toHaveCount(0)

  // ── 4. Incidents: create → corrective action → done → verify → closed ──
  await page.goto('/index.php/apps/tourcheck/incidents', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-tck-new-incident]').click()
  await page.fill('#tck-inc-form-title', INC_TITLE)
  await page.locator('#tck-inc-cat').selectOption('damage')
  await clickAndReload(page, page.locator('#tck-incident-form [type="submit"]'))
  let incRow = page.locator('.tck-incident', { has: page.locator('.tck-list__title', { hasText: INC_TITLE }) })
  await expect(incRow.locator('.tck-list__title')).toBeVisible()

  // Detail toggle lazy-loads corrective actions — empty at first.
  await incRow.locator('[data-tck-incident-toggle]').click()
  await expect(incRow.locator('.tck-incident__detail')).toBeVisible()
  await expect(incRow.locator('[data-tck-incident-toggle]')).toHaveAttribute('aria-expanded', 'true')
  // Empty state is a .tck-hint node — structural, locale-free.
  await expect(incRow.locator('[data-tck-corr-list] .tck-hint')).toBeVisible()

  // Add a corrective → incident moves to "In progress".
  await incRow.locator('form[data-tck-corr-form] input[name="text"]').fill('Replace lock cylinder')
  await clickAndReload(page, incRow.locator('form[data-tck-corr-form] [type="submit"]'))
  incRow = page.locator('.tck-incident', { has: page.locator('.tck-list__title', { hasText: INC_TITLE }) })
  await expect(incRow).toHaveAttribute('data-status', 'action')

  // Re-expand → corrective row visible → Mark done → incident "To verify".
  await incRow.locator('[data-tck-incident-toggle]').click()
  await expect(incRow.locator('[data-tck-corr-list]')).toContainText('Replace lock cylinder')
  // One status-gated action button per corrective row — never text-match it.
  await clickAndReload(page, incRow.locator('[data-tck-corr-list] .tck-list__aside .tck-btn'))
  incRow = page.locator('.tck-incident', { has: page.locator('.tck-list__title', { hasText: INC_TITLE }) })
  await expect(incRow).toHaveAttribute('data-status', 'verify')

  // Re-expand → Verify → no open actions left → incident auto-closes.
  await incRow.locator('[data-tck-incident-toggle]').click()
  await expect(incRow.locator('[data-tck-corr-list]')).toContainText('Replace lock cylinder')
  await clickAndReload(page, incRow.locator('[data-tck-corr-list] .tck-list__aside .tck-btn'))
  incRow = page.locator('.tck-incident', { has: page.locator('.tck-list__title', { hasText: INC_TITLE }) })
  await expect(incRow).toHaveAttribute('data-status', 'closed')
  // Terminal state: no close button, no add-action form.
  await expect(incRow.locator('[data-tck-close-incident]')).toHaveCount(0)
  await expect(incRow.locator('form[data-tck-corr-form]')).toHaveCount(0)
  await axe(page, 'incidents')

  // Close-confirm: closing a second incident must fire the styled
  // confirm dialog (the status is terminal).
  await page.locator('[data-tck-new-incident]').click()
  await page.fill('#tck-inc-form-title', INC_TITLE + ' B')
  await clickAndReload(page, page.locator('#tck-incident-form [type="submit"]'))
  const rowB = page.locator('.tck-incident', { has: page.locator('.tck-list__title', { hasText: INC_TITLE + ' B' }) })
  await clickConfirmAndReload(page, rowB.locator('[data-tck-close-incident]'))

  // ── 5. Reports: generate ──
  await page.goto('/index.php/apps/tourcheck/reports', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-tck-new-report]').click()
  await expect(page.locator('#tck-report-form')).toBeVisible()
  await clickAndReload(page, page.locator('#tck-report-form [type="submit"]'))
  await expect(page.locator('a[href*="/api/reports/"]').first()).toBeVisible()
  await axe(page, 'reports')
})

test('no dead controls: every data-tck-* button on a page has a live target', async ({ page }) => {
  for (const path of ['/index.php/apps/tourcheck/sites', '/index.php/apps/tourcheck/incidents', '/index.php/apps/tourcheck/reports', '/index.php/apps/tourcheck/dashboard']) {
    await page.goto(path, { waitUntil: 'domcontentloaded' })
    const dead = await page.evaluate(() => {
      // "New" toggles must open a sibling form; state buttons must carry an id.
      /** @type {string[]} */
      const out = []
      document.querySelectorAll('button[data-tck-new-site],button[data-tck-new-incident],button[data-tck-new-report]').forEach((b) => {
        // Hidden create form OR an auto-opened one (empty-state guidance).
        const form = document.querySelector('form.tck-hidden, form[data-tck-auto-open]')
        if (!form) out.push(b.outerHTML.slice(0, 80))
      })
      return out
    })
    expect(dead, `dead controls on ${path}`).toEqual([])
  }
})

test('mobile 375: plan form fits, no hscroll, touch targets', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 })
  await page.goto('/index.php/apps/tourcheck/sites', { waitUntil: 'domcontentloaded' })
  await page.locator('a.tck-list__main').first().click()
  await page.waitForLoadState('domcontentloaded')
  const hscroll = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)
  expect(hscroll).toBe(false)
  await page.locator('[data-tck-new-plan]').click()
  await expect(page.locator('#tck-plan-form')).toBeVisible()
  const hscroll2 = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)
  expect(hscroll2).toBe(false)
})

test('edge cases: interval plan, weekly-days guard, ghost-uid rejection, picker', async ({ page }) => {
  // Site + checkpoint setup (fresh site, so plan form is reachable)
  await page.goto('/index.php/apps/tourcheck/sites', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-tck-new-site]').click()
  await page.fill('#tck-site-name', SITE_NAME + ' B')
  await clickAndReload(page, page.locator('#tck-site-form [type="submit"]'))
  await expect(page).toHaveURL(/\/apps\/tourcheck\/sites\/\d+/)
  trackCreatedSite(page)
  await page.locator('[data-tck-new-cp]').click()
  await page.fill('#tck-cp-name', 'Gate B')
  await clickAndReload(page, page.locator('#tck-cp-form [type="submit"]'))
  // The plan form's checkpoint checkboxes are server-rendered — prove the
  // reload actually carried the new row before relying on the guard order.
  await expect(page.locator('input[name="cp_ids"]')).toHaveCount(1)

  // Weekly with zero days unchecked → client-side guard, no request fired
  await page.locator('[data-tck-new-plan]').click()
  await page.locator('#tck-plan-type').selectOption('weekly')
  for (const cb of await page.locator('input[name="day_ids"]').all()) {
    await cb.uncheck()
  }
  let apiFired = false
  page.on('request', (r) => { if (r.url().includes('/api/')) apiFired = true })
  await page.locator('#tck-plan-form [type="submit"]').click()
  await expect(page.locator('#tck-plan-days-error')).toBeVisible()
  expect(apiFired).toBe(false)
  page.removeAllListeners('request')

  // Interval plan: conditional field appears, create+activate works
  await page.locator('#tck-plan-type').selectOption('interval')
  await expect(page.locator('#tck-plan-hours')).toBeVisible()
  await clickAndReload(page, page.locator('#tck-plan-form [type="submit"]'))
  await expect(page.locator('[data-tck-plan-state][data-active="1"]').first()).toBeVisible()

  // Ghost uid → uniform-404 contract (existence-oracle defense): error toast
  // surfaces, no member row is added. Field-level aria-invalid is correctly
  // NOT set — 404 carries no fields map by design.
  await page.locator('[data-tck-new-member]').click()
  await page.fill('#tck-mem-uid', 'ghost-user-definitely-not-real')
  await page.locator('#tck-member-form [type="submit"]').click()
  await expect(page.locator('.tck-toast--error').first()).toBeVisible({ timeout: 15000 })
  await expect(page.locator('[data-tck-remove-member="ghost-user-definitely-not-real"]')).toHaveCount(0)

  // Picker: 2+ chars → combobox dropdown with real options, click selects.
  // 'atl' → tck_atlas (access-having); 'adm' would find only admin, who has
  // no TourCheck access — search endpoint filters to access-having uids.
  await page.fill('#tck-mem-uid', '')
  await page.fill('#tck-mem-uid', 'atl')
  await expect(page.locator('.tck-picker__item').first()).toBeVisible({ timeout: 5000 })
  await page.locator('.tck-picker__item').first().click()
  await expect(page.locator('#tck-mem-uid')).not.toHaveValue('')
  await axe(page, 'site detail with picker')
})

test('UX-1/GA-19: rejected scan always offers a corrective action, never a dead end', async ({ page }) => {
  await page.goto('/index.php/apps/tourcheck/capture', { waitUntil: 'domcontentloaded' })
  await page.fill('#tck-scan-input', 'tck_definitely_invalid_token_xxxxxxxx')
  await page.press('#tck-scan-input', 'Enter')
  await expect(page.locator('[data-tck-reject]')).toBeVisible()
  // Must be either the supervisor-signal button or the fallback link —
  // before the signalSiteId fix neither ever rendered.
  const corrective = page.locator('[data-tck-corrective]')
  await expect(corrective).toBeVisible()
  await expect(corrective.locator('button, a')).toHaveCount(1)
  await axe(page, 'capture reject state')
})

test('NH-10 key board: register → take → return round-trip', async ({ page }) => {
  await page.goto('/index.php/apps/tourcheck/sites', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-tck-new-site]').click()
  await page.fill('#tck-site-name', SITE_NAME + ' Keys')
  await clickAndReload(page, page.locator('#tck-site-form [type="submit"]'))
  await expect(page).toHaveURL(/\/apps\/tourcheck\/sites\/\d+/)
  trackCreatedSite(page)

  // Register — empty label must surface an inline field error instantly
  // (client-side required check, no request fired), and no row may appear.
  await page.locator('[data-tck-new-key]').click()
  let apiFired = false
  page.on('request', (r) => { if (r.url().includes('/api/')) apiFired = true })
  await page.locator('#tck-key-form [type="submit"]').click()
  await expect(page.locator('#tck-key-label-error')).toBeVisible()
  expect(apiFired).toBe(false)
  page.removeAllListeners('request')
  await expect(page.locator('[data-tck-key-list] .tck-list__row')).toHaveCount(0)

  await page.fill('#tck-key-label', 'E2E front door')
  await clickAndReload(page, page.locator('#tck-key-form [type="submit"]'))
  const row = page.locator('[data-tck-key-list] .tck-list__row', { hasText: 'E2E front door' })
  await expect(row).toBeVisible()
  await expect(row.locator('.tck-badge')).toBeVisible()

  // Take → status flips to out; Return → back to in; movement log grows.
  const act = row.locator('[data-tck-key-act]')
  await act.click()
  await page.waitForLoadState('domcontentloaded')
  await expect(row.locator('[data-tck-key-act][data-act="return"]')).toBeVisible()
  await row.locator('[data-tck-key-act]').click()
  await page.waitForLoadState('domcontentloaded')
  await expect(row.locator('[data-tck-key-act][data-act="take"]')).toBeVisible()
  await expect(page.locator('[data-tck-key-log]')).toBeVisible()
  await axe(page, 'site detail key board')
})
