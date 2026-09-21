import { expect, test, type Page } from '@playwright/test'

import { apiFrom, nextCode } from './support.ts'

/**
 * The PRODUCTION browser security policy (ADR 0026), in a real browser, against the real production
 * build.
 *
 * These journeys deliberately do not use `baseURL`. They run against the gateway's
 * production-equivalent site (`prod.flowlife.localhost`), which serves the Console's actual Vite build
 * as static files beside the API under the exact headers `config/security.php` produces — the same
 * arrangement Apache will serve in production. The development origin is not that: it runs Vite, which
 * needs 'unsafe-inline' and 'unsafe-eval', and proving anything there would prove nothing about
 * production.
 *
 * Two things are being shown, and both matter:
 *
 * 1. the strict policy does not break anything the Console does; and
 * 2. it actually BLOCKS. A header that is present but not enforced looks identical in a snapshot, so
 *    every forbidden case below is a real attempt the browser must refuse.
 *
 * The e2e project compiles without the DOM library (tsconfig.node.json), so browser-side code is typed
 * through the `Dom` shape below, as the rest of the suite is through its own casts.
 */

const PORT = new URL(process.env.E2E_BASE_URL ?? 'http://commons.flowlife.localhost:18080').port
const PROD = `http://prod.flowlife.localhost:${PORT}`
/** A real origin the browser can reach that is NOT 'self': the development mail catcher. */
const OTHER_ORIGIN = `http://mail.flowlife.localhost:${PORT}`

const POLICY =
  "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; " +
  "connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"

/*
 * Two administrators, not one. Playwright runs these journeys in parallel worker PROCESSES, and the
 * platform accepts each authenticator time step once, so two journeys sharing a secret would race for a
 * code and one would be refused as a replay.
 */
const ADMIN = {
  email: 'e2e.security.admin@example.org',
  password: 'e2e-security-admin-password-not-a-secret',
  secret: 'PB2XQZ3EMF2GK43UNBSWY3DPFQQHO33S',
}

const CODES_ADMIN = {
  email: 'e2e.security.codes@example.org',
  password: 'e2e-security-codes-password-not-a-secret',
  secret: 'GIYDCMBSGE3TQMRSGE3DAOBSGIYDCMBS',
}

interface Element {
  src: string
  textContent: string
  tagName: string
  contentDocument: { getElementById: (id: string) => unknown } | null
  addEventListener: (type: string, listener: () => void) => void
}

interface Dom {
  document: {
    createElement: (tag: string) => Element
    head: { append: (node: Element) => void }
    body: { append: (node: Element) => void }
  }
  Image: new () => Element
  location: { hash: string }
  setTimeout: (fn: () => void, ms: number) => void
  __cspViolations: string[]
  __inlineRan?: boolean
}

/*
 * `Dom` is a TYPE only. Everything a page.evaluate callback touches must be reached from INSIDE that
 * callback — Playwright ships the function to the browser, where a helper defined here would not
 * exist — hence the `globalThis as unknown as Dom` cast repeated in each one.
 */

/** Every CSP violation the page reports, so a journey can assert on silence as well as on noise. */
function violations(page: Page): string[] {
  const logged: string[] = []
  void page.addInitScript(() => {
    const g = globalThis as unknown as Dom & {
      addEventListener: (type: string, listener: (event: unknown) => void) => void
    }
    g.__cspViolations = []
    g.addEventListener('securitypolicyviolation', (event: unknown) => {
      const e = event as { violatedDirective?: string; blockedURI?: string }
      g.__cspViolations.push(`${e.violatedDirective ?? '?'} ${e.blockedURI ?? '?'}`)
    })
  })
  page.on('console', (message) => {
    if (/Content Security Policy|Refused to/i.test(message.text())) logged.push(message.text())
  })
  return logged
}

const reported = (page: Page): Promise<string[]> =>
  page.evaluate(() => (globalThis as unknown as Dom).__cspViolations)

