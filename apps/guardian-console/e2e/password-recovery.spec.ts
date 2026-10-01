import { expect, test } from '@playwright/test'

import {
  expectNoTokenShapedText,
  locationOf,
  mailpit,
  messageIdsTo,
  signedInAs,
} from './support.ts'

// An operator sends an Account holder the NORMAL password-reset email (ADR 0024), in real Chromium through the real gateway
// with real Mailpit mail. What it proves: the operator's screen and the API response never carry the token or the link; the
// holder's message is the ordinary reset message, to the right address, with the existing link shape; and following that link
// still lands on the existing reset page. It does NOT complete the password change: credentials/console specs already prove
// that path, and finishing it here would only make this fixture mutation-heavy.
//
// The operator is a pre-minted, freshly verified Platform Administrator session (E2eSessionSeeder). The target is its OWN
// fixture (E2eAccountSeeder::RESET_TARGET_EMAIL): a reset token is per-Account state, so no other journey reads it.
const TARGET = { email: 'e2e.admin.resettarget@example.org', name: 'E2E Reset Target' }

test.describe('an operator sends a password reset email', () => {
  test('the holder gets the normal reset message; the operator never sees a token or a link', async ({
    browser,
    baseURL,
    request,
  }) => {
    const url = baseURL ?? ''
    const admin = await signedInAs(browser, url, 'admin-recover')
    const before = await messageIdsTo(request, url, TARGET.email)

    // Account detail, as an operator reaches it.
    await admin.goto('/admin/accounts')
    await admin.getByLabel('Name or email').fill(TARGET.email)
    await admin.getByRole('button', { name: 'Search' }).click()
    await admin.getByRole('link', { name: TARGET.name, exact: true }).click()
    await expect(admin.getByRole('heading', { level: 1, name: TARGET.name })).toBeVisible()

    // Password recovery is its own panel, apart from two-step verification, and offers no way to set a password.
    await expect(admin.getByRole('heading', { name: 'Password' })).toBeVisible()
    await expect(admin.getByRole('button', { name: /reset sign-in access/i })).toHaveCount(0)
    await admin.getByRole('button', { name: 'Send password reset email' }).click()
    const dialog = admin.getByRole('dialog', {
      name: `Send ${TARGET.name} a password reset email?`,
    })
    await expect(dialog).toContainText('You will not see or set the new password')
    await expect(dialog).toContainText('Their two-step verification is not reset')
    await expect(dialog.locator('input, textarea')).toHaveCount(0)

    // What the API answers is the only thing the operator's browser ever receives about this.
    const answered = admin.waitForResponse(
      (response) =>
        response.url().endsWith('/password-reset') && response.request().method() === 'POST',
    )
    await dialog.getByRole('button', { name: 'Send reset email' }).click()
    const response = await answered
    expect(response.status()).toBe(200)
    const responseText = await response.text()
    expect(JSON.parse(responseText)).toMatchObject({ delivery: { status: 'sent' } })
    expect(responseText).not.toMatch(/token|reset-password|[A-Za-z0-9_-]{43}/i)
    await expect(
      admin.getByText(`A password reset email was sent to ${TARGET.email}.`),
    ).toBeVisible()

    // Exactly one new message reaches the holder, and only their address.
    let fresh: string[] = []
    await expect
      .poll(
        async () => {
          fresh = (await messageIdsTo(request, url, TARGET.email)).filter(
            (id) => !before.includes(id),
          )
          return fresh.length
        },
        { timeout: 20_000 },
      )
      .toBe(1)
    const message = await mailpit(request, url, `/api/v1/message/${fresh[0] ?? ''}`)
    const mail = (await message.json()) as {
      Subject: string
      Text: string
      To: { Address: string }[]
    }
    expect(mail.To.map((to) => to.Address)).toEqual([TARGET.email])
    expect(mail.Subject).toBe('Reset your password')
    // Neutral wording: the same message anyone asking to reset gets, never claiming the Guardian Console.
    expect(mail.Text).toContain('Someone asked to reset the password for this email address.')
    expect(mail.Text).not.toContain('Guardian')
    const link = /(https?:\/\/\S+\/reset-password#token=[^&\s]+&email=\S+)/.exec(mail.Text)?.[1]
    expect(link, 'the message carries the existing reset link shape').toBeDefined()
    const token = decodeURIComponent(/#token=([^&\s]+)/.exec(link ?? '')?.[1] ?? '')
    expect(token.length).toBeGreaterThan(20)

    // The operator's screen, storage and page source hold none of it.
    await expectNoTokenShapedText(admin)
    expect(await admin.content()).not.toContain(token)
    const stored = await admin.evaluate(() =>
      JSON.stringify([
        Object.entries(globalThis.localStorage),
        Object.entries(globalThis.sessionStorage),
      ]),
    )
    expect(stored).not.toContain(token)

    // The holder follows the link: the existing reset page, with the secret scrubbed from the address.
    const holder = await (await browser.newContext({ baseURL: url })).newPage()
    await holder.goto(link ?? '')
    await expect(
      holder.getByRole('heading', { level: 1, name: 'Choose a new password' }),
    ).toBeVisible()
    await expect(holder).toHaveURL(`${url}/reset-password`)
    const scrubbed = await locationOf(holder)
    expect(scrubbed.href).not.toContain(token)
    expect(scrubbed.hash).toBe('')
    await holder.context().close()
    await admin.context().close()
  })
})
