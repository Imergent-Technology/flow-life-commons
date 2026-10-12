import { expect, test, type Browser, type Page } from '@playwright/test'

import { axeViolations, inTheme, THEMES, type Theme } from './axe.ts'
import { apiFrom, nextCode, recoveryCodesFor, signedInAs, type FixtureSession } from './support.ts'

// Relationships (ADR 0038, G10 WP5), in real Chromium through the real gateway and with the real platform deciding every request.
// The personas are real assignments, not interception:
//   - `plain-guardian` holds the Guardian role: it VIEWS Guardians, and it views and MANAGES Volunteers (and may view People).
//   - `relationships-admin` is a Console administrator, who also manages Guardians and may assign roles.
// The one thing no real persona is, is "may manage a type but not view it"; that single state is simulated by removing `view` from
// the served definition, and the platform still answers every request it is sent.
//
// Each journey makes its own people with random names and removes the relationships it made, as the administrator (deleting needs a
// recent proof). The administrator's recovery codes are single use: 9 and 8 sign the two sessions in and 7 proves the stale one. Any
// other proof (a long run ages a session past 15 minutes) is made with the authenticator, which has no such limit.

test.describe.configure({ timeout: 240_000 })

const ROOT = '/api/v1/admin/relationships'
const ADMIN_PASSWORD = 'e2e-relationships-admin-password-not-a-secret'
const ADMIN_CODES = recoveryCodesFor('Y')
const ADMIN_SECRET = 'KRUGKIDROVUWG2ZAMJZG653OEBTYPHLI'
const GUARDIAN_PASSWORD = 'e2e-admin-guardian-password-not-a-secret'
const GUARDIAN_CODES = recoveryCodesFor('S')

const tag = () => crypto.randomUUID().slice(0, 8)
const ulid = /([0-9A-Za-z]{26})\/?$/

interface Dom {
  document: { documentElement: { scrollWidth: number; clientWidth: number } }
}

const horizontalOverflow = (page: Page): Promise<number> =>
  page.evaluate(() => {
    const root = (globalThis as unknown as Dom).document.documentElement
    return root.scrollWidth - root.clientWidth
  })

async function signedIn(
  browser: Browser,
  baseURL: string | undefined,
  as: FixtureSession,
  theme?: Theme,
): Promise<Page> {
  const page = await signedInAs(browser, baseURL ?? '', as)
  if (theme !== undefined) await inTheme(page, theme)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  return page
}

/** The person id at the end of a record page's address. */
function personIdIn(page: Page): string {
  const found = ulid.exec(new URL(page.url()).pathname)
  expect(found, `no person id in ${page.url()}`).not.toBeNull()
  return found?.[1] ?? ''
}

/**
 * A session whose proof of who it is has aged past 15 minutes (a long run does that) is asked for one by the platform. Proving
 * freshens it, so a journey that must not be interrupted does so once, up front, with the authenticator.
 */
async function ensureVerified(page: Page, password: string, secret: string): Promise<void> {
  const me = (await apiFrom(page, 'GET', '/api/v1/me')).body as {
    mfa: { security_verified_until: string | null }
  }
  if (me.mfa.security_verified_until !== null) return
  for (let attempt = 0; attempt < 2; attempt++) {
    const proved = await apiFrom(page, 'POST', '/api/v1/security/verify', {
      current_password: password,
      code: await nextCode(secret),
    })
    if (proved.status === 204) return
  }
  expect(false, 'prove who is asking').toBe(true)
}

async function relationshipOf(
  page: Page,
  slug: string,
  personId: string,
): Promise<{ id: string; revision: number } | null> {
  const read = await apiFrom(page, 'GET', `${ROOT}/${slug}/${personId}/management`)
  if (read.status !== 200) return null
  const body = read.body as { relationship_id: string; revision: number }
  return { id: body.relationship_id, revision: body.revision }
}

