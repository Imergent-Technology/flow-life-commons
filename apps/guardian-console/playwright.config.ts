import { defineConfig, devices } from '@playwright/test'

// Runs inside the `e2e` compose profile (./flow test e2e), against the running dev stack. Two origins, on purpose:
//
//   built        the DEFAULT. The Console's production BUILD (dist/, which ./flow test e2e rebuilds first and e2e/globalSetup.ts refuses to run
//                without), served beside the real Laravel API on one origin by the gateway's production-equivalent site, under the exact production
//                security headers (ADR 0026). Every functional, security, accessibility and responsive journey runs here, so what is tested is
//                what is shipped. It costs a cold page 7 requests where the development server costs 258.
//   development  the Vite development server through the same gateway, for the one contract that exists only there: the development
//                gateway's routing and its HMR websocket (e2e/gateway.spec.ts). Nothing else belongs here.
//
// E2E_BASE_URL is the DEVELOPMENT origin (specs that derive the gateway's port from it keep doing so). The built origin is the same gateway port
// on `prod.flowlife.localhost`, a host the gateway's Caddyfile defines.
const developmentURL = process.env.E2E_BASE_URL ?? 'http://commons.flowlife.localhost:18080'
const builtURL =
  process.env.E2E_BUILT_URL ?? `http://prod.flowlife.localhost:${new URL(developmentURL).port}`

/** The journeys that test the development server itself. */
const DEVELOPMENT_ONLY = ['**/gateway.spec.ts']

export default defineConfig({
  testDir: './e2e',
  outputDir: './test-results',
  globalSetup: './e2e/globalSetup.ts',
  fullyParallel: true,
  forbidOnly: true,
  retries: 0,
  reporter: [['list']],
  use: {
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'built',
      use: { ...devices['Desktop Chrome'], baseURL: builtURL },
      testIgnore: DEVELOPMENT_ONLY,
    },
    {
      name: 'development',
      use: { ...devices['Desktop Chrome'], baseURL: developmentURL },
      testMatch: DEVELOPMENT_ONLY,
    },
  ],
})
