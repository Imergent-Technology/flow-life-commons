import { expect, test, type Browser, type Page } from '@playwright/test'

import {
  captureConsole,
  locationOf,
  messageIdsTo,
  mailpit,
  meStatus,
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
// One address makes every sign-in, and the platform allows 30 attempts per address per 15 minutes
// (identity.login_throttle), successful or not. `./flow test e2e` clears the counters first. This file
// makes 13 attempts and the other specs about 12, so keep an eye on the total if you add journeys.

const GUARDIAN = {
  email: 'e2e.guardian@example.org',
  password: 'e2e-fixture-password-not-a-secret',
  name: 'E2E Guardian',
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
}
const CHANGER = {
  email: 'e2e.ui.change@example.org',
  password: 'e2e-ui-change-password-not-a-secret',
}

const consoleHeading = (page: Page) =>
  page.getByRole('heading', { level: 1, name: 'Flow Life Guardian Console' })
const loginHeading = (page: Page) => page.getByRole('heading', { level: 1, name: 'Sign in' })
const passwordField = (page: Page) => page.getByLabel('Password', { exact: true })

async function fillLogin(page: Page, email: string, password: string) {
  await page.getByLabel('Email address').fill(email)
  await passwordField(page).fill(password)
}

/** Opens the login page and signs in the way a person does, with the mouse. */
async function signInThroughUi(page: Page, email: string, password: string) {
  await page.goto('/login')
  await fillLogin(page, email, password)
  await page.getByRole('button', { name: 'Sign in' }).click()
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
    await signInThroughUi(page, GUARDIAN.email, GUARDIAN.password)
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
    await expect(page.getByText(GUARDIAN.name, { exact: true })).toHaveCount(0)
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
  test('with the token typed in: no session is created, and the chosen password then signs in', async ({
    page,
  }) => {
    const password = unique('invitee')
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
    await expect(consoleHeading(page)).toBeVisible()
    await expect(page.getByText('E2E UI Invitee', { exact: true }).first()).toBeVisible()
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
    await expect(consoleHeading(page)).toBeVisible()

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
    await signInThroughUi(owner, RECOVERY.email, RECOVERY.password)
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

    await signInThroughUi(page, CHANGER.email, CHANGER.password)
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
    await expect(consoleHeading(page)).toBeVisible()

    await page.context().close()
  })
})