interface Operator {
  email: string
  password: string
  secret: string
}

async function signIn(page: Page, who: Operator): Promise<void> {
  await page.goto(`${PROD}/login`)
  await page.getByLabel('Email address').fill(who.email)
  await page.getByLabel('Password', { exact: true }).fill(who.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Enter your code' })).toBeVisible()
  await page.getByLabel('Authentication code').fill(await nextCode(who.secret))
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(
    page.getByRole('heading', { level: 1, name: 'Flow Life Guardian Console' }),
  ).toBeVisible()
}

/**
 * The response headers for a path on the production-equivalent origin.
 *
 * Deliberately a NAVIGATION rather than Playwright's Node-side `request` fixture: Node cannot resolve
 * *.localhost, browsers can (the rest of the suite works around the same thing).
 */
async function headersFor(page: Page, url: string): Promise<Record<string, string>> {
  const response = await page.goto(url)
  return response?.headers() ?? {}
}

test.describe('the production security policy', () => {
  test('sends exactly the headers the policy declares, on the Console shell and on the API', async ({
    page,
  }) => {
    const headers = await headersFor(page, `${PROD}/login`)

    // The Console's HTML is a static file: in production Apache serves it and Laravel never sees it,
    // so this is the half a Laravel-only policy would have missed entirely.
    expect(headers['content-security-policy']).toBe(POLICY)
    expect(headers['x-content-type-options']).toBe('nosniff')
    expect(headers['x-frame-options']).toBe('DENY')
    expect(headers['referrer-policy']).toBe('same-origin')
    expect(headers['cross-origin-opener-policy']).toBe('same-origin')
    expect(headers['cross-origin-resource-policy']).toBe('same-origin')
    expect(headers['permissions-policy']).toContain('camera=()')

    // And the API half, which Laravel does serve, must agree rather than have a policy of its own.
    const api = await headersFor(page, `${PROD}/api/v1/health`)
    expect(api['content-security-policy']).toBe(POLICY)
    expect(api['cache-control']).toContain('no-store')

    // Plain HTTP here, so HSTS must be absent: a development gateway claiming a year of HTTPS would be
    // describing a deployment it does not have. Production sends it, conditional on HTTPS.
    expect(headers['strict-transport-security']).toBeUndefined()
  })

  test('does not announce the server software', async ({ page }) => {
    // X-Powered-By tells an attacker which PHP patch level to look up and a user nothing. Asserted
    // here rather than in the PHP suite, where the CLI never emits it and the assertion would pass
    // whatever the web server was configured to do.
    const api = await headersFor(page, `${PROD}/api/v1/health`)

    expect(api['x-powered-by']).toBeUndefined()
  })

  test('is not the policy the development Vite origin uses, and is stricter', async ({ page }) => {
    // Stated as a journey so nobody "fixes" the development gateway by giving it the production policy
    // and then loosens production when HMR breaks. Vite needs inline scripts and eval; the production
    // build needs neither. What the development origin must NOT give up is framing and base-uri.
    const development = await headersFor(page, `http://commons.flowlife.localhost:${PORT}/login`)
    const devPolicy = development['content-security-policy'] ?? ''

    expect(devPolicy).toContain('unsafe-inline') // a positive control: this IS the dev-server policy
    expect(devPolicy).toContain("frame-ancestors 'none'")
    expect(devPolicy).toContain("base-uri 'none'")

    const production = await headersFor(page, `${PROD}/login`)
    expect(production['content-security-policy']).not.toContain('unsafe-inline')
    expect(production['content-security-policy']).not.toContain('unsafe-eval')
  })

  test('starts, styles and runs the Console with no policy violation at all', async ({ page }) => {
    const logged = violations(page)
    await page.goto(`${PROD}/login`)

    // The module script ran (React rendered) and the stylesheet applied (a Tailwind utility is in
    // effect). Either being blocked would leave a blank or unstyled page, so this is what proves
    // `script-src 'self'` and `style-src 'self'` are wide enough for the real build.
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
    const weight = await page
      .getByRole('heading', { level: 1 })
      .evaluate(
        (node) =>
          (
            globalThis as unknown as { getComputedStyle: (n: unknown) => { fontWeight: string } }
          ).getComputedStyle(node).fontWeight,
      )
    expect(Number(weight)).toBeGreaterThanOrEqual(600)

    expect(logged).toEqual([])
    expect(await reported(page)).toEqual([])
  })

  test('blocks a script from another origin, an inline script, and eval', async ({ page }) => {
    // The probe. Without it, every assertion above would pass just as well against a header the
    // browser was ignoring.
    await page.goto(`${PROD}/login`)

    const external = await page.evaluate(async (src) => {
      const d = globalThis as unknown as Dom
      const element = d.document.createElement('script')
      element.src = `${src}/not-allowed.js`
      const outcome = new Promise<string>((resolve) => {
        element.addEventListener('load', () => {
          resolve('loaded')
        })
        element.addEventListener('error', () => {
          resolve('blocked')
        })
      })
      d.document.head.append(element)
      return outcome
    }, OTHER_ORIGIN)
    expect(external).toBe('blocked')

    const inline = await page.evaluate(() => {
      const d = globalThis as unknown as Dom
      const element = d.document.createElement('script')
      element.textContent = 'globalThis.__inlineRan = true'
      d.document.head.append(element)
      return d.__inlineRan === true
    })
    expect(inline).toBe(false)

    expect((await reported(page)).some((r) => r.startsWith('script-src'))).toBe(true)

    // `eval` is deliberately NOT probed here, and the reason is worth recording rather than leaving as
    // a gap. Playwright runs page.evaluate code through the debugging protocol, which is not subject to
    // the page's CSP, so an `eval` called from a test would succeed whatever the policy says — the
    // probe would prove nothing and would look like it proved something. What DOES cover it: the policy
    // carries no 'unsafe-eval' (asserted from the live header above and from config in the PHP suite),
    // and the inline-script case above shows the same directive really is enforced on this page.
  })

  test('blocks an image and a fetch from another origin', async ({ page }) => {
    await page.goto(`${PROD}/login`)

    const image = await page.evaluate(async (src) => {
      const d = globalThis as unknown as Dom
      const element = new d.Image()
      const outcome = new Promise<string>((resolve) => {
        element.addEventListener('load', () => {
          resolve('loaded')
        })
        element.addEventListener('error', () => {
          resolve('blocked')
        })
      })
      element.src = `${src}/favicon.ico`
      return outcome
    }, OTHER_ORIGIN)
    expect(image).toBe('blocked')

    // connect-src 'self': the Console could not send anything to another origin even if something in
    // it tried to. On a page that handles credentials this is the directive that matters most.
    const sent = await page.evaluate(async (src) => {
      try {
        await fetch(`${src}/`, { mode: 'no-cors' })
        return 'sent'
      } catch {
        return 'blocked'
      }
    }, OTHER_ORIGIN)
    expect(sent).toBe('blocked')
  })

  test('refuses to be framed', async ({ page }) => {
    await page.goto(`${PROD}/login`)

    const framed = await page.evaluate(async (target) => {
      const d = globalThis as unknown as Dom
      const frame = d.document.createElement('iframe')
      frame.src = target
      d.document.body.append(frame)
      await new Promise((resolve) => {
        d.setTimeout(() => {
          resolve(null)
        }, 1500)
      })
      try {
        // A frame that had really loaded the Console would be same-origin and readable.
        return frame.contentDocument?.getElementById('root') == null ? 'empty' : 'framed'
      } catch {
        return 'blocked'
      }
    }, `${PROD}/login`)

    expect(framed).not.toBe('framed')
  })

  test('signs in, administers accounts and shows a QR code', async ({ page }) => {
    // The signed-in surfaces, item by item, under the enforced policy.
    const logged = violations(page)
    await signIn(page, ADMIN)

    // API calls from the page (connect-src 'self'), with the request-forgery token the Console echoes.
    expect((await apiFrom(page, 'GET', '/api/v1/me')).status).toBe(200)

    // Account administration: the list and a detail page.
    await page.goto(`${PROD}/admin/accounts`)
    await expect(page.getByRole('heading', { level: 1, name: 'Accounts' })).toBeVisible()
    await page.getByRole('link', { name: 'E2E Security Admin' }).first().click()
    await expect(page.getByRole('heading', { level: 1, name: 'E2E Security Admin' })).toBeVisible()

    // The QR code is drawn in the browser as inline SVG from the module matrix. It is the one place a
    // naive implementation would have reached for a QR *service*, which img-src and connect-src would
    // refuse; this shows the local one renders under the policy.
    await page.goto(`${PROD}/account/security`)
    await page.getByRole('button', { name: 'Replace authenticator' }).click()
    const replace = page.getByRole('form', { name: 'Replace authenticator' })
    await replace.getByLabel('Current password').fill(ADMIN.password)
    await replace.getByLabel('Authentication code').fill(await nextCode(ADMIN.secret))
    await replace.getByRole('button', { name: 'Continue' }).click()
    const qr = page.getByRole('img', { name: 'QR code for your authenticator app' })
    await expect(qr).toBeVisible()
    const tag = await qr.evaluate((node: { tagName: string }) => node.tagName)
    expect(tag.toLowerCase()).toBe('svg')

    expect(logged).toEqual([])
    expect(await reported(page)).toEqual([])
  })

  test('copies and downloads recovery codes under the policy', async ({ browser }) => {
    // `URL.createObjectURL` plus a clicked <a download>, and a clipboard write. Neither is obviously
    // safe under `default-src 'none'`, which is exactly why it is measured rather than reasoned about.
    const context = await browser.newContext({
      permissions: ['clipboard-read', 'clipboard-write'],
      acceptDownloads: true,
    })
    const page = await context.newPage()
    const logged = violations(page)

    try {
      await signIn(page, CODES_ADMIN)
      await page.goto(`${PROD}/account/security`)

      await page.getByRole('button', { name: 'Generate new recovery codes' }).click()
      const regenerate = page.getByRole('form', { name: 'Generate new recovery codes' })
      await regenerate.getByLabel('Current password').fill(CODES_ADMIN.password)
      await regenerate.getByLabel('Authentication code').fill(await nextCode(CODES_ADMIN.secret))
      await regenerate.getByRole('button', { name: 'Generate codes' }).click()

      const list = page.getByRole('list', { name: 'Recovery codes' })
      await expect(list).toBeVisible()
      expect((await list.getByRole('listitem').allTextContents()).length).toBeGreaterThan(0)

      const download = page.waitForEvent('download')
      await page.getByRole('button', { name: 'Download as a file' }).click()
      expect((await download).suggestedFilename()).toBe('flow-life-recovery-codes.txt')

      await page.getByRole('button', { name: 'Copy codes' }).click()
      await expect(page.getByText('Copied to the clipboard')).toBeVisible()

      expect(logged).toEqual([])
      expect(await reported(page)).toEqual([])
    } finally {
      await context.close()
    }
  })

  test('reads a secret-bearing fragment and scrubs it, under the policy', async ({ page }) => {
    // The reset and invitation links carry their token in the fragment and the Console removes it from
    // the address bar with the router's replace. That is script doing navigation work, so it is worth
    // showing it still happens with the strict policy enforced.
    const logged = violations(page)
    await page.goto(`${PROD}/accept-invitation#token=not-a-real-token-000000000000000000000000000`)

    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    expect(await page.evaluate(() => (globalThis as unknown as Dom).location.hash)).toBe('')
    expect(logged).toEqual([])
  })
})
