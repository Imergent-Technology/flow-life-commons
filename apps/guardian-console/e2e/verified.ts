import { expect, test, type Browser, type Page } from '@playwright/test'

import { inTheme, type Theme } from './axe.ts'
import { apiFrom, nextCode, SESSION_COOKIE, signedInAs, type FixtureSession } from './support.ts'

// A session of the WORKER's own, for work that needs a recent proof (ADR 0023): deleting a Pack, recognising a Guardian, removing a
// relationship. Nothing here is a test.
//
// Why a session of its own. Proving (`POST /security/verify`) rotates the session id and destroys the old one, so proving on a session
// another journey holds (the minted `plain-guardian` or `relationships-admin`, which many journeys in other workers share) signs every
// one of those journeys out mid-step. And the platform accepts each authenticator time step once per Account, so two workers proving
// as one Account in the same 30 seconds refuse each other. Each worker therefore signs in, for real (password and authenticator, the
// way the Console does), as its own administrator (E2eAccountSeeder::WORKERS), and nothing else ever holds that session.
//
// A fresh sign-in with a second factor IS a fresh verification, valid for 15 minutes, so the worker signs in again once its session is
// ten minutes old: a journey handed it always has at least five minutes of proof, and no proof is spent (proofs are rate limited per
// address, 20 in 15 minutes, across the whole suite). If the platform still asks (`verification_required`), the session proves once with
// its own authenticator, which rotates only its own id.

/** [email, password, TOTP secret] of each worker's administrator, as E2eAccountSeeder::WORKERS seeds them. */
const WORKERS: readonly (readonly [string, string, string])[] = [
  [
    'e2e.worker.0@example.org',
    'e2e-worker-0-password-not-a-secret',
    'GCWIAIBLAWGEZ4LAJ27G6J2WZ347EVSG',
  ],
  [
    'e2e.worker.1@example.org',
    'e2e-worker-1-password-not-a-secret',
    'XI2IBMBBLREHJQPRAHCS3O3PL5PWFZA2',
  ],
  [
    'e2e.worker.2@example.org',
    'e2e-worker-2-password-not-a-secret',
    'D4QB7BJ5OJRSIIBVU5NV3S5YQLKLNT3U',
  ],
  [
    'e2e.worker.3@example.org',
    'e2e-worker-3-password-not-a-secret',
    'RR2BYXPVIGKOWSYP2C63UWYLNE5W4EF7',
  ],
  [
    'e2e.worker.4@example.org',
    'e2e-worker-4-password-not-a-secret',
    'ZL5ILBG33EMLMBW3TFY4RZQXIRZYZNK4',
  ],
  [
    'e2e.worker.5@example.org',
    'e2e-worker-5-password-not-a-secret',
    'F44R6U5HK3265QFEY5L77EXHPFSRCJMD',
  ],
  [
    'e2e.worker.6@example.org',
    'e2e-worker-6-password-not-a-secret',
    'E3XFIBY43P4WCBKNV5EYPEWXNZV6RNNS',
  ],
  [
    'e2e.worker.7@example.org',
    'e2e-worker-7-password-not-a-secret',
    'XEX6JB2NWWILCXSHEDWUY3JZSPPQUIWR',
  ],
]

/** Who a verified session signs in and proves as. */
export interface Prover {
  email: string
  password: string
  secret: string
}

/** How long a sign-in is relied on before signing in again: five minutes short of the 15-minute proof window. */
const REUSE_MS = 10 * 60_000

/** A code the platform refused (a time step this Account spent in the last minute, e.g. by a worker that restarted) is retried with the next step, at most this often. */
const CODE_ATTEMPTS = 3

const isVerificationRequired = (response: { status: number; body: unknown }): boolean =>
  response.status === 403 &&
  typeof response.body === 'object' &&
  response.body !== null &&
  (response.body as { verification_required?: unknown }).verification_required === true

/**
 * One session, held by one worker only, that can be relied on for work needing a recent proof. It is signed in on first use (or, for a
 * probe, starts from a minted fixture session), signs in again when it has aged or the platform has ended it, and proves again when the
 * platform asks. It counts what it did, so a journey can show which path it took.
 */
export class VerifiedSession {
  private readonly browser: Browser
  private readonly baseURL: string
  private readonly prover: Prover
  private readonly start: FixtureSession | undefined
  private page: Page | undefined
  private provedAt = 0
  signIns = 0
  proofs = 0

  /** `start`: begin from this minted session instead of signing in (for a journey that needs a session whose proof has aged). */
  constructor(browser: Browser, baseURL: string, prover: Prover, start?: FixtureSession) {
    this.browser = browser
    this.baseURL = baseURL
    this.prover = prover
    this.start = start
  }

  /** A page at the origin in this session, for same-origin requests. */
  async ready(): Promise<Page> {
    if (this.page === undefined && this.start !== undefined) {
      const page = await signedInAs(this.browser, this.baseURL, this.start)
      await page.goto('/api/v1/health')
      expect((await apiFrom(page, 'GET', '/api/v1/me')).status, 'the minted session works').toBe(
        200,
      )
      this.page = page
      this.provedAt = Number.POSITIVE_INFINITY // its proof's age is unknown here: only the platform's answer says
      return page
    }
    if (this.page === undefined || Date.now() - this.provedAt > REUSE_MS) return this.signIn()
    return this.page
  }