/** Removes a relationship through the API, as cleanup. A session that is not freshly verified cannot, so callers prove first. */
async function remove(page: Page, slug: string, personId: string): Promise<void> {
  const current = await relationshipOf(page, slug, personId)
  if (current === null) return
  await apiFrom(
    page,
    'DELETE',
    `${ROOT}/${slug}/${personId}?relationship_id=${current.id}&revision=${String(current.revision)}`,
  )
}

/** Removes a relationship as the administrator, whatever the journey's own persona may do (deletion needs a recent proof). */
async function adminRemove(
  browser: Browser,
  baseURL: string | undefined,
  slug: string,
  personId: string,
): Promise<void> {
  const admin = await signedIn(browser, baseURL, 'relationships-admin')
  try {
    await ensureVerified(admin, ADMIN_PASSWORD, ADMIN_SECRET)
    await remove(admin, slug, personId)
  } finally {
    await admin.context().close()
  }
}

/** Records a person as a Volunteer through the API (a Volunteer needs no recent proof), and returns the person's id. */
async function makeVolunteer(
  page: Page,
  name: string,
  fields: Record<string, string> = {},
  status = 'active',
): Promise<string> {
  const made = await apiFrom(page, 'POST', `${ROOT}/volunteers`, {
    new_person: {
      display_name: name,
      email: `${crypto.randomUUID().slice(0, 12)}@example.org`,
      phone: null,
    },
    status,
    fields,
  })
  expect(made.status, JSON.stringify(made.body)).toBe(201)
  return (made.body as { person: { id: string } }).person.id
}

async function openNavigation(page: Page): Promise<void> {
  await page.goto('/people')
  await expect(page.getByRole('heading', { level: 1, name: 'People' })).toBeVisible()
}

