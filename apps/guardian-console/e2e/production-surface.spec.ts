import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs'

import { expect, test, type Page } from '@playwright/test'

/**
 * The COMPOSED production public surface, in a real browser.
 *
 * Until now every rule of the production origin had been proved on its own — the host probed the
 * maintenance condition, the rewrite engine, `mod_headers` and the private-path denials one at a time
 * (production readiness, section 4a) — and the ordered whole had never been exercised. Rule ordering
 * is exactly where a file like this goes wrong: a maintenance arm placed before the private-path
 * denials answers 503 for `/.env`, and an SPA fallback placed before the API carve-out answers an
 * unknown API path with the Console's HTML shell and a 200. Both are invisible to a per-rule test and
 * obvious here.
 *
 * WHAT THIS PROVES, PRECISELY: the externally visible contract, against the production-equivalent
 * origin (`prod.flowlife.localhost`), which serves the Console's real production BUILD beside the
 * real API under the real policy. It does NOT execute the production `.htaccess` — that file is
 * Apache's and there is no Apache here. The two are kept in lockstep by construction (the Caddyfile
 * mirrors it section by section), by scripts/tests/public-surface.sh (neither file may gain a rule
 * class the other lacks) and by ProductionSurfaceTest (the `.htaccess` is pinned structurally,
 * including rule order). What the real host has proved about the real file is a separate and narrower
 * claim, recorded in docs/runbooks/production-readiness.md.
 *
 * The maintenance journeys are tagged @maintenance and run in their own serial pass (`./flow test
 * e2e`), because the flag they raise is global to the origin and would otherwise fail every journey
 * running beside them.
 */

const PORT = new URL(process.env.E2E_BASE_URL ?? 'http://commons.flowlife.localhost:18080').port
const PROD = `http://prod.flowlife.localhost:${PORT}`

/**
 * The authoritative maintenance flag, as the gateway and Apache both see it: Laravel's own
 * `storage/framework/down` (ADR 0027 — one authority, two enforcement points). In production it is
 * `shared/storage/framework/down`, reached through the release's storage symlink.
 *
 * `php artisan down` is the only thing that writes this in real life, and there is no PHP in this
 * container, so these journeys write the same payload the command writes. That is faithful to what is
 * under test: both enforcement points key off the file's EXISTENCE, and Laravel's own half reads this
 * JSON. `php artisan up` runs after this pass regardless of outcome, so a crashed run cannot leave the
 * development origin down.
 */
const FLAG = '/var/www/platform/storage/framework/down'
const FLAG_PAYLOAD = JSON.stringify({
  except: [],
  redirect: null,
  retry: null,
  refresh: null,
  secret: null,
  status: 503,
  template: null,
})

/** Every header ADR 0026 requires on this origin. HSTS is HTTPS-only and absent here by design. */
const POLICY_HEADERS = {
  'content-security-policy':
    "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; " +
    "connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
  'x-content-type-options': 'nosniff',
  'referrer-policy': 'same-origin',
  'x-frame-options': 'DENY',
  'permissions-policy':
    'camera=(), microphone=(), geolocation=(), payment=(), usb=(), display-capture=(), ' +
    'publickey-credentials-get=(), screen-wake-lock=()',
  'cross-origin-opener-policy': 'same-origin',
  'cross-origin-resource-policy': 'same-origin',
}

/** Paths that must never be readable over HTTP, whatever else is true of the origin. */
const PRIVATE_PATHS = [
  '/.env',
  '/.env.production',
  '/.git/config',
  '/vendor/autoload.php',
  '/storage/logs/laravel.log',
  '/storage/framework/down',
  '/app/Providers/AppServiceProvider.php',
  '/config/security.php',
  '/database/migrations',
  '/routes/api.php',
  '/bootstrap/app.php',
  '/artisan',
  '/composer.json',
  '/composer.lock',
  '/release.json',
]

interface Fetched {
  status: number
  contentType: string
  body: string
  headers: Record<string, string>
}

/**
 * Same-origin fetch from a loaded page: Playwright's Node-side `request` fixture cannot resolve
 * *.localhost, and this is also how the Console itself calls the origin.
 */
async function fetchFrom(page: Page, path: string, wanted: string[] = []): Promise<Fetched> {
  return page.evaluate(
    async ([target, names]: [string, string[]]) => {
      const response = await fetch(target, { headers: { Accept: 'text/html,*/*' } })
      const headers: Record<string, string> = {}
      for (const name of names) {
        headers[name] = response.headers.get(name) ?? ''
      }
      return {
        status: response.status,
        contentType: response.headers.get('content-type') ?? '',
        body: await response.text(),
        headers,
      }
    },
    [path, wanted] as [string, string[]],
  )
}

/** The Console's built shell, identified by the mount point its bundle looks for. */
function isConsoleShell(body: string): boolean {
  return body.includes('id="root"')
}

