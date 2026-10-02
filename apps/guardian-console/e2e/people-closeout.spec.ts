import { expect, test, type Browser, type Page } from '@playwright/test'

import { axeViolations, inTheme, THEMES } from './axe.ts'
import { aDemoPersonId, apiFrom, signedInAs } from './support.ts'

// The CRM / People closeout (ADR 0034, G1 Work Package 6), in real Chromium through the real gateway, over the demo data
// (`CrmDemoSeeder`, which `./flow test e2e` runs first). people.spec.ts already proves the working journeys (add, find, correct,
// contact methods, notes, tags). This file adds what they leave out: the demo dataset itself, the duplicate-advice case against
// it, a real history's paging, what each capability sees, what the wire carries, and the accessibility, focus and narrow-screen
// behaviour of the states that matter.
//
// Isolation: journeys that only READ use the demo Persons by name. Any journey that CHANGES something makes its own Person with a
// random name, and a journey that touches a shared fact (the demo email of the advice case) puts it back.

test.describe.configure({ timeout: 120_000 })

/** The seeded Guardian, already on the Console (an API lookup from a blank page has no origin to ask). */
async function guardianOn(
  browser: Browser,
  baseURL: string | undefined,
  as: 'plain-guardian' | 'admin-read' = 'plain-guardian',
): Promise<Page> {
  const page = await signedInAs(browser, baseURL ?? '', as)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  return page
}

const ALLOWED_RECORD_KEYS = new Set(['person', 'profile', 'contact_methods', 'tags'])

/** Every key at any depth of a JSON value. */
function keysOf(value: unknown, into = new Set<string>()): Set<string> {
  if (Array.isArray(value)) value.forEach((entry) => keysOf(entry, into))
  else if (typeof value === 'object' && value !== null) {
    for (const [key, entry] of Object.entries(value)) {
      into.add(key)
      keysOf(entry, into)
    }
  }
  return into
}

/**
 * A Guardian whose `/me` says they hold all but these capabilities. The server is untouched: this is only what the Console is
 * TOLD. (`/me` is read through the browser, which resolves the development host; Playwright's own network stack cannot.)
 */
async function reportedAs(page: Page, drop: string[]): Promise<void> {
  const snapshot = (await apiFrom(page, 'GET', '/api/v1/me')).body as { capabilities: string[] }
  const body = { ...snapshot, capabilities: snapshot.capabilities.filter((c) => !drop.includes(c)) }
  await page.route('**/api/v1/me', (route) => route.fulfill({ json: body }))
}