test.describe('a Volunteer manager', () => {
  test('finds the Relationships entries the definitions serve, records a new Volunteer, maintains them, and removes them', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL, 'plain-guardian')
    const unique = tag()
    const name = `E2E Volunteer ${unique}`
    const email = `volunteer.${unique}@example.org`
    let personId = ''

    try {
      // The navigation is built from the served definitions and this Guardian's real capabilities: they may view Guardians and
      // view and manage Volunteers, and not manage Guardians.
      await openNavigation(page)
      const links = page.getByRole('navigation', { name: 'People' })
      await expect(links.getByRole('link', { name: 'Guardians', exact: true })).toBeVisible()
      await expect(links.getByRole('link', { name: 'Volunteers', exact: true })).toBeVisible()
      await expect(links.getByRole('link', { name: 'Record a Volunteer' })).toBeVisible()
      await expect(links.getByRole('link', { name: 'Record a Guardian' })).toHaveCount(0)

      // Find first, so nobody is recorded twice: nothing matches, and the page offers to add someone new.
      await links.getByRole('link', { name: 'Record a Volunteer' }).click()
      await expect(
        page.getByRole('heading', { level: 1, name: 'Record a Volunteer' }),
      ).toBeVisible()
      await page.getByRole('searchbox', { name: 'Name, email or phone' }).fill(name)
      await page.getByRole('button', { name: 'Search' }).click()
      await expect(page.getByText(/No one matches/)).toBeVisible()
      await page.getByRole('button', { name: 'Add someone new' }).click()

      await page.getByLabel('Display name').fill(name)
      await page.getByLabel('Email').fill(email)
      await page.getByLabel('Initial status').selectOption('pending')
      await page.getByLabel('Interests and skills').fill('Gardening and carpentry')
      await page.getByRole('button', { name: 'Review and record' }).click()

      // Nothing is recorded until the final confirmation, which says who and in what status.
      const review = page.getByRole('dialog', { name: `Record ${name} as a Volunteer?` })
      await expect(review).toContainText('Pending')
      await review.getByRole('button', { name: 'Record' }).click()
      await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
      await expect(page.getByText(`${name} was recorded as a Volunteer.`)).toBeVisible()
      personId = personIdIn(page)

      // Status: a Volunteer needs no recent proof, only a deliberate confirmation.
      await page.getByRole('button', { name: 'Change to Active' }).click()
      await page
        .getByRole('dialog', { name: `Change ${name} to Active?` })
        .getByRole('button', { name: 'Change to Active' })
        .click()
      await expect(page.getByText(`${name} is now Active.`)).toBeVisible()
      await expect(
        page.getByRole('list', { name: 'Status history' }).getByRole('listitem'),
      ).toHaveCount(2)

      // Fields: only the changed one is saved.
      await page.getByRole('button', { name: 'Edit details' }).click()
      await page.getByLabel('Availability').fill('Weekends')
      await page.getByRole('button', { name: 'Save details' }).click()
      await expect(page.getByText('Saved.')).toBeVisible()
      await expect(page.getByText('Weekends')).toBeVisible()

      // Basic details: the Volunteer's own contact details, kept up from here. CRM, which the platform asks, shows the change.
      const changed = `volunteer.changed.${unique}@example.org`
      await page.getByRole('button', { name: 'Edit contact details' }).click()
      await page.getByRole('textbox', { name: 'Primary email' }).fill(changed)
      await page.getByRole('button', { name: 'Save contact details' }).click()
      await expect(page.getByText('Contact details saved.')).toBeVisible()
      const crm = (await apiFrom(page, 'GET', `/api/v1/admin/people/${personId}`)).body as {
        contact_methods: { kind: string; value: string; is_primary: boolean }[]
      }
      expect(crm.contact_methods.find((m) => m.kind === 'email' && m.is_primary)?.value).toBe(
        changed,
      )

      // The directory finds them, with the same status and contact details.
      await page.goto('/relationships/volunteers')
      await page.getByRole('searchbox', { name: 'Name, email or phone' }).fill(unique)
      await page.getByRole('button', { name: 'Search' }).click()
      const table = page.getByRole('table', { name: 'Volunteers' })
      await expect(table.getByRole('link', { name })).toBeVisible()
      await expect(table.getByText(changed)).toBeVisible()

      // The Person record has a tab for each type this Guardian may use, and a Volunteer manager is not told more.
      await page.goto(`/people/${personId}`)
      const tabs = page.getByRole('tablist', { name: 'Relationships' })
      await expect(tabs.getByRole('tab')).toHaveText(['Guardian', 'Volunteer'])
      await tabs.getByRole('tab', { name: 'Guardian' }).click()
      await expect(page.getByText(`${name} is not recorded as a Guardian.`)).toBeVisible()
      await expect(page.getByRole('link', { name: 'Record as Guardian' })).toHaveCount(0) // may not manage Guardians
      await tabs.getByRole('tab', { name: 'Volunteer' }).click()
      await expect(page.getByRole('region', { name: 'Volunteer', exact: true })).toBeVisible()

      // Permanent deletion: confirmation first, and a recent proof if the platform asks for one.
      await page.goto(`/relationships/volunteers/${personId}`)
      await page.getByRole('button', { name: /Delete this volunteer/ }).click()
      const confirm = page.getByRole('dialog', { name: `Delete ${name}'s volunteer permanently?` })
      await expect(confirm).toContainText('does not delete')
      await confirm.getByRole('button', { name: 'Delete permanently' }).click()
      const prompt = page.getByRole('dialog', { name: 'Confirm it is you' })
      if (await prompt.isVisible({ timeout: 2000 }).catch(() => false)) {
        await prompt.getByLabel('Current password').fill(GUARDIAN_PASSWORD)
        await prompt.getByRole('button', { name: 'Use a recovery code instead' }).click()
        await prompt.getByLabel('Recovery code').fill(GUARDIAN_CODES[0] ?? '')
        await prompt.getByRole('button', { name: 'Confirm' }).click()
        await confirm.getByRole('button', { name: 'Delete permanently' }).click()
      }
      await expect(page.getByRole('heading', { level: 1, name: 'Volunteers' })).toBeVisible()
      await expect(page.getByText(`${name}'s volunteer was deleted permanently.`)).toBeVisible()
      expect((await apiFrom(page, 'GET', `${ROOT}/volunteers/${personId}`)).status).toBe(404)
      // The person is still in CRM: deleting a relationship deletes nothing else.
      expect((await apiFrom(page, 'GET', `/api/v1/admin/people/${personId}`)).status).toBe(200)
      personId = ''
    } finally {
      await page.context().close()
      if (personId !== '') await adminRemove(browser, baseURL, 'volunteers', personId)
    }
  })
})

