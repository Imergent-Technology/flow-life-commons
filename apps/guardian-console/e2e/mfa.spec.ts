import { expect, test, type Browser, type Page } from '@playwright/test'

import {
  apiFrom,
  captureConsole,
  clipboardText,
  meStatus,
  nextCode,
  recoveryCodesFor,
  sessionCookie,
  storageSizes,
  totpForStep,
} from './support.ts'

// Two-step verification in the Console (ADR 0023), in real Chromium through the real gateway. The accounts are
// ENROLLED development fixtures (apps/platform/database/seeders/E2eAccountSeeder.php) with a KNOWN authenticator
// secret and recovery codes, because a browser test cannot see an authenticator app: the journeys compute the same
// codes an app would (RFC 6238, in support.ts, independent of the platform's own library). Nothing reaches an
// external service. First-time enrolment (the invitation journey) is in console.spec.ts.
//
// Each journey has its OWN account and secret: the platform accepts each authenticator time step once, so
// journeys running in parallel must not share one.

interface Fixture {
  email: string
  password: string
  secret: string
  tag: string
}

const LATER: Fixture = {
  email: 'e2e.mfa.later@example.org',
  password: 'e2e-mfa-later-password-not-a-secret',
  secret: 'KRSXG5CTMVRXEZLUKN2XGZLSMVZG65DI',
  tag: 'D',
}
const RECOVERY: Fixture = {
  email: 'e2e.mfa.recovery@example.org',
  password: 'e2e-mfa-recovery-password-not-a-secret',
  secret: 'ONSWG4TFOQZDCNRTGEZTQMZQGYYDCMZQ',
  tag: 'K',
}
const MANAGE: Fixture = {
  email: 'e2e.mfa.manage@example.org',
  password: 'e2e-mfa-manage-password-not-a-secret',
  secret: 'MZXW6YTBOI4TQOJQGEZDGNBVGY3TQOJQ',
  tag: 'M',
}
const PENDING: Fixture = {
  email: 'e2e.mfa.pending@example.org',
  password: 'e2e-mfa-pending-password-not-a-secret',
  secret: 'NBSWY3DPFQQHO33SNRSCCIBAEBAGCAQA',
  tag: 'P',
}

const consoleHeading = (page: Page) =>
  page.getByRole('heading', { level: 1, name: 'Flow Life Guardian Console' })
const codeStep = (page: Page) => page.getByRole('heading', { level: 1, name: 'Enter your code' })
const loginHeading = (page: Page) => page.getByRole('heading', { level: 1, name: 'Sign in' })

async function freshPage(browser: Browser, baseURL: string): Promise<Page> {
  const context = await browser.newContext({ baseURL })
  return context.newPage()
}

/** The password step only: the platform answers "one more step", and nobody is signed in. */
async function passwordStep(page: Page, who: Fixture) {
  await page.goto('/login')
  await page.getByLabel('Email address').fill(who.email)
  await page.getByLabel('Password', { exact: true }).fill(who.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(codeStep(page)).toBeVisible()
  expect(await meStatus(page)).toBe(401)
}

async function submitCode(page: Page, secret: string) {
  await page.getByLabel('Authentication code').fill(await nextCode(secret))
  await page.getByRole('button', { name: 'Sign in' }).click()
}

async function recoveryLeft(page: Page): Promise<string> {
  return (
    (await page
      .getByText('Recovery codes left')
      .locator('xpath=following-sibling::dd[1]')
      .textContent()) ?? ''
  )
}

test.describe('signing in with an authenticator', () => {
  test('a later sign-in is password, then a code, then the Console', async ({ page, context }) => {
    const logged = captureConsole(page)
    await passwordStep(page, LATER)
    await expect(page.getByRole('navigation', { name: 'Console' })).toHaveCount(0)
    // The password is not kept once it has done its job.
    await expect(page.locator('body')).not.toContainText(LATER.password)

    // A wrong code is one sentence, on the field, and changes nothing.
    await page.getByLabel('Authentication code').fill('000000')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByLabel('Authentication code')).toHaveAccessibleDescription(
      /The code is not valid/,
    )
    await expect(page.getByLabel('Authentication code')).toBeFocused()
    expect(await meStatus(page)).toBe(401)

    // The right one signs in.
    await submitCode(page, LATER.secret)
    await expect(consoleHeading(page)).toBeVisible()
    expect(await meStatus(page)).toBe(200)
    expect((await sessionCookie(context))?.httpOnly).toBe(true)

    // A refresh keeps the session, and nothing about the factor is on the page.
    await page.reload()
    await expect(consoleHeading(page)).toBeVisible()
    await page.getByRole('link', { name: 'Account security' }).click()
    await expect(page.getByText('Authenticator app')).toBeVisible()
    await expect(page.locator('body')).not.toContainText(LATER.secret)
    expect(await storageSizes(page)).toEqual({ local: 0, session: 0 })
    const output = logged.join('\n')
    for (const forbidden of [LATER.password, LATER.secret, totpForStep(LATER.secret, 1)]) {
      expect(output).not.toContain(forbidden)
    }
  })

  test('a recovery code signs in once, and the same code fails the next time', async ({ page }) => {
    const codes = recoveryCodesFor(RECOVERY.tag)

    await passwordStep(page, RECOVERY)
    await page.getByRole('button', { name: 'Use a recovery code instead' }).click()
    // Typed the way it might be read off a printout: lower case, no hyphens.
    await page.getByLabel('Recovery code').fill((codes[0] ?? '').toLowerCase().replaceAll('-', ''))
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(consoleHeading(page)).toBeVisible()

    // It says how many are left, and never which.
    await page.getByRole('link', { name: 'Account security' }).click()
    expect(await recoveryLeft(page)).toBe('9')
    await expect(page.locator('body')).not.toContainText(codes[1] ?? 'x')

    // Sign out, and try the SAME code again: refused, as any wrong code is, with nobody signed in.
    await page.getByRole('button', { name: 'Sign out' }).click()
    await passwordStep(page, RECOVERY)
    await page.getByRole('button', { name: 'Use a recovery code instead' }).click()
    await page.getByLabel('Recovery code').fill(codes[0] ?? '')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByLabel('Recovery code')).toHaveAccessibleDescription(
      /The code is not valid/,
    )
    expect(await meStatus(page)).toBe(401)

    // A different one still works: the others were untouched.
    await page.getByLabel('Recovery code').fill(codes[1] ?? '')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(consoleHeading(page)).toBeVisible()
    await page.getByRole('link', { name: 'Account security' }).click()
    expect(await recoveryLeft(page)).toBe('8')
  })
})

