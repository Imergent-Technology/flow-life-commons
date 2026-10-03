import { expect, test, type Browser, type Page } from '@playwright/test'

import { axeViolations, inTheme, THEMES, type Theme } from './axe.ts'
import { aDemoPersonId, aMemberId, aThreadId, apiFrom, signedInAs } from './support.ts'

/**
 * The production-readiness accessibility pass.
 *
 * The Console already has structural axe coverage in the component tests, but those run in jsdom, which
 * has no layout and no styles — so the one rule it cannot judge is `color-contrast`, and that is the one
 * most likely to be wrong in a design nobody has measured. These journeys run **axe-core in a real
 * browser, with colour contrast enabled**, over the screens an operator actually spends time in, and
 * finish with the two things a rule engine cannot check: that focus comes back from a dialog, and that
 * a whole administrative task can be done without a mouse.
 *
 * They run against the development origin, not the production-equivalent one, for a mechanical reason:
 * the production CSP has no 'unsafe-inline', so axe cannot be injected into that page at all. What is
 * being measured — markup, roles, names, and the contrast of Tailwind's rendered colours — is identical
 * in both, because both are the same components and the same stylesheet.
 *
 * Deliberately NOT a redesign, and deliberately not exhaustive: it reports what it finds and leaves
 * screen-reader behaviour, which no automated check establishes, explicitly unverified.
 */

// Injecting axe and running the full WCAG rule set over a page takes seconds, and these journeys do it
// several times each. The default 30 s is a suite-wide figure for journeys that do not.
test.describe.configure({ timeout: 120_000 })

const ADMIN = {
  email: 'e2e.admin.read@example.org',
  password: 'e2e-admin-read-password-not-a-secret',
  secret: 'OXYIMCFIPNZB575Y7MZF7OBR26YHQGEY',
}

async function resolvedTheme(page: Page): Promise<string | undefined> {
  return page.evaluate(
    () =>
      (globalThis as unknown as { document: { documentElement: { dataset: { theme?: string } } } })
        .document.documentElement.dataset.theme,
  )
}

/** Opens `path` in the theme, waits for the page's one h1 and for its data to arrive, and returns axe's findings. */
async function auditRoute(page: Page, path: string, theme: Theme): Promise<string[]> {
  await page.goto(path)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await page.waitForLoadState('networkidle')
  // Skeletons and "Loading…" lines are gone: what is audited is the real page, not its placeholder.
  await expect(page.locator('[aria-busy="true"]')).toHaveCount(0)
  expect(await resolvedTheme(page), `${path} booted in ${theme}`).toBe(theme)
  return axeViolations(page)
}