test.describe('the demo data, read as a Guardian reads it', () => {
  test('the directory finds a demo Person by name, email or phone, and shows only what is recorded for them', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    await guardian.goto('/people')
    const table = guardian.getByRole('table', { name: 'People' })

    for (const [text, expected] of [
      ['Hale', 'Marguerite Hale'],
      ['kenji.watanabe@studio', 'Kenji Watanabe'],
      ['010 0173', 'Priya Raman'],
    ] as const) {
      await guardian.getByRole('searchbox', { name: 'Name, email or phone' }).fill(text)
      await guardian.getByRole('button', { name: 'Search' }).click()
      await expect(table.getByRole('link', { name: expected })).toBeVisible()
    }

    await guardian.getByRole('searchbox').fill('Marguerite Hale')
    await guardian.getByRole('button', { name: 'Search' }).click()
    const row = table.getByRole('row').filter({ hasText: 'Marguerite Hale' })
    await expect(row).toContainText('marguerite.hale@example.org')
    await expect(row).toContainText('555 010 0141')
    await expect(row).not.toContainText(/Facilitator|Partner/) // tags are on the record, not the directory
  })

  test('a sparse Person is a name and nothing else, and says so without inventing anything', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    await guardian.goto(`/people/${await aDemoPersonId(guardian, 'Ruth Abernathy')}`)

    await expect(guardian.getByRole('heading', { level: 1, name: 'Ruth Abernathy' })).toBeVisible()
    await expect(guardian.getByText('No contact methods recorded.')).toBeVisible()
    await expect(guardian.getByText('No tags.')).toBeVisible()
    await expect(guardian.getByText('No notes or interactions yet.')).toBeVisible()
    await expect(guardian.getByText('Not recorded')).toHaveCount(2)
  })

  test('a Person with more notes than a page holds is paged by the server, newest first, by keyboard', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    await guardian.goto(`/people/${await aDemoPersonId(guardian, 'Marguerite Hale')}`)
    const history = guardian.getByRole('list', { name: 'Notes and interactions' })

    await expect(history.getByRole('listitem')).toHaveCount(10)
    await expect(
      guardian.getByRole('status').filter({ hasText: 'Page 1 of 2 (12 notes)' }),
    ).toBeVisible()
    await expect(history.getByRole('listitem').first()).toContainText(
      'Walked through the room set-up',
    ) // the latest
    await expect(history.getByText('Coffee to plan the winter movement series')).toHaveCount(0) // the oldest is not here

    // More than one author, and a correction that says who made it, on the first page.
    const authors = await history.getByText(/^Recorded by /).allTextContents()
    expect(new Set(authors.map((a) => a.replace(/ · .*/, ''))).size).toBeGreaterThan(1)
    await expect(
      history.getByRole('listitem').filter({ hasText: 'and her own mats' }),
    ).toContainText('Last edited by')

    await guardian.getByRole('button', { name: 'Next' }).focus()
    await guardian.keyboard.press('Enter')
    await expect(history.getByRole('listitem')).toHaveCount(2)
    await expect(history.getByText('Coffee to plan the winter movement series')).toBeVisible()
    await expect(guardian.getByRole('button', { name: 'Next' })).toBeDisabled()
    await guardian.getByRole('button', { name: 'Previous' }).focus()
    await guardian.keyboard.press('Enter')
    await expect(history.getByRole('listitem')).toHaveCount(10)
  })

  test('the demo tags are an ordinary, editable list that no part of the Console treats as special', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    await guardian.goto('/people/tags')
    const list = guardian.getByRole('list', { name: 'Tags' })

    for (const tag of [
      'Lead',
      'Partner',
      'Facilitator',
      'Performer',
      'Vendor',
      'Volunteer Interest',
      'Artist',
    ]) {
      await expect(list.getByRole('listitem').filter({ hasText: tag })).toBeVisible()
    }
    // Every one of them has the same controls as any tag a Guardian makes, and nothing else.
    await expect(guardian.getByRole('button', { name: 'Rename Volunteer Interest' })).toBeVisible()
    await expect(guardian.getByRole('button', { name: 'Delete Volunteer Interest' })).toBeVisible()
    await expect(guardian.getByRole('main')).not.toContainText(/permission|colou?r|status|role/i)
  })
})

