import { expect, test, type Browser, type Page } from '@playwright/test'

import {
  apiFrom,
  captureConsole,
  invitationEmailedTo,
  meStatus,
  nextCode,
  recoveryCodesFor,
  signedInAs,
  storageSizes,
  unique,
} from './support.ts'

// Operator administration (ADR 0024), in real Chromium through the real gateway: what an administrator can do to other
// people's access, and what stops anyone else.
//
// The administrators and the guardian these journeys act as start from sessions the PLATFORM minted by its own sign-in
// (E2eSessionSeeder: a known Account and a single-use recovery code, in-process), so they do not spend the public login
// rate budget on set-up. Everything that IS about signing in still does it for real in the browser: the invitee accepting
// an emailed invitation and enrolling, being re-enabled and challenged, and the person whose second factor is reset.
// Accounts, passwords and secrets are development fixtures (E2eAccountSeeder); all are public and worthless.

const STALE_ADMIN = {
  password: 'e2e-admin-stale-password-not-a-secret',
  secret: 'YGDVPF7NJEC7MJ54SW3IKGUW7MTJWSEO',
}
const RECOVER_TARGET = {
  email: 'e2e.admin.recovertarget@example.org',
  password: 'e2e-admin-recovertarget-password-not-a-secret',
  secret: 'MOUYMASSFUZOSF2XUTWSGM7XYTNSZYWX',
}

const consoleHeading = (page: Page) =>
  page.getByRole('heading', { level: 1, name: 'Flow Life Guardian Console' })

async function freshPage(browser: Browser, baseURL: string): Promise<Page> {
  return (await browser.newContext({ baseURL })).newPage()
}

/** The id of an Account, asked of the API as the Console would (an administrator's page). */
async function accountIdOf(page: Page, email: string): Promise<string> {
  const listed = await apiFrom(page, 'GET', `/api/v1/admin/accounts?q=${encodeURIComponent(email)}`)
  const rows = (listed.body as { data: { id: string; email: string }[] }).data
  const found = rows.find((row) => row.email === email)
  if (found === undefined) throw new Error(`No account for ${email}.`)
  return found.id
}

async function statusOf(page: Page, id: string): Promise<string> {
  return ((await apiFrom(page, 'GET', `/api/v1/admin/accounts/${id}`)).body as { status: string })
    .status
}

/** Opens an account's page by searching for it, as an operator would. */
async function openAccount(page: Page, email: string, name: string) {
  await page.goto('/admin/accounts')
  await page.getByLabel('Name or email').fill(email)
  await page.getByRole('button', { name: 'Search' }).click()
  await page.getByRole('link', { name, exact: true }).click()
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
}