for (const theme of THEMES) {
  test.describe(`accessibility of every screen in ${theme}`, () => {
    test(`the public pages pass axe in a real browser, contrast included (${theme})`, async ({
      page,
    }) => {
      await inTheme(page, theme)
      for (const path of ['/login', '/forgot-password', '/reset-password', '/accept-invitation']) {
        expect(await auditRoute(page, path, theme), path).toEqual([])
      }
    })

    test(`the second-factor step passes too (${theme})`, async ({ page }) => {
      // Reached for real, because it only exists mid-sign-in.
      await inTheme(page, theme)
      await page.goto('/login')
      await page.getByLabel('Email address').fill(ADMIN.email)
      await page.getByLabel('Password', { exact: true }).fill(ADMIN.password)
      await page.getByRole('button', { name: 'Sign in' }).click()
      await expect(page.getByRole('heading', { level: 1, name: 'Enter your code' })).toBeVisible()

      expect(await axeViolations(page)).toEqual([])
    })

    test(`the sign-in failure and service-unavailable screens pass (${theme})`, async ({
      page,
    }) => {
      await inTheme(page, theme)
      await page.goto('/login')
      await page.getByLabel('Email address').fill('nobody@example.org')
      await page.getByLabel('Password', { exact: true }).fill('not the password at all')
      await page.getByRole('button', { name: 'Sign in' }).click()
      await expect(page.getByRole('alert')).toBeVisible()
      expect(await axeViolations(page), 'a refused sign-in').toEqual([])

      await page.route('**/api/v1/me', (route) => route.abort())
      await page.goto('/')
      await expect(
        page.getByRole('heading', { level: 1, name: 'Service unavailable' }),
      ).toBeVisible()
      expect(await axeViolations(page), 'service unavailable').toEqual([])
    })

    test(`the signed-in and administration screens pass (${theme})`, async ({
      browser,
      baseURL,
    }) => {
      const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
      await inTheme(admin, theme)
      await admin.goto('/')

      const listed = await apiFrom(admin, 'GET', '/api/v1/admin/accounts?q=e2e.admin.read@')
      const id = (listed.body as { data: { id: string }[] }).data[0]?.id ?? ''
      const memberId = await aMemberId(admin)
      const personId = await aDemoPersonId(admin, 'Marguerite Hale')
      const longNotePersonId = await aDemoPersonId(admin, 'Daniel Okoye')
      const openThread = await aThreadId(admin, 'open')
      const resolvedThread = await aThreadId(admin, 'resolved')

      for (const path of [
        '/',
        '/account/security',
        '/admin/accounts',
        '/admin/accounts/invite',
        `/admin/accounts/${id}`,
        '/admin/members',
        '/admin/members/new',
        `/admin/members/${memberId}`,
        '/people',
        '/people/new',
        '/people/tags',
        `/people/${personId}`,
        `/people/${longNotePersonId}`,
        '/discussions',
        '/discussions/new',
        `/discussions/${openThread}`,
        `/discussions/${resolvedThread}`,
        '/no/such/page',
      ]) {
        expect(await auditRoute(admin, path, theme), path).toEqual([])
      }

      await admin.context().close()
    })

    test(`a guardian refused a section, and a person refused the Console, pass (${theme})`, async ({
      browser,
      baseURL,
    }) => {
      const guardian = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
      await inTheme(guardian, theme)
      expect(await auditRoute(guardian, '/admin/accounts', theme), 'not permitted').toEqual([])
      await guardian.context().close()
    })

    test(`a non-Console Account's /my/ home, and access denied on a privileged route, both pass (${theme})`, async ({
      page,
    }) => {
      await inTheme(page, theme)
      await page.goto('/login')
      await page.getByLabel('Email address').fill('e2e.noaccess@example.org')
      await page.getByLabel('Password', { exact: true }).fill('e2e-noaccess-password-not-a-secret')
      await page.getByRole('button', { name: 'Sign in' }).click()
      await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible()
      await page.waitForLoadState('networkidle')
      expect(await axeViolations(page), '/my/').toEqual([])

      await page.goto('/account/security')
      await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()
      expect(await axeViolations(page), 'access denied').toEqual([])
    })

    test(`the rest of the Member surface passes: /my/membership and /my/security (${theme})`, async ({
      page,
    }) => {
      await inTheme(page, theme)
      await page.goto('/login')
      await page.getByLabel('Email address').fill('e2e.noaccess@example.org')
      await page.getByLabel('Password', { exact: true }).fill('e2e-noaccess-password-not-a-secret')
      await page.getByRole('button', { name: 'Sign in' }).click()
      await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible()

      await page
        .getByRole('navigation', { name: 'Member' })
        .getByRole('link', { name: 'Membership' })
        .click()
      await expect(page.getByRole('heading', { level: 1, name: 'Membership' })).toBeVisible()
      await page.waitForLoadState('networkidle')
      expect(await axeViolations(page), '/my/membership').toEqual([])

      await page.getByRole('link', { name: 'Security' }).click()
      await expect(page.getByRole('heading', { level: 1, name: 'Security' })).toBeVisible()
      expect(await axeViolations(page), '/my/security').toEqual([])
    })

    test(`dialogs pass (${theme})`, async ({ browser, baseURL }) => {
      const admin = await openDisableTarget(browser, baseURL ?? '')
      await inTheme(admin, theme)
      await admin.reload()
      await expect(admin.getByRole('heading', { level: 1 })).toBeVisible()
      await admin.getByRole('button', { name: 'Disable this account' }).click()
      await expect(admin.getByRole('dialog')).toBeVisible()
      expect(await axeViolations(admin), 'the destructive confirmation').toEqual([])
      await admin.context().close()
    })
  })
}

