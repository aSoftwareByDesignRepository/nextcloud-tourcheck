import { defineConfig, devices } from '@playwright/test'

const baseURL = process.env.NC_BASE_URL || 'http://localhost:8081'

export default defineConfig({
  testDir: 'e2e',
  globalSetup: './e2e/global-setup.js',
  // Playwright deletes outputDir at run start — a fixed path means any
  // sibling run on this shared box wipes in-flight traces of a concurrent
  // suite (browserContext.close ENOENT). Unique per run; PW_OUTPUT_DIR
  // overrides when a stable dir is needed (CI artifact upload).
  outputDir: process.env.PW_OUTPUT_DIR || `test-results/run-${process.pid}-${Date.now().toString(36)}`,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
