import { defineConfig, devices } from '@playwright/test'

// Runs inside the `e2e` compose profile (./flow test e2e), against the running
// dev stack. Deliberately tiny: a smoke test that the Guardian Console loads.
export default defineConfig({
  testDir: './e2e',
  outputDir: './test-results',
  fullyParallel: true,
  forbidOnly: true,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://guardian.flowlife.localhost:18080',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
