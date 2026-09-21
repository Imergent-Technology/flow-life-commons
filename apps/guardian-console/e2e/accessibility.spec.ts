import { readFileSync } from 'node:fs'

import { expect, test, type Browser, type Page } from '@playwright/test'

import { apiFrom, signedInAs } from './support.ts'

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

const AXE = readFileSync('node_modules/axe-core/axe.min.js', 'utf8')

// Injecting axe and running the full WCAG rule set over a page takes seconds, and these journeys do it
// several times each. The default 30 s is a suite-wide figure for journeys that do not.
test.describe.configure({ timeout: 120_000 })

const ADMIN = {
  email: 'e2e.admin.read@example.org',
  password: 'e2e-admin-read-password-not-a-secret',
  secret: 'OXYIMCFIPNZB575Y7MZF7OBR26YHQGEY',
}

interface AxeViolation {
  id: string
  help: string
  nodes: { target: string[] }[]
}

/** Runs axe over the current page, with colour contrast ON, and returns readable violations. */
async function axeViolations(page: Page): Promise<string[]> {
  await page.addScriptTag({ content: AXE })
  const violations = await page.evaluate(async () => {
    const runner = (globalThis as unknown as { axe: { run: (o: unknown) => Promise<unknown> } }).axe
    const results = (await runner.run({
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    })) as { violations: AxeViolation[] }
    return results.violations.map(
      (v) => `${v.id}: ${v.help} (${v.nodes.map((n) => n.target.join(' ')).join('; ')})`,
    )
  })
  return violations
}

test.describe('accessibility of the principal screens', () => {
  test('the public pages pass axe in a real browser, contrast included', async ({ page }) => {
    for (const path of ['/login', '/forgot-password', '/reset-password', '/accept-invitation']) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      expect(await axeViolations(page), path).toEqual([])
    }
  })

  test('the second-factor step passes too', async ({ page }) => {
    // Reached for real, because it only exists mid-sign-in.
    await page.goto('/login')
    await page.getByLabel('Email address').fill(ADMIN.email)
    await page.getByLabel('Password', { exact: true }).fill(ADMIN.password)
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Enter your code' })).toBeVisible()

    expect(await axeViolations(page)).toEqual([])
  })

  test('the signed-in and administration screens pass', async ({ browser, baseURL }) => {
    const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
    await admin.goto('/')

    for (const path of ['/', '/account/security', '/admin/accounts', '/admin/invitations/new']) {
      await admin.goto(path)
      await expect(admin.getByRole('heading', { level: 1 })).toBeVisible()
      expect(await axeViolations(admin), path).toEqual([])
    }

    // And a detail page, which needs an id.
    const listed = await apiFrom(admin, 'GET', '/api/v1/admin/accounts?q=e2e.admin.read@')
    const id = (listed.body as { data: { id: string }[] }).data[0]?.id ?? ''
    await admin.goto(`/admin/accounts/${id}`)
    await expect(admin.getByRole('heading', { level: 1, name: 'E2E Admin Read' })).toBeVisible()
    expect(await axeViolations(admin)).toEqual([])

    await admin.context().close()
  })
})

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