for (const theme of THEMES) {
  test.describe(`accessibility of the shell's states in ${theme}`, () => {
    /** A signed-in page in the theme at a width, on a page whose section has a drawer. */
    async function shell(browser: Browser, baseURL: string, width: number): Promise<Page> {
      const admin = await signedInAs(browser, baseURL, 'admin-read')
      await inTheme(admin, theme)
      await admin.setViewportSize({ width, height: 800 })
      await admin.goto('/admin/accounts')
      await expect(admin.getByRole('heading', { level: 1, name: 'Accounts' })).toBeVisible()
      await admin.waitForLoadState('networkidle')
      expect(await resolvedTheme(admin)).toBe(theme)
      return admin
    }

    test(`pinned drawer (1280px) (${theme})`, async ({ browser, baseURL }) => {
      const admin = await shell(browser, baseURL ?? '', 1280)
      await expect(admin.locator('[data-drawer="pinned"]')).toBeVisible()
      expect(await axeViolations(admin)).toEqual([])
      await admin.context().close()
    })

    test(`overlay drawer, open (1100px) (${theme})`, async ({ browser, baseURL }) => {
      const admin = await shell(browser, baseURL ?? '', 1100)
      await admin
        .getByRole('navigation', { name: 'Console' })
        .getByRole('button', { name: 'Admin' })
        .click()
      await expect(admin.locator('[data-drawer="overlay"]')).toBeVisible()
      expect(await axeViolations(admin)).toEqual([])
      await admin.context().close()
    })

    test(`account menu, open (${theme})`, async ({ browser, baseURL }) => {
      const admin = await shell(browser, baseURL ?? '', 1280)
      await admin.getByRole('button', { name: /account menu/i }).click()
      await expect(admin.getByRole('menu', { name: 'Account' })).toBeVisible()
      expect(await axeViolations(admin)).toEqual([])
      await admin.context().close()
    })

    test(`mobile navigation sheet, open (375px) (${theme})`, async ({ browser, baseURL }) => {
      const admin = await shell(browser, baseURL ?? '', 375)
      await admin.getByRole('button', { name: 'Navigation menu' }).click()
      await expect(admin.getByRole('dialog')).toBeVisible()
      expect(await axeViolations(admin)).toEqual([])
      await admin.context().close()
    })

    test(`every stop of the keyboard path shows a focus ring (${theme})`, async ({ page }) => {
      await inTheme(page, theme)
      await page.goto('/login')
      await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
      // The heading takes focus on arrival; Tab then walks the form and its links.
      const stops: { tag: string; outline: string; width: string }[] = []
      for (let step = 0; step < 5; step += 1) {
        await page.keyboard.press('Tab')
        stops.push(
          await page.evaluate(() => {
            const g = globalThis as unknown as {
              document: { activeElement: unknown }
              getComputedStyle: (e: unknown) => {
                outlineStyle: string
                outlineWidth: string
                outlineColor: string
              }
            }
            const active = g.document.activeElement as { tagName: string }
            const style = g.getComputedStyle(active)
            return {
              tag: active.tagName,
              outline: `${style.outlineStyle} ${style.outlineColor}`,
              width: style.outlineWidth,
            }
          }),
        )
      }
      expect(stops.map((stop) => stop.tag)).toEqual(['INPUT', 'INPUT', 'BUTTON', 'A', 'A'])
      for (const stop of stops) {
        expect(stop.outline, `${stop.tag} has a visible outline`).toMatch(/^solid rgb/)
        expect(Number.parseFloat(stop.width), `${stop.tag} outline is 2px`).toBeGreaterThanOrEqual(
          2,
        )
      }
    })
  })
}

test.describe('what a rule engine cannot check', () => {
  test('returns focus to the control that opened a dialog when it is cancelled', async ({
    browser,
    baseURL,
  }) => {
    // Phase 8 relies on the native <dialog> moving focus in and back out. axe cannot see this: the
    // markup is correct either way, and a person who cancels ends up at the top of the document.
    const admin = await openDisableTarget(browser, baseURL ?? '')

    const open = admin.getByRole('button', { name: 'Disable this account' })
    await open.click()
    const dialog = admin.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // Focus really moved into the dialog, not merely "a dialog appeared".
    expect(await focusedInside(admin, 'dialog')).toBe(true)

    await admin.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(open).toBeFocused()

    await admin.context().close()
  })

  test('completes an administrative task with the keyboard alone', async ({ browser, baseURL }) => {
    // One representative workflow, no mouse: find the account, open the confirmation, back out of it,
    // and confirm that nothing changed. Tab order, focus visibility and Enter/Escape all have to work
    // for this to pass, and none of them is something a rule engine judges.
    const admin = await openDisableTarget(browser, baseURL ?? '')

    await admin.keyboard.press('Escape') // start from a known state
    const open = admin.getByRole('button', { name: 'Disable this account' })
    await open.focus()
    await admin.keyboard.press('Enter')
    await expect(admin.getByRole('dialog')).toBeVisible()

    // Every control in the dialog is reachable by Tab, and Escape is Cancel.
    await admin.keyboard.press('Tab')
    expect(await focusedInside(admin, 'dialog')).toBe(true)
    await admin.keyboard.press('Escape')
    await expect(admin.getByRole('dialog')).toBeHidden()
    await expect(open).toBeFocused()

    // Nothing happened: cancelling really cancels.
    await expect(admin.getByText('Disabled', { exact: true })).toHaveCount(0)

    await admin.context().close()
  })
})

/** An administrator's page, open on an account that can be disabled. */
async function openDisableTarget(browser: Browser, baseURL: string): Promise<Page> {
  const admin = await signedInAs(browser, baseURL, 'admin-read')
  await admin.goto('/') // apiFrom fetches a relative path FROM the page, so it must be on the origin first
  const listed = await apiFrom(admin, 'GET', '/api/v1/admin/accounts?q=e2e.admin.target@')
  const id = (listed.body as { data: { id: string }[] }).data[0]?.id ?? ''
  await admin.goto(`/admin/accounts/${id}`)
  await expect(admin.getByRole('heading', { level: 1 })).toBeVisible()
  return admin
}

/** Whether the focused element is inside an element matching the selector. */
async function focusedInside(page: Page, selector: string): Promise<boolean> {
  return page.evaluate((target) => {
    const d = globalThis as unknown as {
      document: {
        activeElement: { closest: (s: string) => unknown } | null
      }
    }
    return d.document.activeElement?.closest(target) != null
  }, selector)
}