test.describe('an administrator recognising a Guardian', () => {
  test('grants Guardian Initiate access with the explicit choice, sees it on the Account page as read-only, and sees it withdrawn and restored with the status', async ({
    browser,
    baseURL,
  }) => {
    const admin = await signedIn(browser, baseURL, 'relationships-admin')
    await ensureVerified(admin, ADMIN_PASSWORD, ADMIN_SECRET)
    // The Guardian role's own person: an existing person, with an Account that already holds the Console, so an Initiate grant
    // through the relationship adds nothing it did not have, and the journey removes the relationship at the end.
    const holder = 'E2E Plain Guardian'
    let personId = ''

    try {
      await admin.goto('/relationships/guardians/new')
      await admin.getByRole('searchbox', { name: 'Name, email or phone' }).fill(holder)
      await admin.getByRole('button', { name: 'Search' }).click()
      await admin.getByRole('button', { name: `Choose ${holder}` }).click()

      // Choosing records nothing: the confirmation names them and says what recording means.
      const confirmExisting = admin.getByRole('dialog', { name: `Record ${holder}?` })
      await expect(confirmExisting).toContainText('creates a guardian relationship')
      await confirmExisting.getByRole('button', { name: 'Continue' }).click()

      await admin.getByLabel('Initial status').selectOption('active')
      // Guardian Initiate is preselected for an operator who may assign roles, and the operator can still say no.
      const option = admin.getByRole('checkbox', { name: 'Grant Guardian Initiate access' })
      await expect(option).toBeChecked()
      await admin.getByRole('button', { name: 'Review and record' }).click()
      const review = admin.getByRole('dialog', { name: `Record ${holder} as a Guardian?` })
      await expect(review).toContainText('Guardian Initiate access will be granted.')
      await review.getByRole('button', { name: 'Record' }).click()

      await expect(admin.getByRole('heading', { level: 1, name: holder })).toBeVisible()
      personId = personIdIn(admin)
      const access = admin.getByRole('region', { name: 'Guardian Initiate access' })
      await expect(access).toContainText('Granted, and in effect')

      // The Account page lists it apart from the independent assignments, read-only, with its source.
      const accounts = (await apiFrom(admin, 'GET', '/api/v1/admin/accounts?q=e2e.admin.guardian@'))
        .body as {
        data: { id: string }[]
      }
      const accountId = accounts.data[0]?.id ?? ''
      await admin.goto(`/admin/accounts/${accountId}`)
      const sourced = admin.getByRole('region', { name: 'Access through a source' })
      await expect(sourced).toContainText('Guardian Initiate')
      await expect(sourced).toContainText('Granted through a relationship')
      await expect(sourced.getByRole('button')).toHaveCount(0)

      // Deactivation withdraws it (the Account page stops listing it); reactivation offers it again, preselected because it was granted.
      await admin.goto(`/relationships/guardians/${personId}`)
      await admin.getByRole('button', { name: 'Change to Inactive' }).click()
      const deactivate = admin.getByRole('dialog', { name: `Change ${holder} to Inactive?` })
      await expect(deactivate).toContainText('access given through this relationship is withdrawn')
      await deactivate.getByRole('button', { name: 'Change to Inactive' }).click()
      await expect(admin.getByText(`${holder} is now Inactive.`)).toBeVisible()
      await expect(admin.getByRole('region', { name: 'Guardian Initiate access' })).toContainText(
        'Granted, withdrawn while not active',
      )
      await admin.goto(`/admin/accounts/${accountId}`)
      await expect(admin.getByRole('heading', { level: 1 })).toBeVisible()
      await expect(admin.getByRole('region', { name: 'Access through a source' })).toHaveCount(0)

      await admin.goto(`/relationships/guardians/${personId}`)
      await admin.getByRole('button', { name: 'Change to Active' }).click()
      const reactivate = admin.getByRole('dialog', { name: `Change ${holder} to Active?` })
      await expect(
        reactivate.getByRole('checkbox', { name: 'Grant Guardian Initiate access' }),
      ).toBeChecked()
      await reactivate.getByRole('button', { name: 'Change to Active' }).click()
      await expect(admin.getByText(`${holder} is now Active.`)).toBeVisible()
      await expect(admin.getByRole('region', { name: 'Guardian Initiate access' })).toContainText(
        'Granted, and in effect',
      )

      // Withdrawing it is a decision on the relationship, and deleting the relationship removes the rest.
      await admin.getByRole('button', { name: 'Withdraw access' }).click()
      await admin
        .getByRole('dialog', { name: `Withdraw Guardian Initiate access from ${holder}?` })
        .getByRole('button', { name: 'Withdraw access' })
        .click()
      await expect(
        admin.getByText(`Guardian Initiate access was withdrawn from ${holder}.`),
      ).toBeVisible()
      await expect(admin.getByRole('region', { name: 'Guardian Initiate access' })).toContainText(
        'Declined',
      )

      await admin.getByRole('button', { name: /Delete this guardian/ }).click()
      await admin
        .getByRole('dialog', { name: `Delete ${holder}'s guardian permanently?` })
        .getByRole('button', { name: 'Delete permanently' })
        .click()
      await expect(admin.getByRole('heading', { level: 1, name: 'Guardians' })).toBeVisible()
      expect((await apiFrom(admin, 'GET', `${ROOT}/guardians/${personId}`)).status).toBe(404)
      personId = ''
    } finally {
      if (personId !== '') await remove(admin, 'guardians', personId)
      await admin.context().close()
    }
  })
})