test.describe('registering someone who may already be there', () => {
  test('a shared CRM email is advice: the candidate can be inspected, a distinct Person is added on an explicit choice, and the demo Person is untouched', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    const name = `E2E Moreau ${crypto.randomUUID().slice(0, 8)}`
    const hannahId = await aDemoPersonId(guardian, 'Hannah Moreau')

    await guardian.goto('/people/new')
    await guardian.getByLabel('Display name').fill(name)
    await guardian.getByLabel('Email').fill('Hannah.Moreau@example.org') // the demo email, written differently
    await guardian.getByRole('button', { name: 'Add person' }).click()

    // Advice, and nothing created.
    const advice = guardian.getByRole('list', { name: 'Possible duplicates' })
    await expect(advice).toContainText('Hannah Moreau')
    await expect(advice).toContainText('same email')
    await expect(guardian.getByText(/Nothing has been added yet/)).toBeVisible()
    await expect(guardian.getByRole('heading', { level: 1, name: 'Person added' })).toHaveCount(0)
    const found = await apiFrom(
      guardian,
      'GET',
      `/api/v1/admin/people?q=${encodeURIComponent(name)}`,
    )
    expect((found.body as { data: unknown[] }).data).toHaveLength(0)

    // Inspect the candidate: it opens as its own record, in its own tab, and the form here is still filled in.
    const popup = guardian.waitForEvent('popup')
    await advice.getByRole('link', { name: 'Hannah Moreau' }).click()
    const candidate = await popup
    await expect(candidate.getByRole('heading', { level: 1, name: 'Hannah Moreau' })).toBeVisible()
    await candidate.close()
    await expect(guardian.getByLabel('Display name')).toHaveValue(name)

    // Explicit confirmation: a distinct Person is added, and Hannah is exactly as she was.
    await guardian.getByRole('button', { name: 'This is a different person: add anyway' }).click()
    await expect(guardian.getByRole('heading', { level: 1, name: 'Person added' })).toBeVisible()
    await expect(
      guardian.getByRole('status').filter({ hasText: 'was added to the directory' }),
    ).toBeFocused() // the outcome is announced and focused
    const hannah = await apiFrom(guardian, 'GET', `/api/v1/admin/people/${hannahId}`)
    const methods = (hannah.body as { contact_methods: { value: string }[] }).contact_methods.map(
      (m) => m.value,
    )
    expect(methods).toEqual(['hannah.moreau@example.org', '555 010 0199'])

    // Put the shared fact back: remove the demo email from the new Person, so the next run meets the same single candidate.
    await guardian.getByRole('link', { name: 'Open the person' }).click()
    await guardian.getByRole('button', { name: 'Remove Hannah.Moreau@example.org' }).click()
    await guardian.getByRole('dialog').getByRole('button', { name: 'Remove' }).click()
    await expect(guardian.getByText('Hannah.Moreau@example.org was removed.')).toBeVisible()
    const holders = await apiFrom(
      guardian,
      'GET',
      '/api/v1/admin/people?q=hannah.moreau%40example.org',
    )
    expect(
      (holders.body as { data: { display_name: string }[] }).data.map((p) => p.display_name),
    ).toEqual(['Hannah Moreau'])
  })
})

