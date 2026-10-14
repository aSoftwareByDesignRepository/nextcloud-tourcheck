import { test } from '@playwright/test'
import { login } from './helpers/auth.js'

const CREDS = { username: 'tck_atlas', password: 'TckAtlas2026!' }
const OUT = '/tmp/parity-shots'
const APPS = [
  ['tourcheck', '/index.php/apps/tourcheck/dashboard'],
  ['budgetcheck', '/index.php/apps/budgetcheck/'],
  ['ticketcheck', '/index.php/apps/ticketcheck/'],
]

test('visual parity: same chrome across family apps', async ({ page }) => {
  await login(page, CREDS)
  for (const [app, url] of APPS) {
    await page.goto(url, { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('#app-content')
    await page.screenshot({ path: `${OUT}/${app}.png`, fullPage: false })
    const fp = await page.evaluate(() => {
      const nav = document.querySelector('#app-navigation')
      const content = document.querySelector('#app-content')
      const skip = document.querySelector('[class*="skip"]')
      const crumbs = document.querySelector('[class*="breadcrumb"], nav[aria-label*="readcrumb"]')
      const buttons = [...document.querySelectorAll('#app-content button, #app-content .button, #app-content a.button')].slice(0, 3).map(b => {
        const s = getComputedStyle(b); const r = b.getBoundingClientRect()
        return `${s.borderColor}/${Math.round(r.height)}px`
      })
      return { nav: !!nav, navClass: nav?.className ?? '', content: content?.className ?? '', skip: !!skip, crumbs: !!crumbs, buttons }
    })
    console.log(`=== ${app} ===`, JSON.stringify(fp))
  }
})