test.describe('recent verification guards Guardian recognition', () => {
  test('a session whose proof is due is refused, recorded nothing, asked to prove, and records only on a second press', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL, 'relationships-admin-stale')
    const name = `E2E Guardian ${tag()}`
    let personId = ''
    try {
      const me = (await apiFrom(page, 'GET', '/api/v1/me')).body as {
        mfa: { security_verified_until: string | null }
      }
      expect(me.mfa.security_verified_until, 'the proof is due').toBeNull()

      await page.goto('/relationships/guardians/new')
      await page.getByRole('button', { name: 'Add someone new' }).click()
      await page.getByLabel('Display name').fill(name)
      await page.getByLabel('Initial status').selectOption('inactive')
      await page.getByRole('button', { name: 'Review and record' }).click()
      await page
        .getByRole('dialog', { name: `Record ${name} as a Guardian?` })
        .getByRole('button', { name: 'Record' })
        .click()

      // The platform wants a recent proof; the Console opens the shared prompt and says nothing has been recorded.
      const prompt = page.getByRole('dialog', { name: 'Confirm it is you' })
      await expect(prompt).toBeVisible()
      await expect(prompt).toContainText('Nothing has been changed yet')
      await prompt.getByLabel('Current password').fill(ADMIN_PASSWORD)
      await prompt.getByRole('button', { name: 'Use a recovery code instead' }).click()
      await prompt.getByLabel('Recovery code').fill(ADMIN_CODES[7] ?? '')
      await prompt.getByRole('button', { name: 'Confirm' }).click()
      await expect(prompt).toHaveCount(0)

      // Proving did not record: they are still on the intake page, told to confirm again, and nobody was created.
      await expect(page.getByText(/Nothing has been recorded yet: confirm again/)).toBeVisible()
      const nobody = (
        await apiFrom(page, 'GET', `${ROOT}/guardians/candidates?q=${encodeURIComponent(name)}`)
      ).body as {
        data: unknown[]
      }
      expect(nobody.data).toHaveLength(0)

      await page
        .getByRole('dialog', { name: `Record ${name} as a Guardian?` })
        .getByRole('button', { name: 'Record' })
        .click()
      await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
      personId = personIdIn(page)
      // An Inactive Guardian was recorded with no access decision at all.
      await expect(page.getByRole('region', { name: 'Guardian Initiate access' })).toContainText(
        'Not decided',
      )
    } finally {
      if (personId !== '') await remove(page, 'guardians', personId)
      await page.context().close()
    }
  })
})