test.describe('managing two-step verification', () => {
  test('regenerates recovery codes, and replaces the authenticator, each on fresh proof', async ({
    browser,
    baseURL,
  }) => {
    test.setTimeout(90_000)
    const page = await freshPage(browser, baseURL ?? '')
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write']) // to READ back what "Copy setup key" wrote
    const logged = captureConsole(page)
    const oldCodes = recoveryCodesFor(MANAGE.tag)

    await passwordStep(page, MANAGE)
    await submitCode(page, MANAGE.secret)
    await expect(consoleHeading(page)).toBeVisible()
    await page.getByRole('link', { name: 'Account security' }).click()
    expect(await recoveryLeft(page)).toBe('10')
    // There is no way to switch it off.
    await expect(page.getByRole('button', { name: /disable|turn off|remove/i })).toHaveCount(0)

    // Regenerating needs the password AND a second factor, here a recovery code. A wrong password changes nothing.
    await page.getByRole('button', { name: 'Generate new recovery codes' }).click()
    const regenerate = page.getByRole('form', { name: 'Generate new recovery codes' })
    await regenerate.getByLabel('Current password').fill('definitely not the password')
    await regenerate.getByRole('button', { name: 'Use a recovery code instead' }).click()
    await regenerate.getByLabel('Recovery code').fill(oldCodes[2] ?? '')
    await regenerate.getByRole('button', { name: 'Generate codes' }).click()
    await expect(regenerate.getByLabel('Current password')).toHaveAccessibleDescription(
      /current password is incorrect/,
    )

    await regenerate.getByLabel('Current password').fill(MANAGE.password)
    await regenerate.getByLabel('Recovery code').fill(oldCodes[2] ?? '')
    await regenerate.getByRole('button', { name: 'Generate codes' }).click()

    const list = page.getByRole('list', { name: 'Recovery codes' })
    await expect(list).toBeVisible()
    const fresh = await list.getByRole('listitem').allTextContents()
    expect(fresh).toHaveLength(10)
    expect(fresh.filter((code) => oldCodes.includes(code))).toEqual([])
    await page.getByLabel('I have saved these recovery codes somewhere safe.').check()
    await page.getByRole('button', { name: 'Done' }).click()
    for (const code of fresh) await expect(page.locator('body')).not.toContainText(code)
    expect(await recoveryLeft(page)).toBe('10')

    // Replacing: proof first (password and a code from the CURRENT authenticator), then a NEW secret, pending.
    await page.getByRole('button', { name: 'Replace authenticator' }).click()
    const replace = page.getByRole('form', { name: 'Replace authenticator' })
    await replace.getByLabel('Current password').fill(MANAGE.password)
    await replace.getByLabel('Authentication code').fill(await nextCode(MANAGE.secret))
    const issuing = page.waitForResponse(
      (r) =>
        r.request().method() === 'POST' &&
        new URL(r.url()).pathname === '/api/v1/mfa/authenticator',
    )
    await replace.getByRole('button', { name: 'Continue' }).click()
    const issued = (await (await issuing).json()) as { secret: string; expires_at: string }
    await expect(
      page.getByRole('img', { name: 'QR code for your authenticator app' }),
    ).toBeVisible()
    const replacement = ((await page.locator('code').first().textContent()) ?? '').replace(
      /\s/g,
      '',
    )
    expect(replacement).toMatch(/^[A-Z2-7]{32}$/)
    expect(replacement).not.toBe(MANAGE.secret)
    expect(replacement).toBe(issued.secret) // the key on the page is the one the server issued

    // The server names the deadline, from the real pending-secret lifetime (15 minutes), and the page shows it.
    const left = Date.parse(issued.expires_at) - Date.now()
    expect(left).toBeGreaterThan(14 * 60_000)
    expect(left).toBeLessThanOrEqual(15 * 60_000 + 5_000)
    await expect(page.getByText(/This setup key expires at/)).toBeVisible()

    // Copy setup key: the canonical key (no display spaces) reaches the clipboard, and nothing else happens.
    const seen: string[] = []
    page.on('request', (request) => seen.push(request.url()))
    await page.getByRole('button', { name: 'Copy setup key' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Setup key copied.' })).toBeVisible()
    expect(await clipboardText(page)).toBe(issued.secret)
    expect(seen).toEqual([])

    // Nothing has changed until the NEW one is proved: a wrong code leaves the setup open. (The field keeps digits only.)
    await page.getByLabel('Code from the new authenticator').fill('000 000')
    await expect(page.getByLabel('Code from the new authenticator')).toHaveValue('000000')
    await page.getByRole('button', { name: 'Switch to the new authenticator' }).click()
    await expect(page.getByLabel('Code from the new authenticator')).toHaveAccessibleDescription(
      /The code is not valid/,
    )
    const good = await nextCode(replacement)
    await page
      .getByLabel('Code from the new authenticator')
      .fill(`${good.slice(0, 3)} ${good.slice(3)}`)
    await expect(page.getByLabel('Code from the new authenticator')).toHaveValue(good)
    await page.getByRole('button', { name: 'Switch to the new authenticator' }).click()
    await expect(page.getByText('Your authenticator has been replaced')).toBeVisible()
    await expect(page.locator('body')).not.toContainText(replacement)

    // The session survived, rotated; the secret is nowhere in storage or the console.
    expect(await meStatus(page)).toBe(200)
    expect(await storageSizes(page)).toEqual({ local: 0, session: 0 })
    const output = logged.join('\n')
    for (const forbidden of [MANAGE.secret, replacement, MANAGE.password, ...fresh]) {
      expect(output).not.toContain(forbidden)
    }

    // The OLD authenticator stops working, an OLD recovery code is dead, and the NEW authenticator signs in.
    await page.getByRole('button', { name: 'Sign out' }).click()
    await passwordStep(page, MANAGE)
    await page
      .getByLabel('Authentication code')
      .fill(totpForStep(MANAGE.secret, Math.floor(Date.now() / 30_000)))
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByLabel('Authentication code')).toHaveAccessibleDescription(
      /The code is not valid/,
    )
    await page.getByRole('button', { name: 'Use a recovery code instead' }).click()
    await page.getByLabel('Recovery code').fill(oldCodes[5] ?? '')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByLabel('Recovery code')).toHaveAccessibleDescription(
      /The code is not valid/,
    )
    expect(await meStatus(page)).toBe(401)
    await page.getByRole('button', { name: 'Use an authenticator code instead' }).click()
    await submitCode(page, replacement)
    await expect(consoleHeading(page)).toBeVisible()

    await page.context().close()
  })
})

