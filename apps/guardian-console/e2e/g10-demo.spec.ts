import { expect, test, type Page } from '@playwright/test'

import { axeViolations, inTheme, THEMES } from './axe.ts'
import { demo, persona } from './g10.ts'
import { CARD, PACK } from './resourcesDemo.ts'
import { signedInAs } from './support.ts'

// The G10 demonstration (ADR 0038, WP7), as a reviewer would walk it, over the seeded demo (RelationshipsDemoSeeder and
// ResourcesDemoSeeder; docs/development/g10-demo.md): an administrator reads the Guardian and Volunteer directories by status, a Person
// with two relationships, their history, fields and Guardian Initiate provisioning, and the sourced grants on the Account page; then the
// same shared Pack is read by the privileged viewer and by a restricted reader. Read-only: nothing here changes the demo. Each screen is
// also checked with axe in both themes, and for sideways scrolling at 320, 375 and 1280 px.

test.describe.configure({ timeout: 180_000 })

const DEMO = {
  activeNoRole: 'Amara Okafor',
  former: 'Bernard Lindqvist',
  managedInitiate: 'Chiara Benedetti',
  fullAndInitiate: 'Desmond Achterberg',
  grantedNoAccount: 'Esi Mensah',
  pending: 'Farah Haddad',
  inactive: 'Hana Kowalczyk',
  reactivated: 'Idris Barzani',
} as const

interface Dom {
  document: { documentElement: { scrollWidth: number; clientWidth: number } }
}

const horizontalOverflow = (page: Page): Promise<number> =>
  page.evaluate(() => {
    const root = (globalThis as unknown as Dom).document.documentElement
    return root.scrollWidth - root.clientWidth
  })

async function administrator(page: Page): Promise<void> {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
}

/** Finds people in a directory through its own search form, optionally narrowed to one status, and returns the table. */
async function find(page: Page, plural: 'Guardians' | 'Volunteers', text: string, status = '') {
  await page.goto(`/relationships/${plural.toLowerCase()}`)
  const form = page.getByRole('search', { name: `Find ${plural.toLowerCase()}` })
  await form.getByRole('searchbox').fill(text)
  await form.getByLabel('Status').selectOption(status)
  await form.getByRole('button', { name: 'Search' }).click()
  const table = page.getByRole('table', { name: plural })
  await expect(table).toBeVisible()
  return table
}

async function openRecord(
  page: Page,
  plural: 'Guardians' | 'Volunteers',
  name: string,
): Promise<void> {
  const table = await find(page, plural, name)
  await table.getByRole('link', { name, exact: true }).click()
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
}

test('an administrator reads the demo: directories by status, a Person with two relationships, history, fields and Initiate provisioning', async ({
  browser,
  baseURL,
}) => {
  const page = await signedInAs(browser, baseURL ?? '', 'admin-read')
  await administrator(page)

  // Guardians in each state, filtered by the directory itself.
  const inactiveGuardians = await find(page, 'Guardians', '', 'inactive')
  await expect(inactiveGuardians).toContainText(DEMO.former)
  await expect(inactiveGuardians).not.toContainText(DEMO.activeNoRole)
  const activeGuardians = await find(page, 'Guardians', 'Okafor', 'active')
  await expect(
    activeGuardians.getByRole('row').filter({ hasText: DEMO.activeNoRole }),
  ).toContainText('Active')

  // Volunteers in each state: Pending, Inactive, and one reactivated on the same relationship.
  await expect(await find(page, 'Volunteers', DEMO.pending, 'pending')).toContainText(DEMO.pending)
  await expect(await find(page, 'Volunteers', DEMO.inactive, 'inactive')).toContainText(
    DEMO.inactive,
  )
  await openRecord(page, 'Volunteers', DEMO.reactivated)
  const history = page.getByRole('list', { name: 'Status history' }).getByRole('listitem')
  await expect(history).toHaveCount(3)
  await expect(page.getByText('Sundays')).toBeVisible()

  // A Guardian who is also a Volunteer (and a Member), with no Account, whose Guardian Initiate decision is granted and waits.
  await openRecord(page, 'Guardians', DEMO.grantedNoAccount)
  await expect(page.getByRole('region', { name: 'Guardian Initiate access' })).toContainText(
    'Granted',
  )
  const personPath = new URL(page.url()).pathname.split('/').pop() ?? ''
  await page.goto(`/people/${personPath}`)
  const tabs = page.getByRole('tablist', { name: 'Relationships' })
  await expect(tabs.getByRole('tab')).toHaveText(['Guardian', 'Volunteer'])
  await tabs.getByRole('tab', { name: 'Volunteer' }).click()
  await expect(page.getByText('Saturday mornings')).toBeVisible()

  // A Guardian whose relationship-managed grant was withdrawn when they became Inactive: the decision is kept, the grant is not.
  await openRecord(page, 'Guardians', DEMO.former)
  await expect(page.getByRole('region', { name: 'Guardian Initiate access' })).toContainText(
    'withdrawn',
  )

  // Sourced grants on the Account page, apart from the independent role, read-only.
  for (const [name, independent] of [
    [DEMO.managedInitiate, false],
    [DEMO.fullAndInitiate, true],
  ] as const) {
    await page.goto(`/admin/accounts?q=${encodeURIComponent(name)}`)
    await page.getByRole('link', { name, exact: true }).click()
    const sourced = page.getByRole('region', { name: 'Access through a source' })
    await expect(sourced).toContainText('Guardian Initiate')
    await expect(sourced).toContainText('Granted through a relationship')
    await expect(sourced.getByRole('button')).toHaveCount(0)
    // The independent role is listed under Access with Access's own words (Guardian Full, which "may ... manage Resources").
    const roles = page.getByRole('region', { name: 'Access', exact: true })
    if (independent) await expect(roles).toContainText('manage Resources')
    else await expect(roles).not.toContainText('manage Resources')
  }
  await page.context().close()
})