test.describe('what the platform lets each real persona do', () => {
  test('a Guardian who may only VIEW Guardians sees their records, with no control, and cannot reach intake', async ({
    browser,
    baseURL,
  }) => {
    const admin = await signedIn(browser, baseURL, 'relationships-admin')
    await ensureVerified(admin, ADMIN_PASSWORD, ADMIN_SECRET)
    const name = `E2E Viewed Guardian ${tag()}`
    const made = await apiFrom(admin, 'POST', `${ROOT}/guardians`, {
      new_person: { display_name: name, email: null, phone: null },
      status: 'inactive',
      fields: { stewardship: 'The garden' },
    })
    expect(made.status, JSON.stringify(made.body)).toBe(201)
    const personId = (made.body as { person: { id: string } }).person.id
    const viewer = await signedIn(browser, baseURL, 'plain-guardian')

    try {
      await viewer.goto(`/relationships/guardians/${personId}`)
      await expect(viewer.getByRole('heading', { level: 1, name })).toBeVisible()
      await expect(viewer.getByText('The garden')).toBeVisible()
      await expect(viewer.getByRole('list', { name: 'Status history' })).toBeVisible()
      for (const control of [
        /Change to/,
        'Edit details',
        'Grant access',
        'Withdraw access',
        /Delete this/,
      ]) {
        await expect(viewer.getByRole('button', { name: control })).toHaveCount(0)
      }
      // The management read is refused by the platform itself.
      expect(
        (await apiFrom(viewer, 'GET', `${ROOT}/guardians/${personId}/management`)).status,
      ).toBe(403)

      await viewer.goto('/relationships/guardians')
      await expect(viewer.getByRole('table', { name: 'Guardians' })).toBeVisible()
      await expect(viewer.getByRole('link', { name: 'Record a Guardian' })).toHaveCount(0)
      await viewer.goto('/relationships/guardians/new')
      await expect(viewer.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    } finally {
      await remove(admin, 'guardians', personId)
      await viewer.context().close()
      await admin.context().close()
    }
  })

  test('a manager who cannot view a type reads only the management view, with no history, and reaches no directory', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL, 'plain-guardian')
    const name = `E2E Managed Volunteer ${tag()}`
    const personId = await makeVolunteer(page, name, { interests: 'Painting' })
    // The one simulated state: the served definition says this operator may manage but not view Volunteers. The platform
    // still answers whatever the Console then asks it.
    const served = (await apiFrom(page, 'GET', '/api/v1/admin/relationship-types')).body as {
      data: { type: string; actions: Record<string, boolean> }[]
    }
    for (const type of served.data) if (type.type === 'volunteer') type.actions.view = false
    await page.route('**/api/v1/admin/relationship-types', (route) =>
      route.fulfill({ json: served }),
    )
    const requested: string[] = []
    page.on('request', (request) => requested.push(new URL(request.url()).pathname))

    try {
      await page.goto(`/relationships/volunteers/${personId}`)
      await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
      await expect(page.getByRole('button', { name: /Change to/ }).first()).toBeVisible()
      await expect(page.getByText('Painting')).toBeVisible()
      await expect(page.getByRole('heading', { name: 'History' })).toHaveCount(0)
      await expect(page.getByText('Status since')).toHaveCount(0)
      expect(requested).toContain(`${ROOT}/volunteers/${personId}/management`)
      expect(requested).not.toContain(`${ROOT}/volunteers/${personId}`) // never asked for the record it may not view

      await page.goto('/relationships/volunteers')
      await expect(page.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
      expect(requested).not.toContain(`${ROOT}/volunteers`) // nor the directory
      await page.goto('/relationships/volunteers/new')
      await expect(
        page.getByRole('heading', { level: 1, name: 'Record a Volunteer' }),
      ).toBeVisible()
    } finally {
      await page.context().close()
      await adminRemove(browser, baseURL, 'volunteers', personId)
    }
  })

  test('a relationship deleted and recorded again under a working operator is a conflict, and nothing is resent', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL, 'plain-guardian')
    const name = `E2E Moving Volunteer ${tag()}`
    const personId = await makeVolunteer(page, name, {}, 'pending')
    try {
      await page.goto(`/relationships/volunteers/${personId}`)
      await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
      await page.getByRole('button', { name: 'Change to Active' }).click()
      const dialog = page.getByRole('dialog', { name: `Change ${name} to Active?` })

      // Behind the operator's back the same person's relationship is changed by someone else.
      const before = await relationshipOf(page, 'volunteers', personId)
      const elsewhere = await apiFrom(page, 'PUT', `${ROOT}/volunteers/${personId}/status`, {
        relationship_id: before?.id,
        revision: before?.revision,
        status: 'inactive',
      })
      expect(elsewhere.status).toBe(200)

      await dialog.getByRole('button', { name: 'Change to Active' }).click()
      await expect(
        page.getByText('This volunteer was changed while you were working.'),
      ).toBeVisible()
      await expect(page.getByText('Your change was not applied.')).toBeVisible()
      // The platform holds what the other person set; the Console did not send the old decision again.
      const now = (await apiFrom(page, 'GET', `${ROOT}/volunteers/${personId}/management`))
        .body as { status: string }
      expect(now.status).toBe('inactive')
    } finally {
      await page.context().close()
      await adminRemove(browser, baseURL, 'volunteers', personId)
    }
  })
})

