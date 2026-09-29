import { expect, test, type Page } from '@playwright/test'

import { goToAccountSecurity, meStatus, signedInAs, signOut, unique } from './support.ts'

/**
 * The Member self-service surface, in real Chromium through the real same-origin gateway (ADR 0032, Work
 * Package 4): a non-Console Account's own `/my/` area, and a Guardian's ability to visit it too. Not the
 * full WP5 onboarding journey (invitation through Mailpit to first sign-in) — that is its own package;
 * this proves the existing-session/member-surface behaviour once an Account already has one.
 *
 * `E2E No Access` is the same fixture `console.spec.ts` and `accessibility.spec.ts` also sign in as, read-only, for
 * "signed in but not permitted": an active Account with no authenticator and no `console.access`, so signing in is
 * one step, no code. Every test below that uses it only reads or signs itself out, so it never races those other
 * files: nothing here ends another session on the Account. The Guardian side uses the pre-minted `plain-guardian`
 * session every other read-only journey shares (administration.spec.ts, shell.spec.ts, membership.spec.ts): it
 * spends no login attempt and no TOTP step, so it cannot race another spec file for the same code the way signing
 * in live with a shared secret could.
 *
 * `E2E Member Change`, below, is this file's OWN Account, read by nothing else: changing a password ends every
 * OTHER session on the Account, so that test could not share `E2E No Access` without racing the files above.
 */
const MEMBER = {
  email: 'e2e.noaccess@example.org',
  password: 'e2e-noaccess-password-not-a-secret',
}

const MEMBER_CHANGE = {
  email: 'e2e.member.change@example.org',
  password: 'e2e-member-change-password-not-a-secret',
}

const homeHeading = (page: Page) => page.getByRole('heading', { level: 1, name: 'Home' })
const loginHeading = (page: Page) => page.getByRole('heading', { level: 1, name: 'Sign in' })

async function signIn(page: Page, email: string, password: string) {
  await page.goto('/login')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

test.describe('a non-Console Account', () => {
  // Nothing below mutates the shared `E2E No Access` Account (each test only reads, navigates or signs
  // itself out), so these run in whatever order/parallelism Playwright chooses without racing each other
  // or `console.spec.ts`/`accessibility.spec.ts`, which read the same Account. The one test that DOES
  // change a password uses its own dedicated Account, in its own describe block below.

  test('signs in and lands at /my/, with the Member frame visible and the Guardian shell absent', async ({
    page,
  }) => {
    await signIn(page, MEMBER.email, MEMBER.password)

    await expect(homeHeading(page)).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/my')
    expect(await meStatus(page)).toBe(200)
    await expect(page.getByRole('navigation', { name: 'Console' })).toHaveCount(0)
    await expect(page.getByText('Guardian Console')).toHaveCount(0)
    await expect(page.getByText('Flow Life Commons')).toBeVisible()
    await expect(page.getByRole('navigation', { name: 'Member' })).toBeVisible()
  })

  test('reads its own real membership state on /my/membership: no grant on record, truthfully', async ({
    page,
  }) => {
    await signIn(page, MEMBER.email, MEMBER.password)
    await page
      .getByRole('navigation', { name: 'Member' })
      .getByRole('link', { name: 'Membership' })
      .click()

    await expect(page.getByRole('heading', { level: 1, name: 'Membership' })).toBeVisible()
    await expect(page.getByText('Inactive')).toBeVisible()
    await expect(page.getByText('No membership history yet')).toBeVisible()
  })

  test('is refused a genuinely privileged Console route by direct navigation, not silently let in', async ({
    page,
  }) => {
    await signIn(page, MEMBER.email, MEMBER.password)
    // Unlike a locator action, goto() does not wait for anything: it must not fire before the async
    // sign-in (still in flight right after the click) has actually set the session cookie.
    await expect(homeHeading(page)).toBeVisible()
    await page.goto('/admin/accounts')

    await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/admin/accounts') // refused, not redirected anywhere
  })

  test('signs out from /my/ the same way the Console does', async ({ page }) => {
    await signIn(page, MEMBER.email, MEMBER.password)
    await homeHeading(page).waitFor()

    await signOut(page)

    await expect(loginHeading(page)).toBeVisible()
    expect(await meStatus(page)).toBe(401)
  })

  test('is responsive at a phone width and at desktop, with no horizontal overflow', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 375, height: 800 })
    await signIn(page, MEMBER.email, MEMBER.password)
    await expect(homeHeading(page)).toBeVisible()
    const narrowScroll = await page.evaluate(() => {
      const root = (
        globalThis as unknown as {
          document: { documentElement: { scrollWidth: number; clientWidth: number } }
        }
      ).document.documentElement
      return root.scrollWidth - root.clientWidth
    })
    expect(narrowScroll).toBeLessThanOrEqual(1) // a stray hairline is not a real overflow

    await page.setViewportSize({ width: 1280, height: 900 })
    await page.reload()
    await expect(homeHeading(page)).toBeVisible()
    await expect(page.getByRole('navigation', { name: 'Member' })).toBeVisible()
  })
})

test.describe('a non-Console Account changing its own password', () => {
  // Its own dedicated Account (`E2E Member Change`), not `E2E No Access`: changing a password ends every
  // OTHER session on the Account, and `E2E No Access` is read, in parallel, by the tests above and by
  // console.spec.ts/accessibility.spec.ts — sharing it here would cut their sessions out from under them.
  test('changes its own password from /my/security, the same self-service the Console already offers', async ({
    page,
  }) => {
    await signIn(page, MEMBER_CHANGE.email, MEMBER_CHANGE.password)
    await goToAccountSecurity(page) // "Account security" in the menu; this Account's own is /my/security

    await expect(page.getByRole('heading', { level: 1, name: 'Security' })).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/my/security')
    // No two-step verification section: this Account has no authenticator, and nothing here invents one.
    await expect(page.getByRole('heading', { name: 'Two-step verification' })).toHaveCount(0)

    const next = unique('member-security')
    await page.getByLabel('Current password').fill(MEMBER_CHANGE.password)
    await page.getByLabel('New password', { exact: true }).fill(next)
    await page.getByLabel('Confirm new password').fill(next)
    await page.getByRole('button', { name: 'Change password' }).click()

    await expect(
      page.getByText('Your password has been changed. Other devices have been signed out.'),
    ).toBeVisible()

    // Restore it, so the fixture stays reusable across runs and workers.
    await page.getByLabel('Current password').fill(next)
    await page.getByLabel('New password', { exact: true }).fill(MEMBER_CHANGE.password)
    await page.getByLabel('Confirm new password').fill(MEMBER_CHANGE.password)
    await page.getByRole('button', { name: 'Change password' }).click()
    await expect(
      page.getByText('Your password has been changed. Other devices have been signed out.'),
    ).toBeVisible()
  })
})

test.describe('a Guardian and /my/ (ADR 0032)', () => {
  test('still lands in the Console by default, may visit /my/ manually, and can return', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await signedInAs(browser, baseURL ?? '', 'plain-guardian')

    await guardian.goto('/')
    await expect(guardian.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()

    await guardian.goto('/my')
    await expect(homeHeading(guardian)).toBeVisible()
    await expect(guardian.getByRole('heading', { name: 'Access denied' })).toHaveCount(0)

    await guardian.goto('/')
    await expect(guardian.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
    await expect(guardian.getByRole('navigation', { name: 'Console' })).toBeVisible()

    await guardian.context().close()
  })
})