async function fillLogin(page: Page, email: string, password: string) {
  await page.goto('/login')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

test.describe('who may administer', () => {
  test('an administrator opens Account administration; a guardian is refused, on the page and at the API', async ({
    browser,
    baseURL,
  }) => {
    const url = baseURL ?? ''
    const admin = await signedInAs(browser, url, 'admin-read')

    await admin.goto('/')
    await expect(consoleHeading(admin)).toBeVisible()
    await admin.getByRole('link', { name: 'Accounts', exact: true }).click()
    await expect(admin.getByRole('heading', { level: 1, name: 'Accounts' })).toBeVisible()
    const table = admin.getByRole('table', { name: 'Accounts' })
    await expect(table.getByRole('link', { name: 'E2E Admin Read' })).toBeVisible()
    await expect(table.getByText('(you)')).toBeVisible()
    await expect(table.getByText('Platform administrator').first()).toBeVisible()

    // An operator's own account offers no way to disable it or to reset its own second factor here, and the server refuses both.
    await table.getByRole('link', { name: 'E2E Admin Read' }).click()
    await expect(admin.getByRole('heading', { level: 1, name: 'E2E Admin Read' })).toBeVisible()
    await expect(admin.getByRole('button', { name: 'Disable this account' })).toHaveCount(0)
    await expect(admin.getByRole('button', { name: 'Reset two-step verification' })).toHaveCount(0)
    const self = ((await apiFrom(admin, 'GET', '/api/v1/me')).body as { account: { id: string } })
      .account.id
    const reset = await apiFrom(admin, 'POST', `/api/v1/admin/accounts/${self}/mfa/reset`)
    expect(reset.status).toBe(422)
    expect(reset.body).toMatchObject({ code: 'self_mfa_reset_prohibited' })

    const guardian = await signedInAs(browser, url, 'plain-guardian')
    await guardian.goto('/')
    await expect(consoleHeading(guardian)).toBeVisible() // they may use the Console...
    await expect(guardian.getByRole('link', { name: 'Accounts', exact: true })).toHaveCount(0)
    await guardian.goto('/admin/accounts')
    await expect(guardian.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    await expect(guardian.getByRole('table')).toHaveCount(0)

    // ...and the server refuses them whatever the page shows: reads, and mutations, and it does not even ask them to prove anything.
    const target = await accountIdOf(admin, 'e2e.admin.target@example.org')
    expect((await apiFrom(guardian, 'GET', '/api/v1/admin/accounts')).status).toBe(403)
    const refused = await apiFrom(guardian, 'POST', `/api/v1/admin/accounts/${target}/disable`)
    expect(refused.status).toBe(403)
    expect(refused.body).not.toHaveProperty('verification_required')
    expect(await statusOf(admin, target)).toBe('active')
  })
})

test.describe('recent verification', () => {
  test('a sensitive action needs a fresh proof: the session alone is not enough, the prompt asks for it, and the person then confirms again', async ({
    browser,
    baseURL,
  }) => {
    test.setTimeout(120_000)
    const admin = await signedInAs(browser, baseURL ?? '', 'admin-stale')
    const logged = captureConsole(admin)
    await openAccount(admin, 'e2e.admin.target@example.org', 'E2E Stale Target')
    const target = await accountIdOf(admin, 'e2e.admin.target@example.org')

    // The session is alive and may READ, but its last proof is older than 15 minutes: the server refuses a change outright.
    expect(await meStatus(admin)).toBe(200)
    const refused = await apiFrom(admin, 'POST', `/api/v1/admin/accounts/${target}/disable`)
    expect(refused.status).toBe(403)
    expect(refused.body).toMatchObject({ verification_required: true })
    expect(await statusOf(admin, target)).toBe('active')

    // Through the Console, the same refusal opens a real proof prompt.
    await admin.getByRole('button', { name: 'Disable this account' }).click()
    const confirm = admin.getByRole('dialog', { name: 'Disable E2E Stale Target?' })
    await expect(confirm.getByText(/signed out everywhere/)).toBeVisible()
    await confirm.getByRole('button', { name: 'Disable account' }).click()
    const prompt = admin.getByRole('dialog', { name: 'Confirm it is you' })
    await expect(prompt).toBeVisible()
    await expect(prompt.getByLabel('Current password'))
      .toBeFocused()
      .catch(() => undefined)

    // A wrong password is refused, in the prompt, and nothing has happened.
    await prompt.getByLabel('Current password').fill('not the password')
    await prompt.getByLabel('Authentication code').fill(await nextCode(STALE_ADMIN.secret))
    await prompt.getByRole('button', { name: 'Confirm' }).click()
    await expect(prompt.getByText('The current password is incorrect.')).toBeVisible()
    expect(await statusOf(admin, target)).toBe('active')

    // The right proof closes it, and STILL nothing has been done: the person is told to confirm again.
    await prompt.getByLabel('Current password').fill(STALE_ADMIN.password)
    await prompt.getByLabel('Authentication code').fill(await nextCode(STALE_ADMIN.secret))
    await prompt.getByRole('button', { name: 'Confirm' }).click()
    await expect(prompt).toHaveCount(0)
    await expect(admin.getByText(/confirm again to continue/)).toBeVisible()
    expect(await statusOf(admin, target)).toBe('active')

    // Only a deliberate second press does it, and the proof is now recorded on the session.
    await confirm.getByRole('button', { name: 'Disable account' }).click()
    await expect(admin.getByText('E2E Stale Target is disabled.')).toBeVisible()
    expect(await statusOf(admin, target)).toBe('disabled')
    const me = (await apiFrom(admin, 'GET', '/api/v1/me')).body as {
      mfa: { security_verified_until: string | null }
    }
    expect(me.mfa.security_verified_until).not.toBeNull()

    // Verified, the next sensitive action goes straight through: no prompt.
    await admin.getByRole('button', { name: 'Re-enable this account' }).click()
    await admin
      .getByRole('dialog', { name: 'Re-enable E2E Stale Target?' })
      .getByRole('button', { name: 'Re-enable account' })
      .click()
    await expect(admin.getByText('E2E Stale Target can sign in again.')).toBeVisible()
    await expect(admin.getByRole('dialog', { name: 'Confirm it is you' })).toHaveCount(0)
    expect(await statusOf(admin, target)).toBe('active')

    // The proof, the password and the code were never logged and never put in browser storage.
    expect(logged.join('\n')).not.toContain(STALE_ADMIN.password)
    expect(await storageSizes(admin)).toEqual({ local: 0, session: 0 })
  })
})

test.describe.serial('inviting, changing access, disabling and re-enabling someone', () => {
  const invitee = {
    name: 'E2E Invited Operator',
    email: `invited.${crypto.randomUUID().slice(0, 8)}@example.org`,
    password: unique('invited operator'),
  }
  let admin: Page
  let person: Page
  let secret = ''

  test.beforeAll(async ({ browser, baseURL }) => {
    admin = await signedInAs(browser, baseURL ?? '', 'admin-story')
    person = await freshPage(browser, baseURL ?? '')
  })

  test('the administrator invites an operator, and the invitation is emailed', async ({
    baseURL,
    request,
  }) => {
    await admin.goto('/admin/accounts')
    await admin.getByRole('link', { name: 'Invite an operator' }).click()
    await expect(admin.getByRole('heading', { level: 1, name: 'Invite an operator' })).toBeVisible()

    await admin.getByLabel('Display name').fill(invitee.name)
    await admin.getByLabel('Email address').fill(invitee.email)
    // The access on offer comes from the server; the Console names none of it.
    await admin.getByRole('checkbox', { name: /Guardian/ }).check()
    await admin.getByRole('button', { name: 'Send invitation' }).click()

    await expect(admin.getByRole('heading', { level: 1, name: 'Invitation sent' })).toBeVisible()
    await expect(admin.locator('body')).not.toContainText(/[A-Za-z0-9_-]{43}/) // the secret is nowhere on the page

    const mail = await invitationEmailedTo(request, baseURL ?? '', invitee.email)
    expect(mail.link).toContain('/accept-invitation#token=') // in the FRAGMENT, out of access logs
    expect(mail.link).not.toContain('?token=')
    await person.goto(mail.link)
  })

  test('the invitee accepts it, their email is VERIFIED, and they enrol before entering the Console', async () => {
    await expect(person.getByText('Your invitation link was recognised.')).toBeVisible()
    await person.getByLabel('New password', { exact: true }).fill(invitee.password)
    await person.getByLabel('Confirm new password').fill(invitee.password)
    await person.getByRole('button', { name: 'Set password and activate' }).click()
    await expect(
      person.getByRole('heading', { level: 1, name: 'Invitation accepted' }),
    ).toBeVisible()
    expect(await meStatus(person)).toBe(401) // accepting signs no one in

    await person.getByRole('link', { name: 'Continue to sign in' }).click()
    await fillLogin(person, invitee.email, invitee.password)
    await expect(
      person.getByRole('heading', { level: 1, name: 'Set up two-step verification' }),
    ).toBeVisible()
    await person.getByRole('button', { name: 'Set up authenticator' }).click()
    secret = ((await person.locator('code').first().textContent()) ?? '').replace(/\s/g, '')
    expect(secret).toMatch(/^[A-Z2-7]{32}$/)
    await person.getByLabel('Authentication code').fill(await nextCode(secret))
    await person.getByRole('button', { name: 'Verify and continue' }).click()
    await expect(person.getByRole('list', { name: 'Recovery codes' })).toBeVisible()
    await person.getByLabel('I have saved these recovery codes somewhere safe.').check()
    await person.getByRole('button', { name: 'Continue to the Console' }).click()
    await expect(consoleHeading(person)).toBeVisible()

    // The administrator sees the account active, with a VERIFIED email: the invitation was delivered to that mailbox.
    await openAccount(admin, invitee.email, invitee.name)
    await expect(admin.getByText('Active', { exact: true }).first()).toBeVisible()
    await expect(
      admin.getByText('Email verified').locator('xpath=following-sibling::dd[1]'),
    ).not.toHaveText('Not verified')
    await expect(admin.getByText('Guardian', { exact: true }).first()).toBeVisible()
  })

  test('the administrator removes and restores their access, and it takes effect on the very next request', async () => {
    await admin.getByRole('button', { name: 'Remove Guardian' }).click()
    await admin
      .getByRole('dialog', { name: /Remove “Guardian”/ })
      .getByRole('button', { name: 'Remove access' })
      .click()
    await expect(admin.getByText(/“Guardian” was removed/)).toBeVisible()
    await expect(admin.getByText('They hold no access.')).toBeVisible()

    // No new sign-in: the invitee's page is refused the Console the moment it next asks.
    await person.reload()
    await expect(person.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()

    await admin.getByLabel('Give them access').selectOption({ label: 'Guardian' })
    await admin.getByRole('button', { name: 'Add access' }).click()
    await admin
      .getByRole('dialog', { name: /Give E2E Invited Operator/ })
      .getByRole('button', { name: 'Give access' })
      .click()
    await expect(admin.getByText(/now has “Guardian” access/)).toBeVisible()
    await person.reload()
    await expect(consoleHeading(person)).toBeVisible()
  })

  test('the administrator disables them, which ends their session, then re-enables them, and they still need their second factor', async ({
    browser,
    baseURL,
  }) => {
    test.setTimeout(120_000)
    await admin.getByRole('button', { name: 'Disable this account' }).click()
    const dialog = admin.getByRole('dialog', { name: `Disable ${invitee.name}?` })
    await expect(
      dialog.getByText(/person record, their access roles and their history are kept/),
    ).toBeVisible()
    await dialog.getByRole('button', { name: 'Disable account' }).click()
    await expect(admin.getByText(`${invitee.name} is disabled.`)).toBeVisible()

    expect(await meStatus(person)).toBe(401) // signed out everywhere, at once
    const blocked = await freshPage(browser, baseURL ?? '')
    await fillLogin(blocked, invitee.email, invitee.password)
    await expect(blocked.getByRole('alert')).toBeVisible() // a disabled account cannot sign in
    expect(await meStatus(blocked)).toBe(401)

    await admin.getByRole('button', { name: 'Re-enable this account' }).click()
    await admin
      .getByRole('dialog', { name: `Re-enable ${invitee.name}?` })
      .getByRole('button', { name: 'Re-enable account' })
      .click()
    await expect(admin.getByText(`${invitee.name} can sign in again.`)).toBeVisible()

    // Re-enabling created no session and bypassed nothing: the password leads to the second-factor challenge.
    const back = await freshPage(browser, baseURL ?? '')
    await fillLogin(back, invitee.email, invitee.password)
    await expect(back.getByRole('heading', { level: 1, name: 'Enter your code' })).toBeVisible()
    expect(await meStatus(back)).toBe(401)
    await back.getByLabel('Authentication code').fill(await nextCode(secret))
    await back.getByRole('button', { name: 'Sign in' }).click()
    await expect(consoleHeading(back)).toBeVisible()
  })
})

test.describe('recovering a second factor', () => {
  test("an administrator resets another person's, the old factor stops working, and they enrol a new one before the Console", async ({
    browser,
    baseURL,
  }) => {
    test.setTimeout(120_000)
    const url = baseURL ?? ''
    const admin = await signedInAs(browser, url, 'admin-recover')
    await openAccount(admin, RECOVER_TARGET.email, 'E2E Recover Target')
    await expect(admin.getByText('Set up, with 10 recovery codes left.')).toBeVisible()

    await admin.getByRole('button', { name: 'Reset two-step verification' }).click()
    const dialog = admin.getByRole('dialog', {
      name: 'Reset two-step verification for E2E Recover Target?',
    })
    await expect(dialog.getByText(/authenticator app and recovery codes/)).toBeVisible()
    await expect(dialog.getByText(/signed out everywhere/)).toBeVisible()
    await expect(
      dialog.getByText(/must sign in with their password and set up two-step verification again/),
    ).toBeVisible()
    await dialog.getByRole('button', { name: 'Reset two-step verification' }).click()
    await expect(admin.getByText(/will set it up again when they next sign in/)).toBeVisible()
    await expect(admin.getByText('Not set up.')).toBeVisible()

    // Their password still works, but it no longer leads to a challenge: the old authenticator and every old
    // recovery code are dead, and they must enrol again.
    const person = await freshPage(browser, url)
    await fillLogin(person, RECOVER_TARGET.email, RECOVER_TARGET.password)
    await expect(
      person.getByRole('heading', { level: 1, name: 'Set up two-step verification' }),
    ).toBeVisible()
    expect(await meStatus(person)).toBe(401)
    const oldCode = await apiFrom(person, 'POST', '/api/v1/mfa/challenge', {
      code: await nextCode(RECOVER_TARGET.secret),
    })
    expect(oldCode.status).toBe(401)
    const oldRecovery = await apiFrom(person, 'POST', '/api/v1/mfa/challenge', {
      recovery_code: recoveryCodesFor('T')[0],
    })
    expect(oldRecovery.status).toBe(401)

    // They can enrol a new authenticator, and only then reach the Console.
    await fillLogin(person, RECOVER_TARGET.email, RECOVER_TARGET.password)
    await person.getByRole('button', { name: 'Set up authenticator' }).click()
    const fresh = ((await person.locator('code').first().textContent()) ?? '').replace(/\s/g, '')
    expect(fresh).not.toBe(RECOVER_TARGET.secret)
    await person.getByLabel('Authentication code').fill(await nextCode(fresh))
    await person.getByRole('button', { name: 'Verify and continue' }).click()
    await person.getByLabel('I have saved these recovery codes somewhere safe.').check()
    await person.getByRole('button', { name: 'Continue to the Console' }).click()
    await expect(consoleHeading(person)).toBeVisible()
  })
})