for (const theme of THEMES) {
  test.describe(`${theme} theme`, () => {
    test('no accessibility violation, colour contrast included, in the relationship screens and their dialogs', async ({
      browser,
      baseURL,
    }) => {
      const page = await signedIn(browser, baseURL, 'plain-guardian', theme)
      const name = `E2E Audited Volunteer ${tag()}`
      const personId = await makeVolunteer(page, name, { interests: 'Gardening' }, 'pending')
      try {
        await page.goto('/relationships/volunteers')
        await expect(page.getByRole('table', { name: 'Volunteers' })).toBeVisible()
        expect(await axeViolations(page), 'the directory').toEqual([])

        await page.goto('/relationships/volunteers/new')
        await expect(
          page.getByRole('heading', { level: 1, name: 'Record a Volunteer' }),
        ).toBeVisible()
        await page.getByRole('searchbox', { name: 'Name, email or phone' }).fill(name)
        await page.getByRole('button', { name: 'Search' }).click()
        await expect(page.getByRole('list', { name: 'People found' })).toBeVisible()
        expect(await axeViolations(page), 'the lookup results').toEqual([])
        await page.getByRole('button', { name: 'Add someone new' }).click()
        expect(await axeViolations(page), 'the intake form').toEqual([])

        await page.goto(`/relationships/volunteers/${personId}`)
        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
        expect(await axeViolations(page), 'the record').toEqual([])
        await page.getByRole('button', { name: 'Change to Active' }).click()
        await expect(page.getByRole('dialog', { name: `Change ${name} to Active?` })).toBeVisible()
        expect(await axeViolations(page), 'the status dialog').toEqual([])
        await page.keyboard.press('Escape')
        await page.getByRole('button', { name: 'Edit contact details' }).click()
        expect(await axeViolations(page), 'the contact details form').toEqual([])
        await page.getByRole('button', { name: 'Cancel' }).click()
        await page.getByRole('button', { name: /Delete this volunteer/ }).click()
        await expect(page.getByRole('dialog')).toBeVisible()
        expect(await axeViolations(page), 'the delete dialog').toEqual([])
        await page.keyboard.press('Escape')

        await page.goto(`/people/${personId}`)
        await expect(page.getByRole('tablist', { name: 'Relationships' })).toBeVisible()
        expect(await axeViolations(page), 'the Person record with its tabs').toEqual([])
      } finally {
        await page.context().close()
        await adminRemove(browser, baseURL, 'volunteers', personId)
      }
    })
  })
}