test.describe('what each capability sees', () => {
  // The Console follows what `/me` reports, and the server decides every request. The server's own refusals, one capability
  // at a time, are proved by PeopleAccessControlTest; the browser shows that the screens follow the capabilities, and that no
  // control is offered for something the Guardian may not do. (No role holds one CRM capability without the other, so the
  // browser can only be TOLD that: the Account's real capabilities are untouched.)
  test('view without manage can browse, open and read everything, and is offered nothing to change', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    await reportedAs(guardian, ['crm.people.manage'])
    const writes: string[] = []
    guardian.on('request', (r) => {
      if (r.method() !== 'GET' && r.url().includes('/api/v1/admin/'))
        writes.push(`${r.method()} ${r.url()}`)
    })

    await guardian.goto('/people')
    await expect(guardian.getByRole('table', { name: 'People' })).toBeVisible()
    await expect(guardian.getByRole('link', { name: 'Add person' })).toHaveCount(0)

    await guardian.goto(`/people/${await aDemoPersonId(guardian, 'Marguerite Hale')}`)
    await expect(guardian.getByRole('list', { name: 'Notes and interactions' })).toBeVisible()
    await expect(guardian.getByRole('list', { name: 'Tags' })).toContainText('Facilitator')
    await expect(guardian.getByRole('list', { name: 'Contact methods' })).toContainText(
      'marguerite.hale@example.org',
    )
    for (const name of [
      /Edit profile/,
      /Add contact method/,
      /Record a note/,
      /Edit tags/,
      /^Edit /,
      /^Remove /,
      /Make .* primary/,
    ]) {
      await expect(guardian.getByRole('button', { name })).toHaveCount(0)
    }
    await expect(guardian.getByRole('link', { name: 'Manage the list of tags' })).toHaveCount(0)

    await guardian.goto('/people/tags')
    await expect(guardian.getByRole('list', { name: 'Tags' })).toBeVisible()
    await expect(guardian.getByRole('form', { name: 'Create a tag' })).toHaveCount(0)
    await expect(guardian.getByRole('button', { name: /^Rename |^Delete / })).toHaveCount(0)

    await guardian.goto('/people/new')
    await expect(guardian.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    expect(writes).toEqual([])
  })

  test('manage without view gains no way in to read People: the list and a record are refused and never requested', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    const demo = await aDemoPersonId(guardian, 'Marguerite Hale') // looked up before the Console is told anything else
    await reportedAs(guardian, ['crm.people.view'])
    const reads: string[] = []
    guardian.on('request', (r) => {
      if (r.method() === 'GET' && /\/api\/v1\/admin\/(people|contact-tags)/.test(r.url()))
        reads.push(r.url())
    })

    for (const path of ['/people', `/people/${demo}`, '/people/tags']) {
      await guardian.goto(path)
      await expect(guardian.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    }
    expect(reads).toEqual([])
    // What manage alone reaches is the form, and the navigation says so.
    await guardian.goto('/')
    await expect(
      guardian.getByRole('navigation', { name: 'Console' }).getByRole('link', { name: 'People' }),
    ).toHaveAttribute('href', '/people/new')
  })

  test('an Account with no CRM access cannot enter People, in the Console or at the API', async ({
    page,
  }) => {
    await page.goto('/login')
    await page.getByLabel('Email address').fill('e2e.noaccess@example.org')
    await page.getByLabel('Password', { exact: true }).fill('e2e-noaccess-password-not-a-secret')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible()

    for (const path of ['/people', '/people/new', '/people/tags']) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()
      await expect(page.getByRole('table')).toHaveCount(0)
    }
    for (const [method, path, body] of [
      ['GET', '/api/v1/admin/people', undefined],
      ['GET', '/api/v1/admin/contact-tags', undefined],
      ['POST', '/api/v1/admin/people', { display_name: 'Nobody' }],
    ] as const) {
      expect((await apiFrom(page, method, path, body)).status, `${method} ${path}`).toBe(403)
    }
  })
})