test.describe('a pending sign-in', () => {
  test('cannot be finished once the credential it proved has been replaced', async ({
    browser,
    baseURL,
  }) => {
    test.setTimeout(90_000)
    const url = baseURL ?? ''
    const newPassword = `e2e replaced ${crypto.randomUUID()} passphrase`

    // The password is proved; the second step is waiting.
    const waiting = await freshPage(browser, url)
    await passwordStep(waiting, PENDING)

    // Meanwhile the same person (or someone with the password) changes it, on a fully signed-in session elsewhere.
    const elsewhere = await freshPage(browser, url)
    await passwordStep(elsewhere, PENDING)
    await submitCode(elsewhere, PENDING.secret)
    await expect(consoleHeading(elsewhere)).toBeVisible()
    const changed = await apiFrom(elsewhere, 'POST', '/api/v1/password/change', {
      current_password: PENDING.password,
      password: newPassword,
      password_confirmation: newPassword,
    })
    expect(changed.status).toBe(204)

    // The waiting sign-in presents a valid, unused code. It must not turn into a session: the password it proved
    // no longer exists.
    await waiting.getByLabel('Authentication code').fill(await nextCode(PENDING.secret))
    await waiting.getByRole('button', { name: 'Sign in' }).click()
    await expect(loginHeading(waiting)).toBeVisible()
    await expect(waiting.getByText('Your sign-in timed out')).toBeVisible()
    expect(await meStatus(waiting)).toBe(401)
    await expect(waiting.getByRole('navigation', { name: 'Console' })).toHaveCount(0)

    // And the old password no longer starts a sign-in at all.
    await waiting.getByLabel('Email address').fill(PENDING.email)
    await waiting.getByLabel('Password', { exact: true }).fill(PENDING.password)
    await waiting.getByRole('button', { name: 'Sign in' }).click()
    await expect(waiting.getByRole('alert')).toHaveText(
      'The email address or password is incorrect.',
    )

    for (const page of [waiting, elsewhere]) await page.context().close()
  })
})