test.describe('layout', () => {
  test('no relationship page scrolls sideways at 320, 375 and 1280px, even with very long names and details', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL, 'plain-guardian')
    const name = `E2E ${'L'.repeat(120)}${tag()}`
    const personId = await makeVolunteer(
      page,
      name,
      { interests: `I${'n'.repeat(300)}`, availability: 'Weekends' },
      'pending',
    )
    try {
      for (const width of [320, 375, 1280]) {
        await page.setViewportSize({ width, height: 800 })
        for (const path of [
          '/relationships/volunteers',
          '/relationships/guardians',
          '/relationships/volunteers/new',
          `/relationships/volunteers/${personId}`,
          `/people/${personId}`,
        ]) {
          await page.goto(path)
          await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
          expect(
            await horizontalOverflow(page),
            `${path} at ${String(width)}px`,
          ).toBeLessThanOrEqual(0)
        }
        // And the dialogs, which are the widest thing on the record page.
        await page.goto(`/relationships/volunteers/${personId}`)
        await page.getByRole('button', { name: 'Change to Active' }).click()
        await expect(page.getByRole('dialog')).toBeVisible()
        expect(
          await horizontalOverflow(page),
          `the dialog at ${String(width)}px`,
        ).toBeLessThanOrEqual(0)
        await page.keyboard.press('Escape')
      }
    } finally {
      await page.context().close()
      await adminRemove(browser, baseURL, 'volunteers', personId)
    }
  })

  test('the whole intake can be done with the keyboard alone', async ({ browser, baseURL }) => {
    const page = await signedIn(browser, baseURL, 'plain-guardian')
    const name = `E2E Keyboard ${tag()}`
    await page.goto('/relationships/volunteers/new')
    await expect(page.getByRole('heading', { level: 1, name: 'Record a Volunteer' })).toBeVisible()
    await page.getByRole('searchbox', { name: 'Name, email or phone' }).focus()
    await page.keyboard.type(name)
    await page.keyboard.press('Enter')
    await expect(page.getByText(/No one matches/)).toBeVisible()
    await page.getByRole('button', { name: 'Add someone new' }).focus()
    await page.keyboard.press('Enter')
    await page.getByLabel('Display name').focus()
    await page.keyboard.type(name)
    await page.getByLabel('Initial status').focus()
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('ArrowDown')
    await page.getByRole('button', { name: 'Review and record' }).focus()
    await page.keyboard.press('Enter')
    const review = page.getByRole('dialog', { name: `Record ${name} as a Volunteer?` })
    await expect(review).toBeVisible()
    // Focus is inside the dialog, and Escape closes it without recording.
    await page.keyboard.press('Escape')
    await expect(review).toHaveCount(0)
    expect(
      (await apiFrom(page, 'GET', `${ROOT}/volunteers/candidates?q=${encodeURIComponent(name)}`))
        .body,
    ).toMatchObject({ data: [] })
    await page.context().close()
  })
})