test.describe('what the wire carries', () => {
  test("a CRM record, its history, the directory and the tag list carry only CRM data and a Person's name", async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    const me = (await apiFrom(guardian, 'GET', '/api/v1/me')).body as {
      account: { id: string; email: string }
      person: { id: string }
    }
    const demo = await aDemoPersonId(guardian, 'Marguerite Hale')

    const record = await apiFrom(guardian, 'GET', `/api/v1/admin/people/${demo}`)
    const history = await apiFrom(guardian, 'GET', `/api/v1/admin/people/${demo}/interactions`)
    const directory = await apiFrom(guardian, 'GET', '/api/v1/admin/people?q=Marguerite')
    const tags = await apiFrom(guardian, 'GET', '/api/v1/admin/contact-tags')
    const own = await apiFrom(guardian, 'GET', `/api/v1/admin/people/${me.person.id}`) // a Person who HAS an Account

    for (const response of [record, history, directory, tags, own])
      expect(response.status).toBe(200)

    // The shapes are exactly the documented ones, at every depth.
    expect(new Set(Object.keys(record.body as object))).toEqual(ALLOWED_RECORD_KEYS)
    expect([...keysOf(record.body)].sort()).toEqual(
      [
        'affiliation',
        'contact_methods',
        'created_at',
        'display_name',
        'how_we_know',
        'id',
        'is_primary',
        'kind',
        'label',
        'name',
        'person',
        'profile',
        'tags',
        'updated_at',
        'value',
      ].sort(),
    )
    expect([...keysOf(history.body)].sort()).toEqual(
      [
        'author',
        'body',
        'created_at',
        'data',
        'display_name',
        'id',
        'kind',
        'last_page',
        'meta',
        'occurred_at',
        'page',
        'per_page',
        'total',
        'updated_at',
        'updated_by',
      ].sort(),
    )
    expect([...keysOf(directory.body)].sort()).toEqual(
      [
        'data',
        'display_name',
        'id',
        'last_page',
        'meta',
        'name',
        'page',
        'per_page',
        'primary_email',
        'primary_phone',
        'tags',
        'total',
      ].sort(),
    )
    expect([...keysOf(tags.body)].sort()).toEqual(['data', 'id', 'name', 'person_count'].sort())

    // Nothing about ANY Account (its login, its id) appears in any of it, though two of its authors ARE operators. The accounts
    // are listed by an administrator, so the check is against every real login and id rather than a pattern.
    const admin = await guardianOn(browser, baseURL, 'admin-read')
    const accounts = (await apiFrom(admin, 'GET', '/api/v1/admin/accounts?per_page=100')).body as {
      data: { id: string; email: string }[]
    }
    expect(accounts.data.length).toBeGreaterThan(2)
    const everything = JSON.stringify([
      record.body,
      history.body,
      directory.body,
      tags.body,
      own.body,
    ]).toLowerCase()
    for (const account of accounts.data) {
      expect(everything, `Account ${account.email}`).not.toContain(account.email.toLowerCase())
      expect(everything, 'an Account id').not.toContain(account.id.toLowerCase())
    }
    expect(everything).not.toContain(me.account.email.toLowerCase())
    expect((own.body as { contact_methods: unknown[] }).contact_methods).toEqual([]) // an Account's login is not CRM data
  })

  test("an Account's login email finds nobody in the directory, and is not CRM data unless recorded as a contact method", async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    await guardian.goto('/')
    const me = (await apiFrom(guardian, 'GET', '/api/v1/me')).body as { account: { email: string } }

    const byLogin = await apiFrom(
      guardian,
      'GET',
      `/api/v1/admin/people?q=${encodeURIComponent(me.account.email)}`,
    )

    expect((byLogin.body as { data: unknown[] }).data).toEqual([])
  })
})

