import { expect, test, type Browser, type Page } from '@playwright/test'

import {
  captureConsole,
  clipboardText,
  locationOf,
  mailpit,
  messageIdsTo,
  meStatus,
  nextCode,
  replayedStatus,
  scriptVisibleCookies,
  sessionCookie,
  storageSizes,
  unique,
} from './support.ts'

// The Guardian Console's own screens, driven in real Chromium through the real same-origin gateway
// (ADR 0016): sign-in, the Console boundary, forbidden access, sign-out, invitation acceptance, forgotten
// password with real Mailpit mail, and changing a password. Everything is done by clicking and typing in the
// pages a person would use; raw API calls appear only to MEASURE what the platform now holds (is this
// session alive? does a copied cookie still work?).
//
// Self-contained: the platform runs the no-op breached-password checker here, so nothing reaches a public
// service (`./flow test e2e` refuses to run otherwise). The accounts are development fixtures seeded by
// apps/platform/database/seeders/E2eAccountSeeder.php; their names, passwords and tokens are public.
//
// One address makes every sign-in, and the platform counts each attempt, refused or not, against a per-address
// limit (identity.login_throttle; 30 per 15 minutes by default). The development stack raises it (.env.example:
// IDENTITY_LOGIN_MAX_ATTEMPTS_PER_IP) and `./flow test e2e` refuses to run without that, and clears the counters
// first. A whole run makes about 40 sign-in attempts. A Console user's second step (a code) is a separate per-address limit
// (identity.credential_throttle.mfa_challenge, 30 by default), raised the same way (IDENTITY_MFA_MAX_PER_IP): a full run
// used 29 of the 30, which left no room for another journey.

// Console users are ENROLLED fixtures with a known authenticator secret (ADR 0023), so signing in is two steps.
// One secret per test that signs in with it: the platform accepts each time step once, and parallel workers
// sharing a secret would race for it.
const GUARDIAN = {
  email: 'e2e.console.a@example.org',
  password: 'e2e-console-a-password-not-a-secret',
  secret: 'MJQXGZJTGIYTCMRSGA4DGNZUGEZDMOBQ',
  name: 'E2E Console A',
}
const SECOND = {
  email: 'e2e.console.b@example.org',
  password: 'e2e-console-b-password-not-a-secret',
  secret: 'NRSWC43FONSXEZLSMFZGK43FNVSXG5DP',
}
const NO_ACCESS = {
  email: 'e2e.noaccess@example.org',
  password: 'e2e-noaccess-password-not-a-secret',
}
const INVITEE = {
  email: 'e2e.ui.invitee@example.org',
  token: 'e2e-ui-invitation-token-not-a-secret-000000',
}
const LINK_INVITEE = {
  email: 'e2e.ui.linkinvitee@example.org',
  token: 'e2e-ui-link-invitation-token-not-a-secret-0',
}
const RECOVERY = {
  email: 'e2e.ui.recovery@example.org',
  password: 'e2e-ui-recovery-password-not-a-secret',
  secret: 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U',
}
const CHANGER = {
  email: 'e2e.ui.change@example.org',
  password: 'e2e-ui-change-password-not-a-secret',
  secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
}

const consoleHeading = (page: Page) =>
  page.getByRole('heading', { level: 1, name: 'Flow Life Guardian Console' })
const loginHeading = (page: Page) => page.getByRole('heading', { level: 1, name: 'Sign in' })
const passwordField = (page: Page) => page.getByLabel('Password', { exact: true })

async function fillLogin(page: Page, email: string, password: string) {
  await page.getByLabel('Email address').fill(email)
  await passwordField(page).fill(password)
}

/** The second step: the password was right, and a code from the authenticator finishes the sign-in. */
async function enterCode(page: Page, secret: string) {
  await expect(page.getByRole('heading', { level: 1, name: 'Enter your code' })).toBeVisible()
  await page.getByLabel('Authentication code').fill(await nextCode(secret))
  await page.getByRole('button', { name: 'Sign in' }).click()
}