function isMaintenancePage(body: string): boolean {
  return body.includes('<h1>Down for maintenance</h1>')
}

async function expectPolicyHeaders(page: Page, path: string): Promise<void> {
  const result = await fetchFrom(page, path, Object.keys(POLICY_HEADERS))

  for (const [name, value] of Object.entries(POLICY_HEADERS)) {
    // Exactly the policy, once. A duplicated header arrives joined by ", " and fails here, which is
    // deliberate: two CSP headers are intersected by the browser and are not the policy that was written.
    expect(result.headers[name], `${name} on ${path}`).toBe(value)
  }
}

test.describe('composed production surface: serving normally', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(PROD)
  })

  test('serves the Console shell at the root', async ({ page }) => {
    const result = await fetchFrom(page, '/')

    expect(result.status).toBe(200)
    expect(result.contentType).toContain('text/html')
    expect(isConsoleShell(result.body)).toBe(true)
  })

  test('falls back to the Console shell for a client-side route', async ({ page }) => {
    // The SPA fallback: /people/123 is no file on disk, and must boot the Console rather than 404.
    const result = await fetchFrom(page, '/people/123')

    expect(result.status).toBe(200)
    expect(isConsoleShell(result.body)).toBe(true)
  })

  test('boots the Console from a deep link in a real navigation', async ({ page }) => {
    const response = await page.goto(`${PROD}/people/123`)

    expect(response?.status()).toBe(200)
    // Nobody is signed in, so the Console's own router redirects: proof the shell ran, not just served.
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
  })

  test('serves the real built assets as themselves', async ({ page }) => {
    const shell = await fetchFrom(page, '/')
    const asset = /src="([^"]+\.js)"/.exec(shell.body)?.[1]
    const style = /href="([^"]+\.css)"/.exec(shell.body)?.[1]
    expect(asset, 'the built shell must reference a hashed script').toBeTruthy()
    expect(style, 'the built shell must reference a hashed stylesheet').toBeTruthy()

    const js = await fetchFrom(page, asset ?? '')
    const css = await fetchFrom(page, style ?? '')

    expect(js.status).toBe(200)
    expect(js.contentType).toMatch(/javascript/)
    expect(isConsoleShell(js.body)).toBe(false)
    expect(css.status).toBe(200)
    expect(css.contentType).toContain('text/css')
  })

  test('serves the platform public files that sit beside the build', async ({ page }) => {
    // In production these are real files in the same document root as the Console's shell.
    const favicon = await fetchFrom(page, '/favicon.ico')
    const robots = await fetchFrom(page, '/robots.txt')

    expect(favicon.status).toBe(200)
    expect(isConsoleShell(favicon.body)).toBe(false)
    expect(robots.status).toBe(200)
    expect(robots.contentType).toContain('text/plain')
  })

  test('routes the API to Laravel', async ({ page }) => {
    const result = await fetchFrom(page, '/api/v1/health')

    expect(result.status).toBe(200)
    expect(result.contentType).toContain('application/json')
    expect(JSON.parse(result.body)).toMatchObject({ status: 'ok' })
  })

  for (const path of ['/api', '/api/nope', '/api/v1/nope']) {
    test(`answers unknown API path ${path} with JSON, never the Console shell`, async ({
      page,
    }) => {
      // The carve-out sits before the SPA fallback. Without it this is a 200 and an HTML shell, and
      // every API client's error handling breaks in a way no status code reveals.
      const result = await fetchFrom(page, path)

      expect(result.status).toBe(404)
      expect(result.contentType).toContain('application/json')
      expect(isConsoleShell(result.body)).toBe(false)
    })
  }

  test('answers the liveness probe from Laravel', async ({ page }) => {
    const result = await fetchFrom(page, '/up')

    expect(result.status).toBe(200)
    expect(result.contentType).toContain('text/plain')
    expect(result.body.trim()).toBe('up')
  })

  for (const path of PRIVATE_PATHS) {
    test(`refuses the private path ${path}`, async ({ page }) => {
      const result = await fetchFrom(page, path)

      expect(result.status).toBe(403)
      // The failure this guards against is not a 200 with the file: it is the SPA fallback quietly
      // answering 200 with the Console shell, which looks fine in a browser and hides the hole.
      expect(isConsoleShell(result.body)).toBe(false)
      expect(result.body).not.toContain('APP_KEY')
    })
  }

  test('does not serve a private path through traversal', async ({ page }) => {
    for (const path of ['/assets/%2e%2e/.env', '/%2e%2e/shared/.env', '/assets/../.env']) {
      const result = await fetchFrom(page, path)

      expect(result.status, path).not.toBe(200)
      expect(result.body, path).not.toContain('APP_KEY')
    }
  })

  test('leaves the ACME challenge path reachable', async ({ page }) => {
    // The dot-path denial must not deny /.well-known, or certificate renewal on the host fails. There
    // is no challenge file to serve, so the contract is simply that it is not refused as private.
    const result = await fetchFrom(page, '/.well-known/acme-challenge/probe-token')

    expect(result.status).not.toBe(403)
  })

  for (const path of [
    '/',
    '/people/123',
    '/api/v1/health',
    '/api/v1/nope',
    '/.env',
    '/favicon.ico',
  ]) {
    test(`carries the full browser security policy on ${path}`, async ({ page }) => {
      // ADR 0026 covers the WHOLE origin, which is the reason the policy lives in the web server's
      // own file: a 403 and an asset never reach PHP, so middleware could not dress them.
      await expectPolicyHeaders(page, path)
    })
  }
})