for (const theme of THEMES) {
  test.describe(`the People states pass axe in a real browser, contrast included (${theme})`, () => {
    test(`the directory, a record with its history, and the registration and advice states (${theme})`, async ({
      browser,
      baseURL,
    }) => {
      const guardian = await guardianOn(browser, baseURL)
      await inTheme(guardian, theme)
      const demo = await aDemoPersonId(guardian, 'Marguerite Hale')

      await guardian.goto('/people')
      await expect(guardian.getByRole('table', { name: 'People' })).toBeVisible()
      expect(await axeViolations(guardian), 'directory').toEqual([])

      await guardian
        .getByRole('searchbox', { name: 'Name, email or phone' })
        .fill('nobody matches this at all')
      await guardian.getByRole('button', { name: 'Search' }).click()
      await expect(guardian.getByText('No people match.')).toBeVisible()
      expect(await axeViolations(guardian), 'no match').toEqual([])

      await guardian.goto(`/people/${demo}`)
      await expect(guardian.getByRole('list', { name: 'Notes and interactions' })).toBeVisible()
      expect(await axeViolations(guardian), 'record with history').toEqual([])

      await guardian.goto('/people/new')
      await guardian.getByLabel('Display name').fill(`E2E Axe ${crypto.randomUUID().slice(0, 8)}`)
      await guardian.getByLabel('Email').fill('hannah.moreau@example.org')
      await guardian.getByRole('button', { name: 'Add person' }).click()
      await expect(guardian.getByRole('list', { name: 'Possible duplicates' })).toBeVisible()
      expect(await axeViolations(guardian), 'duplicate advice').toEqual([])
    })

    test(`the forms, the tag states and the confirmation dialogs, including an error (${theme})`, async ({
      browser,
      baseURL,
    }) => {
      const guardian = await guardianOn(browser, baseURL)
      await inTheme(guardian, theme)
      const tag = crypto.randomUUID().slice(0, 8)
      // Its own Person and tag, made through the API: this journey changes things.
      await guardian.goto('/')
      const person = (
        await apiFrom(guardian, 'POST', '/api/v1/admin/people', {
          display_name: `E2E Axe States ${tag}`,
        })
      ).body as { person: { id: string } }
      const made = (
        await apiFrom(guardian, 'POST', '/api/v1/admin/contact-tags', { name: `E2E axe ${tag}` })
      ).body as { id: string }
      await apiFrom(guardian, 'PUT', `/api/v1/admin/people/${person.person.id}/tags`, {
        tag_ids: [made.id],
      })
      await apiFrom(guardian, 'POST', `/api/v1/admin/people/${person.person.id}/interactions`, {
        body: 'A note to edit and remove.',
      })

      await guardian.goto(`/people/${person.person.id}`)
      await expect(guardian.getByRole('list', { name: 'Notes and interactions' })).toBeVisible()

      await guardian.getByRole('button', { name: 'Edit profile' }).click()
      await guardian.getByRole('button', { name: 'Add contact method' }).click()
      await guardian.getByRole('button', { name: 'Record a note' }).click()
      await guardian.getByRole('button', { name: /^Edit the note from / }).click()
      await guardian.getByRole('button', { name: 'Edit tags' }).click()
      await expect(guardian.getByRole('group', { name: 'Tags' })).toBeVisible()
      expect(await axeViolations(guardian), 'every form open at once').toEqual([])

      await guardian.reload()
      await guardian.getByRole('button', { name: /^Remove the note from / }).click()
      await expect(guardian.getByRole('dialog', { name: 'Remove this note?' })).toBeVisible()
      expect(await axeViolations(guardian), 'remove dialog').toEqual([])
      await guardian.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click()

      await guardian.goto('/people/tags')
      await expect(guardian.getByRole('list', { name: 'Tags' })).toBeVisible()
      await guardian.getByRole('button', { name: `Rename E2E axe ${tag}` }).click()
      expect(await axeViolations(guardian), 'rename form').toEqual([])
      await guardian.getByRole('button', { name: 'Cancel' }).click()
      await guardian.getByRole('button', { name: `Delete E2E axe ${tag}` }).click()
      await guardian.getByRole('dialog').getByRole('button', { name: 'Delete' }).click()
      await expect(guardian.getByRole('dialog').getByRole('alert')).toContainText('was not deleted')
      expect(await axeViolations(guardian), 'delete-in-use refusal inside its dialog').toEqual([])
    })
  })
}