  /**
   * A request in this session. A `401` (the session ended) is followed by signing in again; a `403` that asks for recent verification
   * by proving. Each at most once, and the request is then made again; any other answer is returned as it is, for the caller to judge.
   */
  async request(
    method: string,
    path: string,
    body?: unknown,
  ): Promise<{ status: number; body: unknown }> {
    let page = await this.ready()
    let response = await apiFrom(page, method, path, body)
    if (response.status === 401) {
      page = await this.signIn()
      response = await apiFrom(page, method, path, body)
    }
    if (isVerificationRequired(response)) {
      await this.prove(page)
      response = await apiFrom(page, method, path, body)
    }
    return response
  }

  /** The session cookie's current value (proving or signing in replaces it). */
  async sessionCookie(): Promise<string> {
    const page = await this.ready()
    const cookie = (await page.context().cookies()).find((c) => c.name === SESSION_COOKIE)
    expect(cookie, 'the verified session has a session cookie').toBeDefined()
    return cookie?.value ?? ''
  }

  async close(): Promise<void> {
    await this.page?.context().close()
    this.page = undefined
  }

  /** Signs in for real, in a new browser context: password, then a code from the Account's authenticator. */
  private async signIn(): Promise<Page> {
    await this.page?.context().close()
    const context = await this.browser.newContext({ baseURL: this.baseURL })
    const page = await context.newPage()
    await page.goto('/api/v1/health')
    // The first request gets a session and a request-forgery token, as the Console's does.
    expect((await apiFrom(page, 'GET', '/api/v1/me')).status).toBe(401)
    const login = await apiFrom(page, 'POST', '/api/v1/login', {
      email: this.prover.email,
      password: this.prover.password,
    })
    expect(login.status, `sign in as ${this.prover.email}: ${JSON.stringify(login.body)}`).toBe(202)
    await this.withCode(
      (code) => apiFrom(page, 'POST', '/api/v1/mfa/challenge', { code }),
      200,
      'the second factor',
    )
    this.page = page
    this.provedAt = Date.now()
    this.signIns++
    return page
  }

  /** Proves again on this session (rotating only its own id). */
  private async prove(page: Page): Promise<void> {
    await this.withCode(
      (code) =>
        apiFrom(page, 'POST', '/api/v1/security/verify', {
          current_password: this.prover.password,
          code,
        }),
      204,
      'recent verification',
    )
    this.provedAt = Date.now()
    this.proofs++
  }

  private async withCode(
    send: (code: string) => Promise<{ status: number; body: unknown }>,
    expected: number,
    what: string,
  ): Promise<void> {
    let last: { status: number; body: unknown } | undefined
    for (let attempt = 0; attempt < CODE_ATTEMPTS; attempt++) {
      last = await send(await nextCode(this.prover.secret))
      if (last.status === expected) return
      if (last.status !== 422) break // only a refused code is worth the next time step
    }
    expect(last?.status, `${what} for ${this.prover.email}: ${JSON.stringify(last?.body)}`).toBe(
      expected,
    )
  }
}

let mine: VerifiedSession | undefined

/** This worker's administrator. Each worker has its own, so no two ever share a session or an authenticator time step. */
export function workerProver(): Prover {
  const index = test.info().parallelIndex
  const worker = WORKERS[index]
  if (worker === undefined)
    throw new Error(
      `Worker ${String(index)} has no administrator of its own: E2eAccountSeeder seeds ${String(WORKERS.length)}. Run with fewer workers.`,
    )
  return { email: worker[0], password: worker[1], secret: worker[2] }
}

/** This worker's own verified session, kept for the worker's life (the browser it lives in is the worker's). */
export function workerSession(browser: Browser, baseURL?: string): VerifiedSession {
  mine ??= new VerifiedSession(
    browser,
    baseURL ?? test.info().project.use.baseURL ?? '',
    workerProver(),
  )
  return mine
}

/**
 * A NEW page, in a context of its own, in this worker's verified session (an administrator, freshly proved), for a journey that drives
 * screens needing a recent proof. The journey may close it; the session is the worker's and outlives it.
 */
export async function verifiedAdminPage(
  browser: Browser,
  baseURL: string | undefined,
  theme?: Theme,
): Promise<Page> {
  const session = workerSession(browser, baseURL)
  const value = await session.sessionCookie()
  const origin = baseURL ?? test.info().project.use.baseURL ?? ''
  const context = await browser.newContext({ baseURL: origin })
  await context.addCookies([
    {
      name: SESSION_COOKIE,
      value,
      url: `https://${new URL(origin).host}`,
      secure: true,
      httpOnly: true,
      sameSite: 'Lax',
    },
  ])
  const page = await context.newPage()
  if (theme !== undefined) await inTheme(page, theme)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  return page
}