test.describe('composed production surface: maintenance @maintenance', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(() => {
    mkdirSync(FLAG.slice(0, FLAG.lastIndexOf('/')), { recursive: true })
    writeFileSync(FLAG, FLAG_PAYLOAD)
  })

  test.afterAll(() => {
    rmSync(FLAG, { force: true })
  })

  test('is actually down before anything else is asserted', () => {
    expect(existsSync(FLAG)).toBe(true)
  })

  test('answers the maintenance page for the Console root', async ({ page }) => {
    await page.goto(PROD)
    const result = await fetchFrom(page, '/', ['retry-after', 'cache-control', 'content-type'])

    expect(result.status).toBe(503)
    expect(isMaintenancePage(result.body)).toBe(true)
    expect(isConsoleShell(result.body)).toBe(false)
    expect(result.headers['retry-after']).toBe('120')
    expect(result.headers['cache-control']).toBe('no-store, no-cache, must-revalidate')
    expect(result.headers['content-type']).toBe('text/html; charset=utf-8')
  })

  test('intercepts client-side routes and static assets too', async ({ page }) => {
    await page.goto(PROD)

    for (const path of ['/people/123', '/login', '/favicon.ico', '/assets/anything.css']) {
      const result = await fetchFrom(page, path)

      expect(result.status, path).toBe(503)
      expect(isMaintenancePage(result.body), path).toBe(true)
    }
  })

  test('renders the maintenance page in a real navigation', async ({ page }) => {
    const response = await page.goto(`${PROD}/people/123`)

    expect(response?.status()).toBe(503)
    await expect(
      page.getByRole('heading', { level: 1, name: 'Down for maintenance' }),
    ).toBeVisible()
  })

  test('never rewrites the API to the maintenance responder', async ({ page }) => {
    await page.goto(PROD)

    for (const path of ['/api', '/api/v1/health', '/api/v1/nope']) {
      const result = await fetchFrom(page, path)

      // Laravel's own maintenance handling answers, and it negotiates content: a JSON caller gets
      // JSON. A static HTML 503 delivered to an API client is worse than the outage it reports.
      expect(result.contentType, path).toContain('application/json')
      expect(isMaintenancePage(result.body), path).toBe(false)
      expect(result.status, path).toBe(503)
    }
  })

  test('leaves the liveness probe to Laravel', async ({ page }) => {
    await page.goto(PROD)
    const result = await fetchFrom(page, '/up')

    // Down, as the documented contract says — but answered by the application, not by the responder.
    expect(result.status).toBe(503)
    expect(isMaintenancePage(result.body)).toBe(false)
  })

  test('serves the responder directly without looping', async ({ page }) => {
    await page.goto(PROD)
    const result = await fetchFrom(page, '/maintenance.php')

    // The responder excludes itself from the rewrite. Without that exclusion this is an internal
    // rewrite loop, which Apache answers with a 500 in the middle of an outage.
    expect(result.status).toBe(503)
    expect(isMaintenancePage(result.body)).toBe(true)
  })

  test('keeps private paths private while down', async ({ page }) => {
    await page.goto(PROD)

    for (const path of ['/.env', '/vendor/autoload.php', '/storage/logs/laravel.log']) {
      const result = await fetchFrom(page, path)

      // The denials run BEFORE the maintenance arm, so these stay 403 rather than becoming a 503
      // page — and, more to the point, an outage never becomes a window in which they are served.
      expect(result.status, path).toBe(403)
      expect(result.body, path).not.toContain('APP_KEY')
    }
  })

  test('dresses the maintenance response in the full security policy', async ({ page }) => {
    // The responder loads no framework and sets no policy of its own: these come from the web server,
    // which is what ADR 0026 says covers the whole origin.
    await page.goto(PROD)
    await expectPolicyHeaders(page, '/people/123')
  })

  test('restores the whole surface the moment the flag is removed', async ({ page }) => {
    rmSync(FLAG, { force: true })
    await page.goto(PROD)

    const root = await fetchFrom(page, '/')
    const api = await fetchFrom(page, '/api/v1/health')
    const up = await fetchFrom(page, '/up')

    expect(root.status).toBe(200)
    expect(isConsoleShell(root.body)).toBe(true)
    expect(api.status).toBe(200)
    expect(up.status).toBe(200)
  })
})
