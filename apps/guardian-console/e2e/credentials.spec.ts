import { expect, test, type Browser, type Page } from '@playwright/test'

import { mailpit, messageIdsTo, replayedStatus, sessionCookie, unique } from './support.ts'

// The credential lifecycle in real Chromium, through the real gateway, with real Mailpit mail:
// invitation acceptance, sign-in, authenticated password change, and forgotten-password recovery by
// email. It is self-contained: the platform runs with the no-op breached-password checker, so nothing
// here reaches a public service (`./flow test e2e` refuses to run otherwise). What the platform does
// when a password is breached, or the service is down, is proved deterministically in the backend
// tests (BreachCheckEndpointsTest); the real service has a separate manual smoke test (tests/Live).
// This one talks to the API directly (same-origin fetches from the page), because what it measures is the
// platform's behaviour: session rotation, replayed cookies, one-time tokens. The Console's own screens for
// the same lifecycle are exercised, through the UI, in console.spec.ts, against separate accounts. The
// accounts are development fixtures, reset on every run by
// apps/platform/database/seeders/E2eAccountSeeder.php; the token and their names are public.
const INVITEE = 'e2e.invitee@example.org'
const INVITATION_TOKEN = 'e2e-invitation-token-not-a-secret-000000000'
const RECOVERY = 'e2e.recovery@example.org'
const RECOVERY_PASSWORD = 'e2e-recovery-password-not-a-secret'

interface Api {
  status: number
  body: unknown
  headers: Record<string, string>
}

interface Errors {
  message?: string
  errors?: Record<string, string[]>
}

interface Session {
  session: { authenticated_at: string }
  capabilities: string[]
  account: { email: string }
}

async function xsrfToken(page: Page): Promise<string | undefined> {
  const found = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')
  return found === undefined ? undefined : decodeURIComponent(found.value)
}

/** A same-origin fetch from the page, exactly as the Console will make one. */
async function api(page: Page, method: string, path: string, body?: unknown): Promise<Api> {
  const xsrf = await xsrfToken(page)
  return page.evaluate(
    async ({ method, path, body, xsrf }) => {
      const headers: Record<string, string> = { Accept: 'application/json' }
      if (body !== undefined) headers['Content-Type'] = 'application/json'
      if (xsrf !== undefined) headers['X-XSRF-TOKEN'] = xsrf
      const response = await fetch(path, {
        method,
        headers,
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      })
      const text = await response.text()
      return {
        status: response.status,
        body: text === '' ? null : (JSON.parse(text) as unknown),
        headers: Object.fromEntries(response.headers.entries()),
      }
    },
    { method, path, body, xsrf },
  )
}

const errorsOf = (response: Api): Errors => response.body as Errors

// These journeys measure the PLATFORM, so their pages start on Laravel's stateless liveness page, not on the
// Console: the Console asks who is signed in as it loads, which would issue an (anonymous) session cookie
// and blur what "acceptance and reset create no session" is measured against.
async function freshPage(browser: Browser, baseURL: string): Promise<Page> {
  const context = await browser.newContext({ baseURL })
  const page = await context.newPage()
  await page.goto('/up')
  return page
}

async function signIn(page: Page, email: string, password: string): Promise<Api> {
  await api(page, 'GET', '/api/v1/me') // obtains the session and CSRF cookies, as the Console does
  return api(page, 'POST', '/api/v1/login', { email, password })
}