/**
 * Opens the login page and signs in the way a person does, with the mouse. A Console user (`secret` given) also
 * enters a code from their authenticator; an account whose access needs no second factor is in after the password.
 */
async function signInThroughUi(page: Page, email: string, password: string, secret?: string) {
  await page.goto('/login')
  await fillLogin(page, email, password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  if (secret !== undefined) await enterCode(page, secret)
}

async function freshPage(browser: Browser, baseURL: string): Promise<Page> {
  const context = await browser.newContext({ baseURL })
  return context.newPage()
}

/** The session's authentication instant, as the platform reports it. */
async function authenticatedAt(page: Page): Promise<string> {
  return page.evaluate(async () => {
    const response = await fetch('/api/v1/me')
    return ((await response.json()) as { session: { authenticated_at: string } }).session
      .authenticated_at
  })
}

test.describe('signing in and out of the Console', () => {
  test('an unauthenticated visitor is sent to sign in, and sign-in works by keyboard alone, returns them to where they were headed, survives a refresh, and signing out ends it for good', async ({
    page,
    context,
    request,
    baseURL,
  }) => {
    const url = baseURL ?? ''
    const logged = captureConsole(page)

    // Not signed in: a deep link lands on the login page, not on a blank or broken Console.
    await page.goto('/account/security')
    await expect(loginHeading(page)).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/login')
    expect(await meStatus(page)).toBe(401)

    // Keyboard only. The heading takes focus when a page appears, so Tab reaches the email field next.
    await expect(loginHeading(page)).toBeFocused()
    await page.keyboard.press('Tab')
    await expect(page.getByLabel('Email address')).toBeFocused()
    await page.keyboard.type(GUARDIAN.email)
    await page.keyboard.press('Tab')
    await expect(passwordField(page)).toBeFocused()
    await page.keyboard.type(GUARDIAN.password)
    await page.keyboard.press('Enter')

    // The password alone is NOT a sign-in for a Console user: the second step, and still nobody signed in.
    await expect(page.getByRole('heading', { level: 1, name: 'Enter your code' })).toBeVisible()
    expect(await meStatus(page)).toBe(401)
    await expect(page.getByRole('navigation', { name: 'Console' })).toHaveCount(0)
    await expect(page.locator('body')).not.toContainText(GUARDIAN.password)
    await expect(page.getByRole('heading', { level: 1, name: 'Enter your code' })).toBeFocused()
    await page.keyboard.press('Tab')
    await expect(page.getByLabel('Authentication code')).toBeFocused()
    await page.keyboard.type(await nextCode(GUARDIAN.secret))
    await page.keyboard.press('Enter')

    // In, and back at the page they were headed for.
    await expect(page.getByRole('heading', { level: 1, name: 'Account security' })).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/account/security')
    await expect(page.getByText(GUARDIAN.name, { exact: true })).toBeVisible()

    // The session is the HttpOnly cookie: script cannot see it, and the Console keeps nothing of its own.
    const session = await sessionCookie(context)
    expect(session).toMatchObject({ httpOnly: true, secure: true, sameSite: 'Lax' })
    expect(await scriptVisibleCookies(page)).not.toContain('flowlife-session')
    expect(await storageSizes(page)).toEqual({ local: 0, session: 0 })

    // A refresh keeps the session: the Console re-asks the platform, it does not remember.
    await page.reload()
    await expect(page.getByRole('heading', { level: 1, name: 'Account security' })).toBeVisible()

    // The signed-in landing page, and the API health it shows, over the same origin.
    await page.getByRole('link', { name: 'Home' }).click()
    await expect(consoleHeading(page)).toBeVisible()
    await expect(page.getByText('API ok')).toBeVisible()
    await expect(page.getByText('database: ok')).toBeVisible()
    await expect(page.getByText('Session started')).toBeVisible()

    // Sign out.
    await page.getByRole('button', { name: 'Sign out' }).click()
    await expect(loginHeading(page)).toBeVisible()
    await expect(page.getByText('You have been signed out.')).toBeVisible()
    expect(await meStatus(page)).toBe(401)
    await expect(page.getByText(GUARDIAN.name, { exact: true })).toHaveCount(0)

    // The back button does not bring the Console back: what it held is gone.
    await page.goBack()
    await expect(loginHeading(page)).toBeVisible()
    await expect(page.getByText(GUARDIAN.name, { exact: true })).toHaveCount(0)

    // And the copied cookie from before is worthless: the session behind it was destroyed.
    expect(await replayedStatus(request, url, session?.value ?? '')).toBe(401)

    // No secret was ever written to the browser console.
    expect(logged.join('\n')).not.toContain(GUARDIAN.password)
  })

  test('refuses a wrong password and an unknown address with the same one sentence', async ({
    page,
  }) => {
    await page.goto('/login')

    await fillLogin(page, GUARDIAN.email, 'not the password at all')
    await page.getByRole('button', { name: 'Sign in' }).click()
    const wrong = await page.getByRole('alert').textContent()
    await expect(page.getByRole('alert')).toBeFocused()

    await fillLogin(page, 'nobody@example.org', 'not the password at all')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByRole('alert')).toHaveText(wrong ?? '')

    expect(wrong).toBe('The email address or password is incorrect.')
    expect(new URL(page.url()).pathname).toBe('/login')
    expect(page.url()).not.toContain('password')
    // The refused password is not kept.
    await expect(passwordField(page)).toHaveValue('')
  })

  test('an ended session takes the person back to sign in, and says so', async ({
    page,
    context,
  }) => {
    await signInThroughUi(page, SECOND.email, SECOND.password, SECOND.secret)
    await page.getByRole('link', { name: 'Account security' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Account security' })).toBeVisible()

    // The platform no longer knows this session (as after 30 idle minutes or 12 hours): remove the cookie.
    await context.clearCookies({ name: '__Host-flowlife-session' })

    await page.getByLabel('Current password').fill('whatever it was')
    await page.getByLabel('New password', { exact: true }).fill(unique('nope'))
    await page.getByLabel('Confirm new password').fill('does not matter now')
    await page.getByRole('button', { name: 'Change password' }).click()

    await expect(loginHeading(page)).toBeVisible()
    await expect(page.getByText('Your session has ended. Sign in again to continue.')).toBeVisible()
    await expect(page.getByText('E2E Console B', { exact: true })).toHaveCount(0)
  })

  test('someone signed in WITHOUT Console access is told so, not shown the login page again', async ({
    page,
  }) => {
    await signInThroughUi(page, NO_ACCESS.email, NO_ACCESS.password)

    await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/') // 403, not a redirect back to login
    expect(await meStatus(page)).toBe(200) // authenticated all the same
    await expect(page.getByRole('navigation', { name: 'Console' })).toHaveCount(0)
    // It reveals nothing about what would grant access.
    await expect(page.locator('body')).not.toContainText(
      /role|capabilit|console\.access|administrator/i,
    )

    // A Console path is denied the same way.
    await page.goto('/account/security')
    await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()

    await page.getByRole('button', { name: 'Sign out' }).click()
    await expect(loginHeading(page)).toBeVisible()
    expect(await meStatus(page)).toBe(401)
  })
})

test.describe('accepting an invitation', () => {
  test('with the token typed in: no session is created, and the first sign-in enrols an authenticator before the Console', async ({
    page,
    context,
  }) => {
    const password = unique('invitee')
    const logged = captureConsole(page)
    await context.grantPermissions(['clipboard-read', 'clipboard-write']) // to READ back what "Copy setup key" wrote
    await page.goto('/accept-invitation')

    await page.getByLabel('Invitation token').fill(INVITEE.token)
    await page.getByLabel('New password', { exact: true }).fill('too short')
    await page.getByLabel('Confirm new password').fill('too short')
    await page.getByRole('button', { name: 'Set password and activate' }).click()
    // Refused with a reason, before anything is spent.
    await expect(page.getByLabel('New password', { exact: true })).toHaveAccessibleDescription(
      /at least 15/,
    )
    await expect(page.getByLabel('New password', { exact: true })).toBeFocused()

    await page.getByLabel('New password', { exact: true }).fill(password)
    await page.getByLabel('Confirm new password').fill(password)
    await page.getByRole('button', { name: 'Set password and activate' }).click()

    await expect(page.getByRole('heading', { level: 1, name: 'Invitation accepted' })).toBeVisible()
    await expect(page.getByText('You are not signed in yet')).toBeVisible()
    await expect(page.locator('body')).not.toContainText(/verified/i)
    // No automatic session.
    expect(await meStatus(page)).toBe(401)

    await page.getByRole('link', { name: 'Continue to sign in' }).click()
    await fillLogin(page, INVITEE.email, password)
    await page.getByRole('button', { name: 'Sign in' }).click()

    // A Console user's password is not enough: enrolment is required, and there is still no session.
    await expect(
      page.getByRole('heading', { level: 1, name: 'Set up two-step verification' }),
    ).toBeVisible()
    expect(await meStatus(page)).toBe(401)
    await expect(consoleHeading(page)).toHaveCount(0)
    const issuing = page.waitForResponse(
      (r) =>
        r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/v1/mfa/enrollment',
    )
    await page.getByRole('button', { name: 'Set up authenticator' }).click()
    const issued = (await (await issuing).json()) as { secret: string }

    // The QR code is drawn in the page, and the same secret is offered as a manual key.
    await expect(
      page.getByRole('img', { name: 'QR code for your authenticator app' }),
    ).toBeVisible()
    const secret = ((await page.locator('code').first().textContent()) ?? '').replace(/\s/g, '')
    expect(secret).toMatch(/^[A-Z2-7]{32}$/)
    expect(secret).toBe(issued.secret) // the key on the page is the one the server issued
    await expect(page.getByText(/This setup key expires at/)).toBeVisible()

    // Copy setup key: the canonical key (no display spaces) reaches the clipboard, and nothing else happens.
    const seen: string[] = []
    page.on('request', (request) => seen.push(request.url()))
    await page.getByRole('button', { name: 'Copy setup key' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Setup key copied.' })).toBeVisible()
    expect(await clipboardText(page)).toBe(issued.secret)
    expect(seen).toEqual([]) // no request at all, let alone one carrying the key

    // Generating a secret enrolled nothing: a wrong code does not, and leaves the setup to try again. Typed with a
    // space, as an app shows it: the field keeps the six digits.
    await page.getByLabel('Authentication code').fill('000 000')
    await expect(page.getByLabel('Authentication code')).toHaveValue('000000')
    await page.getByRole('button', { name: 'Verify and continue' }).click()
    await expect(page.getByLabel('Authentication code')).toHaveAccessibleDescription(
      /The code is not valid/,
    )
    expect(await meStatus(page)).toBe(401)

    // A valid code from the secret enrols it, and the recovery codes appear ONCE.
    const valid = await nextCode(secret)
    await page.getByLabel('Authentication code').fill(`${valid.slice(0, 3)} ${valid.slice(3)}`)
    await expect(page.getByLabel('Authentication code')).toHaveValue(valid)
    await page.getByRole('button', { name: 'Verify and continue' }).click()
    const list = page.getByRole('list', { name: 'Recovery codes' })
    await expect(list).toBeVisible()
    const codes = await list.getByRole('listitem').allTextContents()
    expect(codes).toHaveLength(10)
    await expect(page.getByText('They will not be shown again')).toBeVisible()
    // The secret and QR code are gone from the page; only the codes are on it.
    await expect(page.getByRole('img', { name: 'QR code for your authenticator app' })).toHaveCount(
      0,
    )
    await expect(page.locator('body')).not.toContainText(secret)

    // The Console does not open until the person says the codes are saved.
    const proceed = page.getByRole('button', { name: 'Continue to the Console' })
    await expect(proceed).toBeDisabled()
    await page.getByLabel('I have saved these recovery codes somewhere safe.').check()
    await proceed.click()
    await expect(consoleHeading(page)).toBeVisible()
    await expect(page.getByText('E2E UI Invitee', { exact: true }).first()).toBeVisible()
    expect(await meStatus(page)).toBe(200)

    // Shown once: a reload brings back the Console, not the codes; and Account security only counts them.
    await page.reload()
    await expect(consoleHeading(page)).toBeVisible()
    await expect(page.locator('body')).not.toContainText(codes[0] ?? 'x')
    await page.getByRole('link', { name: 'Account security' }).click()
    await expect(
      page.getByText('Recovery codes left').locator('xpath=following-sibling::dd[1]'),
    ).toHaveText('10')
    await expect(page.locator('body')).not.toContainText(secret)

    // The session is the HttpOnly cookie, and none of it (secret, codes, password) touched storage or the console.
    expect((await sessionCookie(context))?.httpOnly).toBe(true)
    expect(await storageSizes(page)).toEqual({ local: 0, session: 0 })
    const output = logged.join('\n')
    for (const forbidden of [secret, password, ...codes]) expect(output).not.toContain(forbidden)
  })

  test('with a link that carries the token: the fragment is scrubbed and the token never shown', async ({
    page,
  }) => {
    const password = unique('linked invitee')
    const logged = captureConsole(page)

    await page.goto(`/accept-invitation#token=${LINK_INVITEE.token}`)
    await expect(page.getByText('Your invitation link was recognised.')).toBeVisible()
    await expect(page.getByLabel('Invitation token')).toHaveCount(0)

    // Gone from the address bar and from history, yet the page still has it.
    await expect(page).toHaveURL(/\/accept-invitation$/)
    const scrubbed = await locationOf(page)
    expect(scrubbed.hash).toBe('')
    expect(scrubbed.historyLength).toBe(2) // about:blank, and this one: nothing added

    await page.getByLabel('New password', { exact: true }).fill(password)
    await page.getByLabel('Confirm new password').fill(password)
    await page.getByRole('button', { name: 'Set password and activate' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Invitation accepted' })).toBeVisible()

    await page.getByRole('link', { name: 'Continue to sign in' }).click()
    await fillLogin(page, LINK_INVITEE.email, password)
    await page.getByRole('button', { name: 'Sign in' }).click()
    // The account holds Console access, so its first sign-in is an enrolment, not the Console.
    await expect(
      page.getByRole('heading', { level: 1, name: 'Set up two-step verification' }),
    ).toBeVisible()
    await expect(consoleHeading(page)).toHaveCount(0)

    expect(logged.join('\n')).not.toContain(LINK_INVITEE.token)
    expect(logged.join('\n')).not.toContain(password)
    expect(await storageSizes(page)).toEqual({ local: 0, session: 0 })
  })
})

test.describe('a forgotten password', () => {
  test('is recovered by email through the Console: the link is scrubbed, the reset ends every session, and the old password is dead', async ({
    browser,
    baseURL,
    request,
  }) => {
    test.setTimeout(90_000)
    const url = baseURL ?? ''
    const newPassword = unique('recovered')

    // The owner is signed in somewhere else when they forget nothing, and are then reset.
    const owner = await freshPage(browser, url)
    await signInThroughUi(owner, RECOVERY.email, RECOVERY.password, RECOVERY.secret)
    await expect(consoleHeading(owner)).toBeVisible()

    const before = await messageIdsTo(request, url, RECOVERY.email)
    const beforeUnknown = await messageIdsTo(request, url, 'nobody@example.org')

    // Ask for a link. An address with an account and one without get the same words.
    const anonymous = await freshPage(browser, url)
    const logged = captureConsole(anonymous)
    const asked: string[] = []
    for (const email of [RECOVERY.email, 'nobody@example.org']) {
      await anonymous.goto('/forgot-password')
      await anonymous.getByLabel('Email address').fill(email)
      await anonymous.getByRole('button', { name: 'Send reset link' }).click()
      const message = anonymous.getByText(/If an eligible account exists/)
      await expect(message).toBeVisible()
      asked.push((await message.textContent()) ?? '')
    }
    expect(asked[0]).toBe(asked[1])
    expect(asked[0]).toBe(
      'If an eligible account exists for that address, password reset instructions have been sent.',
    )

    // Exactly one message reaches the owner; none reaches the unknown address.
    let fresh: string[] = []
    await expect
      .poll(
        async () => {
          fresh = (await messageIdsTo(request, url, RECOVERY.email)).filter(
            (id) => !before.includes(id),
          )
          return fresh.length
        },
        { timeout: 20_000 },
      )
      .toBe(1)
    expect(await messageIdsTo(request, url, 'nobody@example.org')).toEqual(beforeUnknown)

    const message = await mailpit(request, url, `/api/v1/message/${fresh[0] ?? ''}`)
    const text = ((await message.json()) as { Text: string }).Text
    const link = /(https?:\/\/\S+\/reset-password#token=[^&\s]+&email=\S+)/.exec(text)?.[1]
    expect(link, 'the message carries a reset link to the Console').toBeDefined()
    const token = decodeURIComponent(/#token=([^&\s]+)/.exec(link ?? '')?.[1] ?? '')

    // Follow the link, as a person does. Viewing it spends nothing.
    const viewer = await freshPage(browser, url)
    await viewer.goto(link ?? '')
    await expect(
      viewer.getByRole('heading', { level: 1, name: 'Choose a new password' }),
    ).toBeVisible()
    await expect(viewer).toHaveURL(`${url}/reset-password`)
    const scrubbed = await locationOf(viewer)
    expect(scrubbed.href).not.toContain(token)
    expect(scrubbed.hash).toBe('')
    expect(scrubbed.historyLength).toBe(2) // about:blank, and this one: nothing added
    // History does not hold the secret either: going back and forward again shows the scrubbed address,
    // and the reloaded page (whose memory is gone) says the link cannot be used rather than reviving it.
    await viewer.goBack()
    await viewer.goForward()
    await expect(viewer).toHaveURL(`${url}/reset-password`)
    await expect(viewer.getByRole('heading', { name: 'Reset link not usable' })).toBeVisible()
    await viewer.context().close()

    // Use it. A refused password does not spend the token.
    await anonymous.goto(link ?? '')
    await anonymous.getByLabel('New password', { exact: true }).fill('too short')
    await anonymous.getByLabel('Confirm new password').fill('too short')
    await anonymous.getByRole('button', { name: 'Set new password' }).click()
    await expect(anonymous.getByLabel('New password', { exact: true })).toHaveAccessibleDescription(
      /at least 15/,
    )

    await anonymous.getByLabel('New password', { exact: true }).fill(newPassword)
    await anonymous.getByLabel('Confirm new password').fill(newPassword)
    await anonymous.getByRole('button', { name: 'Set new password' }).click()
    await expect(
      anonymous.getByRole('heading', { level: 1, name: 'Password changed' }),
    ).toBeVisible()
    // It does not sign anyone in.
    expect(await meStatus(anonymous)).toBe(401)

    // Every session the owner had is over: reloading the tab that was signed in shows the login page.
    await owner.reload()
    await expect(loginHeading(owner)).toBeVisible()
    expect(await meStatus(owner)).toBe(401)

    // The old password no longer works; the new one does.
    await anonymous.getByRole('link', { name: 'Continue to sign in' }).click()
    await fillLogin(anonymous, RECOVERY.email, RECOVERY.password)
    await anonymous.getByRole('button', { name: 'Sign in' }).click()
    await expect(anonymous.getByRole('alert')).toHaveText(
      'The email address or password is incorrect.',
    )
    await fillLogin(anonymous, RECOVERY.email, newPassword)
    await anonymous.getByRole('button', { name: 'Sign in' }).click()
    await enterCode(anonymous, RECOVERY.secret) // a reset does not remove the second factor
    await expect(consoleHeading(anonymous)).toBeVisible()

    // Nothing secret was logged or stored by the Console.
    const output = logged.join('\n')
    for (const secret of [token, newPassword, RECOVERY.password])
      expect(output).not.toContain(secret)
    expect(await storageSizes(anonymous)).toEqual({ local: 0, session: 0 })

    for (const page of [owner, anonymous]) await page.context().close()
  })
})

test.describe('changing a password while signed in', () => {
  test('keeps this session (rotated), refuses a wrong current password, and kills the old password', async ({
    browser,
    baseURL,
    request,
  }) => {
    test.setTimeout(60_000)
    const url = baseURL ?? ''
    const newPassword = unique('changed')
    const page = await freshPage(browser, url)

    await signInThroughUi(page, CHANGER.email, CHANGER.password, CHANGER.secret)
    await expect(consoleHeading(page)).toBeVisible()
    const before = await sessionCookie(page.context())
    const startedBefore = await authenticatedAt(page)
    await page.waitForTimeout(1100) // instants are whole seconds

    await page.getByRole('link', { name: 'Account security' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Account security' })).toBeVisible()

    // A wrong current password changes nothing, and says which field.
    await page.getByLabel('Current password').fill('definitely not the current one')
    await page.getByLabel('New password', { exact: true }).fill(newPassword)
    await page.getByLabel('Confirm new password').fill(newPassword)
    await page.getByRole('button', { name: 'Change password' }).click()
    await expect(page.getByLabel('Current password')).toHaveAccessibleDescription(
      'The current password is incorrect.',
    )
    await expect(page.getByLabel('Current password')).toBeFocused()
    expect(await replayedStatus(request, url, before?.value ?? '')).toBe(200) // untouched
    expect(await authenticatedAt(page)).toBe(startedBefore)

    // The right one changes it, and the person stays signed in.
    await page.getByLabel('Current password').fill(CHANGER.password)
    await page.getByRole('button', { name: 'Change password' }).click()
    await expect(
      page.getByText('Your password has been changed. Other devices have been signed out.'),
    ).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/account/security')
    await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
    expect(await meStatus(page)).toBe(200)

    // The platform rotated the session: re-dated, and the id that was copied before is dead.
    expect(await authenticatedAt(page)).not.toBe(startedBefore)
    expect(await replayedStatus(request, url, before?.value ?? '')).toBe(401)
    // No session identifier is ever put on the page.
    await expect(page.locator('body')).not.toContainText(/flowlife-session|session id/i)
    // What was typed does not linger in the form.
    await expect(page.getByLabel('Current password')).toHaveValue('')
    await expect(page.getByLabel('New password', { exact: true })).toHaveValue('')

    // Sign out. The old password is dead; the new one signs in.
    await page.getByRole('button', { name: 'Sign out' }).click()
    await expect(loginHeading(page)).toBeVisible()
    await fillLogin(page, CHANGER.email, CHANGER.password)
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByRole('alert')).toHaveText('The email address or password is incorrect.')
    await fillLogin(page, CHANGER.email, newPassword)
    await page.getByRole('button', { name: 'Sign in' }).click()
    await enterCode(page, CHANGER.secret)
    await expect(consoleHeading(page)).toBeVisible()

    await page.context().close()
  })
})