test.describe('keyboard and focus', () => {
  test('a confirmation opens on its heading, puts Cancel first, and Escape returns focus to what opened it', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    await guardian.goto('/')
    const person = (
      await apiFrom(guardian, 'POST', '/api/v1/admin/people', {
        display_name: `E2E Keys ${crypto.randomUUID().slice(0, 8)}`,
      })
    ).body as { person: { id: string } }
    await apiFrom(guardian, 'POST', `/api/v1/admin/people/${person.person.id}/interactions`, {
      body: 'Remove me with the keyboard.',
    })
    await guardian.goto(`/people/${person.person.id}`)

    const trigger = guardian.getByRole('button', { name: /^Remove the note from / })
    await trigger.focus()
    await guardian.keyboard.press('Enter')
    const dialog = guardian.getByRole('dialog', { name: 'Remove this note?' })
    await expect(dialog.getByRole('heading', { name: 'Remove this note?' })).toBeFocused()
    await guardian.keyboard.press('Tab')
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused()

    await guardian.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)
    await expect(trigger).toBeFocused()
    await expect(guardian.getByText('Remove me with the keyboard.')).toBeVisible() // nothing was removed
  })

  test('a refused submission is announced and tied to its field, and tags are ticked and saved without a mouse', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    const tag = crypto.randomUUID().slice(0, 8)
    await guardian.goto('/')
    const person = (
      await apiFrom(guardian, 'POST', '/api/v1/admin/people', {
        display_name: `E2E Keys Two ${tag}`,
      })
    ).body as { person: { id: string } }
    await apiFrom(guardian, 'POST', '/api/v1/admin/contact-tags', { name: `E2E keys ${tag}` })
    await guardian.goto(`/people/${person.person.id}`)

    // A note dated in the future is refused by the server, beside the field it concerns.
    await guardian.getByRole('button', { name: 'Record a note' }).click()
    await guardian.getByRole('textbox', { name: 'Details' }).fill('From the future.')
    await guardian.getByLabel('When it happened').fill('2099-01-01T09:00')
    await guardian
      .getByRole('form', { name: 'Record a note' })
      .getByRole('button', { name: 'Record' })
      .click()
    const when = guardian.getByLabel('When it happened')
    await expect(when).toHaveAttribute('aria-invalid', 'true')
    await expect(when).toHaveAccessibleDescription(/future/)
    await guardian.getByRole('button', { name: 'Cancel' }).click()

    await guardian.getByRole('button', { name: 'Edit tags' }).click()
    const box = guardian.getByRole('checkbox', { name: `E2E keys ${tag}` })
    await box.focus()
    await guardian.keyboard.press('Space')
    await expect(box).toBeChecked()
    await guardian.getByRole('button', { name: 'Save tags' }).focus()
    await guardian.keyboard.press('Enter')
    await expect(guardian.getByText('Tags saved.')).toBeVisible()
    await expect(guardian.getByRole('list', { name: 'Tags' })).toContainText(`E2E keys ${tag}`)
  })
})

test.describe('on a narrow screen', () => {
  test('a long note wraps, the forms fit, and a dialog stays inside the screen at 320px', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    await guardian.setViewportSize({ width: 320, height: 700 })
    const longNote = await aDemoPersonId(guardian, 'Daniel Okoye')
    await guardian.goto(`/people/${longNote}`)
    await expect(guardian.getByText(/Nothing is promised either way/)).toBeVisible()

    const sideways = () =>
      guardian.evaluate(() => {
        const root = (
          globalThis as unknown as {
            document: { documentElement: { scrollWidth: number; clientWidth: number } }
          }
        ).document.documentElement
        return root.scrollWidth - root.clientWidth
      })
    expect(await sideways(), 'the record with a long note').toBeLessThanOrEqual(0)

    await guardian.getByRole('button', { name: 'Record a note' }).click()
    await guardian.getByRole('button', { name: 'Edit tags' }).click()
    expect(await sideways(), 'with the note and tag forms open').toBeLessThanOrEqual(0)
    await guardian
      .getByRole('form', { name: 'Record a note' })
      .getByRole('button', { name: 'Cancel' })
      .click()
    await guardian
      .getByRole('form', { name: 'Edit tags' })
      .getByRole('button', { name: 'Cancel' })
      .click()

    await guardian.getByRole('button', { name: /^Remove the meeting from / }).click()
    const dialog = guardian.getByRole('dialog', { name: 'Remove this meeting?' })
    await expect(dialog).toBeVisible()
    const box = await dialog.boundingBox()
    expect(box).not.toBeNull()
    expect(box?.x ?? -1).toBeGreaterThanOrEqual(0)
    expect((box?.x ?? 0) + (box?.width ?? 999)).toBeLessThanOrEqual(320)
    expect(await sideways(), 'with the removal dialog open').toBeLessThanOrEqual(0)
    await dialog.getByRole('button', { name: 'Cancel' }).click() // nothing was removed
    await expect(guardian.getByText(/Nothing is promised either way/)).toBeVisible()
  })
})