test('the same shared Pack, read by the privileged viewer and by a restricted reader', async ({
  browser,
  baseURL,
}) => {
  const packs = await demo(browser)
  const viewer = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
  await viewer.goto(`/resource-library/${packs.shared.id}`)
  await expect(viewer.getByRole('heading', { level: 1, name: PACK.shared })).toBeVisible()
  const viewerCards = viewer.getByRole('navigation', { name: 'Cards in this Resource' })
  for (const title of [CARD.dayPlan, CARD.stations, CARD.hosting, CARD.rain]) {
    await expect(viewerCards.getByRole('link', { name: new RegExp(title) })).toBeVisible()
  }
  await expect(viewer.locator('body')).toContainText('Draft')

  const reader = await persona(browser, baseURL, 'persona-initiate')
  await reader.goto(`/resource-library/${packs.shared.id}`)
  await expect(reader.getByRole('heading', { level: 1, name: PACK.shared })).toBeVisible()
  const readerCards = reader.getByRole('navigation', { name: 'Cards in this Resource' })
  await expect(readerCards.getByRole('link')).toHaveText([CARD.dayPlan, CARD.hosting])
  for (const absent of [CARD.stations, CARD.rain, 'Draft', 'For Guardians']) {
    await expect(reader.locator('body')).not.toContainText(absent)
  }
  await viewer.context().close()
  await reader.context().close()
})

for (const theme of THEMES) {
  test(`${theme} theme: no accessibility violation, colour contrast included, on the demo's directories, records and both readers`, async ({
    browser,
    baseURL,
  }) => {
    const packs = await demo(browser)
    const page = await signedInAs(browser, baseURL ?? '', 'admin-read')
    await inTheme(page, theme)
    await administrator(page)
    await find(page, 'Guardians', '')
    expect(await axeViolations(page), 'the Guardian directory').toEqual([])
    await openRecord(page, 'Guardians', DEMO.grantedNoAccount)
    expect(await axeViolations(page), 'a Guardian record with a granted decision').toEqual([])
    await openRecord(page, 'Volunteers', DEMO.reactivated)
    expect(await axeViolations(page), 'a Volunteer record with its history').toEqual([])
    await page.context().close()

    for (const [name, label] of [
      ['persona-volunteer', 'the Volunteer reader'],
      ['persona-multi', 'the Guardian, Volunteer and Member reader'],
    ] as const) {
      const reader = await persona(browser, baseURL, name, theme)
      await reader.goto('/resource-library')
      await expect(
        reader.getByRole('heading', { level: 1, name: 'Resource Library' }),
      ).toBeVisible()
      expect(await axeViolations(reader), `${label}: the library`).toEqual([])
      await reader.goto(`/resource-library/${packs.shared.id}`)
      await expect(reader.getByRole('heading', { level: 1, name: PACK.shared })).toBeVisible()
      expect(await axeViolations(reader), `${label}: the shared Pack`).toEqual([])
      await reader.context().close()
    }
  })
}

test('no demo screen scrolls sideways at 320, 375 and 1280px', async ({ browser, baseURL }) => {
  const packs = await demo(browser)
  const page = await signedInAs(browser, baseURL ?? '', 'admin-read')
  const reader = await persona(browser, baseURL, 'persona-multi')
  await administrator(page)
  for (const width of [320, 375, 1280]) {
    await page.setViewportSize({ width, height: 800 })
    await reader.setViewportSize({ width, height: 800 })
    await find(page, 'Volunteers', '')
    expect(
      await horizontalOverflow(page),
      `the Volunteer directory at ${String(width)}px`,
    ).toBeLessThanOrEqual(0)
    await openRecord(page, 'Guardians', DEMO.grantedNoAccount)
    expect(
      await horizontalOverflow(page),
      `a Guardian record at ${String(width)}px`,
    ).toBeLessThanOrEqual(0)
    await reader.goto('/resource-library')
    await expect(reader.getByRole('heading', { level: 1, name: 'Resource Library' })).toBeVisible()
    expect(
      await horizontalOverflow(reader),
      `the audience library at ${String(width)}px`,
    ).toBeLessThanOrEqual(0)
    await reader.goto(`/resource-library/${packs.shared.id}`)
    await expect(reader.getByRole('heading', { level: 1, name: PACK.shared })).toBeVisible()
    expect(
      await horizontalOverflow(reader),
      `the shared Pack at ${String(width)}px`,
    ).toBeLessThanOrEqual(0)
  }
  await page.context().close()
  await reader.context().close()
})
