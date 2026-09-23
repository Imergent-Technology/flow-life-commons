import { expect, test } from '@playwright/test'

import { signedInAs } from './support.ts'

// Membership administration (ADR 0028, Work Package 6), in real Chromium through the real gateway: an operator's
// end-to-end path through the new Members surface, using the same platform-minted session the Accounts journeys use
// (E2eSessionSeeder's `admin-read`: a real Platform Administrator, freshly verified, so this proves the real HTTP flow
// without also re-proving the step-up prompt itself, which administration.spec.ts already covers for Accounts).

/**
 * A `datetime-local` value (browser-local wall clock, no zone) for `days` from now. The suite must stay meaningful
 * whenever it runs, so every term is relative to the moment of the run and never names a calendar date: a grant that is
 * genuinely current today must still be current when this executes, and must not be one that silently expired.
 * The browser under test runs in this process's timezone, so local components are the right ones to format.
 */
function localInput(days: number): string {
  const at = new Date(Date.now() + days * 86_400_000)
  const pad = (n: number) => String(n).padStart(2, '0')
  const date = [String(at.getFullYear()), pad(at.getMonth() + 1), pad(at.getDate())].join('-')
  return `${date}T${pad(at.getHours())}:${pad(at.getMinutes())}`
}

test.describe('membership administration', () => {
  test('an administrator adds a member, adds another grant, and revokes one, with the list and detail reflecting the server at each step', async ({
    browser,
    baseURL,
  }) => {
    const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
    const name = `E2E Member ${crypto.randomUUID().slice(0, 8)}`

    await admin.goto('/')
    await admin.getByRole('link', { name: 'Members', exact: true }).click()
    await expect(admin.getByRole('heading', { level: 1, name: 'Members' })).toBeVisible()

    // Add a new member with a bounded term that is current NOW: started a month ago, ending in two months.
    await admin.getByRole('link', { name: 'Add member' }).click()
    await expect(admin.getByRole('heading', { level: 1, name: 'Add a new member' })).toBeVisible()
    await admin.getByLabel('Display name').fill(name)
    await admin.getByLabel('Starts at').fill(localInput(-30))
    await admin.getByLabel('Ends at').fill(localInput(60))
    await admin.getByRole('button', { name: 'Add member' }).click()

    await expect(admin.getByRole('heading', { level: 1, name: 'Member added' })).toBeVisible()
    await admin.getByRole('link', { name: 'Open the member' }).click()
    await expect(admin.getByRole('heading', { level: 1, name })).toBeVisible()
    // Exact matches: a bare 'Active' is a substring of 'Inactive', so it could pass for the wrong state.
    await expect(admin.getByText('Active', { exact: true })).toBeVisible()
    await expect(admin.getByText('Inactive', { exact: true })).toHaveCount(0)
    await expect(admin.getByText(/Access through/)).toBeVisible()

    // It appears in the list.
    await admin.getByRole('link', { name: 'Members', exact: true }).click()
    await expect(admin.getByRole('link', { name })).toBeVisible()

    // Add another, open-ended, overlapping grant: the domain merges coverage, it does not refuse this.
    await admin.getByRole('link', { name }).click()
    await admin.getByRole('button', { name: 'Add grant' }).click()
    await admin.getByLabel('Starts at').fill(localInput(-5))
    await admin.getByLabel('Open-ended access (no end date)').check()
    await admin.getByRole('button', { name: 'Review and add' }).click()
    await expect(
      admin.getByRole('dialog', { name: `Add a membership grant for ${name}?` }),
    ).toBeVisible()
    await admin.getByRole('dialog').getByRole('button', { name: 'Add grant' }).click()
    await expect(admin.getByText('A membership grant was added.')).toBeVisible()
    // The merged run is now open-ended (the summary line, distinct from a grant row's own lowercase "open-ended").
    await expect(admin.locator('header').getByText('Open-ended', { exact: true })).toBeVisible()

    // Revoke the ORIGINAL (bounded) grant; the record stays active because the open-ended one still covers it.
    const rows = admin.getByRole('listitem').filter({ hasText: 'Operator' })
    await rows.first().getByRole('button', { name: 'Revoke this grant' }).click()
    await expect(
      admin.getByRole('dialog', { name: `Revoke this grant for ${name}?` }),
    ).toBeVisible()
    await admin.getByRole('dialog').getByRole('button', { name: 'Revoke grant' }).click()
    await expect(admin.getByText(/The grant has been revoked/)).toBeVisible()
    // Unambiguous even mid-refresh (only ever one grant says this); once it settles, the list has finished
    // re-fetching, so the remaining assertions see the final state rather than a transitional one.
    await expect(admin.getByText(/^Revoked/)).toBeVisible() // the one just revoked, kept in history
    await expect(admin.getByText('Not revoked')).toBeVisible() // the surviving grant
    await expect(admin.getByText('Active', { exact: true })).toBeVisible() // still covered by the open-ended grant
    await expect(admin.getByText('Inactive', { exact: true })).toHaveCount(0)
  })

  test('a guardian has no Members link and is refused on the page and at the API', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await signedInAs(browser, baseURL ?? '', 'plain-guardian')

    await guardian.goto('/')
    await expect(guardian.getByRole('link', { name: 'Members', exact: true })).toHaveCount(0)

    await guardian.goto('/admin/members')
    await expect(guardian.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    await expect(guardian.getByRole('table')).toHaveCount(0)
  })
})