test.describe('the credential lifecycle, end to end', () => {
  // One journey, in order: each step relies on the one before it.
  test.describe.configure({ mode: 'serial' })

  const firstPassword = unique('first')
  const secondPassword = unique('second')
  const thirdPassword = unique('third')

  test('accepts an invitation over the real gateway, and does not sign the invitee in', async ({
    page,
    baseURL,
  }) => {
    await page.goto('/up') // Laravel, not the Console: see freshPage
    const url = baseURL ?? ''
    expect(url).not.toBe('')

    // Refused before anything is spent, and the refusal says why.
    const weak = await api(page, 'POST', '/api/v1/invitations/accept', {
      token: INVITATION_TOKEN,
      password: 'too short',
      password_confirmation: 'too short',
    })
    expect(weak.status).toBe(422)
    expect(errorsOf(weak).errors?.password?.[0]).toContain('at least 15')

    const accepted = await api(page, 'POST', '/api/v1/invitations/accept', {
      token: INVITATION_TOKEN,
      password: firstPassword,
      password_confirmation: firstPassword,
    })
    expect(accepted.status).toBe(204)

    // Not signed in: no session cookie was issued, and there is no session to ask about.
    expect(await sessionCookie(page.context())).toBeUndefined()
    expect((await api(page, 'GET', '/api/v1/me')).status).toBe(401)

    // The token is one-time, and a used token answers exactly as a garbage one does.
    const replay = await api(page, 'POST', '/api/v1/invitations/accept', {
      token: INVITATION_TOKEN,
      password: secondPassword,
      password_confirmation: secondPassword,
    })
    const garbage = await api(page, 'POST', '/api/v1/invitations/accept', {
      token: 'x'.repeat(43),
      password: secondPassword,
      password_confirmation: secondPassword,
    })
    expect(replay.status).toBe(422)
    expect(replay.body).toEqual(garbage.body)
    expect(Object.keys(errorsOf(replay).errors ?? {})).toEqual(['token'])

    // And the chosen password signs in through the ordinary login.
    const login = await signIn(page, INVITEE, firstPassword)
    expect(login.status).toBe(200)
    expect((login.body as Session).account.email).toBe(INVITEE)
    // Not a Console user (that sign-in is two steps, and is measured in mfa.spec.ts), so no capabilities.
    expect((login.body as Session).capabilities).toEqual([])
    await api(page, 'POST', '/api/v1/logout')
  })

  test('changes the password: this session survives, rotated; every other one is ended; the old password is dead', async ({
    browser,
    baseURL,
    request,
  }) => {
    const url = baseURL ?? ''
    const laptop = await freshPage(browser, url)
    const phone = await freshPage(browser, url)
    expect((await signIn(laptop, INVITEE, firstPassword)).status).toBe(200)
    expect((await signIn(phone, INVITEE, firstPassword)).status).toBe(200)

    const before = await sessionCookie(laptop.context())
    const meBefore = await api(laptop, 'GET', '/api/v1/me')
    const authenticatedBefore = (meBefore.body as Session).session.authenticated_at
    await laptop.waitForTimeout(1100) // instants are whole seconds

    // A session alone is not enough, and a wrong current password changes nothing.
    const wrong = await api(laptop, 'POST', '/api/v1/password/change', {
      current_password: 'not the current password',
      password: secondPassword,
      password_confirmation: secondPassword,
    })
    expect(wrong.status).toBe(422)
    expect(Object.keys(errorsOf(wrong).errors ?? {})).toEqual(['current_password'])
    // Nothing about the session moved: the id it had is still the live one, and it was not re-dated.
    expect(await replayedStatus(request, url, before?.value ?? '')).toBe(200)
    expect(
      ((await api(laptop, 'GET', '/api/v1/me')).body as Session).session.authenticated_at,
    ).toBe(authenticatedBefore)

    const changed = await api(laptop, 'POST', '/api/v1/password/change', {
      current_password: firstPassword,
      password: secondPassword,
      password_confirmation: secondPassword,
    })
    expect(changed.status).toBe(204)

    // This session lives on, with a new authentication instant.
    const meAfter = await api(laptop, 'GET', '/api/v1/me')
    expect(meAfter.status).toBe(200)
    expect((meAfter.body as Session).session.authenticated_at).not.toBe(authenticatedBefore)

    // The other browser was signed out by the change.
    expect((await api(phone, 'GET', '/api/v1/me')).status).toBe(401)

    // ...but it is a DIFFERENT session: the id the laptop had before is worthless to whoever copied it.
    expect(await replayedStatus(request, url, before?.value ?? '')).toBe(401)

    // The old password no longer signs in; the new one does.
    const elsewhere = await freshPage(browser, url)
    expect((await signIn(elsewhere, INVITEE, firstPassword)).status).toBe(401)
    expect((await signIn(elsewhere, INVITEE, secondPassword)).status).toBe(200)

    for (const p of [laptop, phone, elsewhere]) await p.context().close()
  })

  test('recovers a forgotten password by email, and the reset ends every session', async ({
    browser,
    baseURL,
    request,
  }) => {
    const url = baseURL ?? ''
    const owner = await freshPage(browser, url)
    expect((await signIn(owner, RECOVERY, RECOVERY_PASSWORD)).status).toBe(200)

    const before = await messageIdsTo(request, url, RECOVERY)
    const beforeUnknown = await messageIdsTo(request, url, 'nobody@example.org')

    // Public and enumeration-safe: the same answer for an account and for an address that has none.
    const anonymous = await freshPage(browser, url)
    const known = await api(anonymous, 'POST', '/api/v1/password/forgot', { email: RECOVERY })
    const unknown = await api(anonymous, 'POST', '/api/v1/password/forgot', {
      email: 'nobody@example.org',
    })
    expect(known.status).toBe(202)
    expect({ status: known.status, body: known.body }).toEqual({
      status: unknown.status,
      body: unknown.body,
    })

    // Exactly one message reaches the account's owner, and none reaches the unknown address.
    let fresh: string[] = []
    await expect
      .poll(
        async () => {
          fresh = (await messageIdsTo(request, url, RECOVERY)).filter((id) => !before.includes(id))
          return fresh.length
        },
        { timeout: 20_000 },
      )
      .toBe(1)
    expect(await messageIdsTo(request, url, 'nobody@example.org')).toEqual(beforeUnknown)

    const message = await mailpit(request, url, `/api/v1/message/${fresh[0] ?? ''}`)
    const text = ((await message.json()) as { Text: string; HTML: string }).Text
    const link = /(https?:\/\/\S+\/reset-password)#token=([^&\s]+)&email=(\S+)/.exec(text)
    expect(link, 'the message carries a reset link').not.toBeNull()
    const token = decodeURIComponent(link?.[2] ?? '')
    expect(decodeURIComponent(link?.[3] ?? '')).toBe(RECOVERY)
    // The secrets are in the fragment: a browser never sends it to a server.
    expect(new URL(link?.[0] ?? '').search).toBe('')
    expect(text).not.toContain('<html')

    // A refused password does not spend the token.
    const weak = await api(anonymous, 'POST', '/api/v1/password/reset', {
      email: RECOVERY,
      token,
      password: 'too short',
      password_confirmation: 'too short',
    })
    expect(weak.status).toBe(422)
    expect(Object.keys(errorsOf(weak).errors ?? {})).toEqual(['password'])

    // The reset completes, does not sign anyone in, and ends the session the owner had.
    const reset = await api(anonymous, 'POST', '/api/v1/password/reset', {
      email: RECOVERY,
      token,
      password: thirdPassword,
      password_confirmation: thirdPassword,
    })
    expect(reset.status).toBe(204)
    expect(await sessionCookie(anonymous.context())).toBeUndefined()
    expect((await api(owner, 'GET', '/api/v1/me')).status).toBe(401)

    // The token is one-time; the old password is dead; the new one signs in.
    const again = await api(anonymous, 'POST', '/api/v1/password/reset', {
      email: RECOVERY,
      token,
      password: unique('replay'),
      password_confirmation: 'x',
    })
    expect(again.status).toBe(422)
    const fresher = await freshPage(browser, url)
    expect((await signIn(fresher, RECOVERY, RECOVERY_PASSWORD)).status).toBe(401)
    expect((await signIn(fresher, RECOVERY, thirdPassword)).status).toBe(200)

    for (const p of [owner, anonymous, fresher]) await p.context().close()
  })
})
