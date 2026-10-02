import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { operator, serveOperator } from '../../test/admin.ts'
import { expectNoAxeViolations } from '../../test/a11y.ts'
import { json } from '../../test/fakeApi.ts'
import {
  interactionsPage,
  invalidContactInput,
  METHOD_EMAIL_ID,
  METHOD_EMAIL2_ID,
  METHOD_PHONE_ID,
  peoplePage,
  PERSON_ID,
  personNotFound,
  possibleDuplicate,
  wireListing,
  wireMethod,
  wirePerson,
} from '../../test/people.ts'
import { renderApp } from '../../test/renderApp.tsx'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const VIEW = ['console.access', 'crm.people.view']
const MANAGE = ['console.access', 'crm.people.view', 'crm.people.manage']

const LIST = 'GET /api/v1/admin/people' as const
const RECORD = `GET /api/v1/admin/people/${PERSON_ID}` as const
const PATCH_PERSON = `PATCH /api/v1/admin/people/${PERSON_ID}` as const
const INTERACTIONS = `GET /api/v1/admin/people/${PERSON_ID}/interactions` as const
const METHODS = `POST /api/v1/admin/people/${PERSON_ID}/contact-methods` as const

/** The item at `index`, or a failure that says so (the repository allows neither a cast nor a non-null assertion). */
function nth(list: HTMLElement[], index: number): HTMLElement {
  const item = list[index]
  if (item === undefined) throw new Error(`There is no item ${String(index)}.`)
  return item
}

/** The directory requests (they carry a query string, which `callsTo` does not match). */
const listCalls = (api: { calls: { method: string; path: string }[] }) =>
  api.calls.filter((c) => c.method === 'GET' && c.path.startsWith('/api/v1/admin/people?'))

/** The page's own content, without the shell (whose account menu legitimately talks about the signed-in Account). */
const pageText = () => document.querySelector('[data-page-width]')?.textContent ?? ''

const FORBIDDEN_WORDS =
  /account|login|sign-in email|membership|member since|role|capabilit|mfa|two-step|password|security|volunteer/i

describe('the People directory', () => {
  it('lists people with the contact methods recorded for them, each linking to its record', async () => {
    const api = serveOperator(operator(VIEW))
    api.on(
      LIST,
      json(
        peoplePage([
          wireListing(),
          wireListing({
            id: '01J00000000000000000PERSN2',
            display_name: 'Grace Hopper',
            primary_email: null,
            primary_phone: '555 010 0100',
          }),
        ]),
      ),
    )
    renderApp('/people')

    const table = await screen.findByRole('table', { name: 'People' })
    expect(within(table).getByRole('link', { name: 'Ada Lovelace' })).toHaveAttribute(
      'href',
      `/people/${PERSON_ID}`,
    )
    expect(within(table).getByText('ada@example.org')).toBeInTheDocument()
    expect(within(table).getByText('555 010 0100')).toBeInTheDocument()
    expect(within(table).getByRole('columnheader', { name: 'Primary email' })).toBeInTheDocument()
    // Tags arrive on the wire and are not a WP4 feature: not shown, and nothing advertises them.
    expect(pageText()).not.toMatch(/Partner|tag/i)
    expect(pageText()).not.toMatch(FORBIDDEN_WORDS)
  })

  it("asks for the first page, in the server's own order, with no search", async () => {
    const api = serveOperator(operator(VIEW))
    api.on(LIST, json(peoplePage([wireListing()])))
    renderApp('/people')
    await screen.findByRole('table', { name: 'People' })

    expect(listCalls(api).map((c) => c.path)).toEqual(['/api/v1/admin/people?page=1&per_page=25'])
  })

  it('searches only when submitted, not on every keystroke, and says what was searched', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEW))
    api.on(LIST, json(peoplePage([wireListing()])))
    renderApp('/people')
    await screen.findByRole('table', { name: 'People' })

    await user.type(screen.getByRole('searchbox', { name: 'Name, email or phone' }), '  ada  ')
    expect(listCalls(api)).toHaveLength(1) // typing asked for nothing

    await user.click(screen.getByRole('button', { name: 'Search' }))
    await waitFor(() => {
      expect(listCalls(api)).toHaveLength(2)
    })
    expect(listCalls(api)[1]?.path).toBe('/api/v1/admin/people?page=1&per_page=25&q=ada') // trimmed
  })

  it('pages with the server, and a new search returns to page 1 rather than staying on a page that may not exist', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEW))
    api.on(LIST, (call) => {
      const page = new URL(call.path, 'http://x').searchParams.get('page')
      return json(
        peoplePage([wireListing({ display_name: `Person on page ${page ?? '?'}` })], {
          page: Number(page),
          total: 60,
          last_page: 3,
        }),
      )
    })
    renderApp('/people')
    await screen.findByRole('link', { name: 'Person on page 1' })
    expect(screen.getByRole('status', { name: '' })).toHaveTextContent('Page 1 of 3 (60 people)')

    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(await screen.findByRole('link', { name: 'Person on page 2' })).toBeInTheDocument()
    expect(listCalls(api).at(-1)?.path).toContain('page=2')

    await user.type(screen.getByRole('searchbox'), 'grace')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    await screen.findByRole('link', { name: 'Person on page 1' })
    expect(listCalls(api).at(-1)?.path).toBe('/api/v1/admin/people?page=1&per_page=25&q=grace')
  })

  it('says so when there are no people, and, differently, when a search finds none', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEW))
    api.on(LIST, json(peoplePage([])))
    renderApp('/people')

    expect(await screen.findByText('There are no people yet.')).toBeInTheDocument()
    await user.type(screen.getByRole('searchbox'), 'nobody')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    expect(await screen.findByText('No people match.')).toBeInTheDocument()
  })

  it('recovers from a failed load without leaving the page', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEW))
    let healthy = false
    api.on(LIST, () =>
      healthy ? json(peoplePage([wireListing()])) : json({ message: 'boom SQLSTATE[42S02]' }, 500),
    )
    renderApp('/people')

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(
      'The service is temporarily unavailable. Try again in a moment.',
    )
    expect(alert).not.toHaveTextContent(/SQLSTATE|boom/) // the server's words never reach the screen

    healthy = true
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('link', { name: 'Ada Lovelace' })).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('offers "Add person" to crm.people.manage only', async () => {
    const api = serveOperator(operator(VIEW))
    api.on(LIST, json(peoplePage([wireListing()])))
    const view = renderApp('/people')
    await screen.findByRole('table', { name: 'People' })
    expect(screen.queryByRole('link', { name: 'Add person' })).not.toBeInTheDocument()
    view.unmount()

    const managing = serveOperator(operator(MANAGE))
    managing.on(LIST, json(peoplePage([wireListing()])))
    renderApp('/people')
    expect(await screen.findByRole('link', { name: 'Add person' })).toHaveAttribute(
      'href',
      '/people/new',
    )
  })

  it('refuses the page, and asks the server for nothing, without crm.people.view: manage does not stand in for it', async () => {
    const api = serveOperator(operator(['console.access', 'crm.people.manage']))
    renderApp('/people')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
    ).toBeInTheDocument()
    expect(api.calls.filter((c) => c.path.startsWith('/api/v1/admin/people'))).toHaveLength(0)
  })

  it('shows People in the navigation by capability, and never infers view from manage', async () => {
    serveOperator(operator(VIEW))
    const view = renderApp('/')
    expect(await screen.findByRole('link', { name: 'People' })).toHaveAttribute('href', '/people')
    view.unmount()

    serveOperator(operator(['console.access', 'crm.people.manage']))
    renderApp('/')
    await screen.findByRole('heading', { level: 1, name: 'Overview' })
    // Manage alone still leads to a page it may use: the form, not the list.
    expect(screen.getByRole('link', { name: 'People' })).toHaveAttribute('href', '/people/new')
  })
})

describe("a Person's record", () => {
  async function openRecord(capabilities = MANAGE, record: Record<string, unknown> = wirePerson()) {
    const api = serveOperator(operator(capabilities))
    api.on(RECORD, () => json(record))
    renderApp(`/people/${PERSON_ID}`)
    await screen.findByRole('heading', {
      level: 1,
      name: (record.person as { display_name: string }).display_name,
    })
    return api
  }

  it('shows the name, the profile and the contact methods, with the primary stated in words', async () => {
    await openRecord(
      MANAGE,
      wirePerson({
        contact_methods: [
          wireMethod({ label: 'home' }),
          wireMethod({ id: METHOD_EMAIL2_ID, value: 'ada.work@example.org', is_primary: false }),
          wireMethod({
            id: METHOD_PHONE_ID,
            kind: 'phone',
            value: '555 010 0100',
            is_primary: true,
          }),
        ],
      }),
    )

    expect(screen.getByText('Met at the spring workshop')).toBeInTheDocument()
    expect(screen.getByText('Analytical Guild')).toBeInTheDocument()
    const list = screen.getByRole('list', { name: 'Contact methods' })
    const rows = within(list).getAllByRole('listitem')
    expect(rows).toHaveLength(3)
    expect(within(nth(rows, 0)).getByText('ada@example.org')).toBeInTheDocument()
    expect(within(nth(rows, 0)).getByText('home')).toBeInTheDocument()
    expect(within(nth(rows, 0)).getByText('Primary')).toBeInTheDocument()
    expect(within(nth(rows, 1)).queryByText('Primary')).not.toBeInTheDocument()
    expect(within(nth(rows, 2)).getByText('Phone')).toBeInTheDocument()
    expect(within(nth(rows, 2)).getByText('Primary')).toBeInTheDocument() // one per kind
  })

  it('shows nothing about an Account, access, Membership or security', async () => {
    await openRecord()

    expect(pageText()).not.toMatch(FORBIDDEN_WORDS)
  })

  it('says "Not recorded" for a sparse record rather than inventing content', async () => {
    await openRecord(
      VIEW,
      wirePerson({
        profile: { how_we_know: null, affiliation: null, updated_at: null },
        contact_methods: [],
      }),
    )

    expect(screen.getAllByText('Not recorded')).toHaveLength(2)
    expect(screen.getByText('No contact methods recorded.')).toBeInTheDocument()
  })

  it('reads only: a view-only Guardian is offered no way to change anything', async () => {
    const api = await openRecord(VIEW)

    for (const name of [
      /Edit profile/,
      /Add contact method/,
      /Make .* primary/,
      /^Edit /,
      /^Remove /,
    ]) {
      expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    }
    expect(api.calls.filter((c) => c.method !== 'GET' && c.path.includes('/admin/people'))).toEqual(
      [],
    )
  })

  it('offers the changes to crm.people.manage', async () => {
    await openRecord(
      MANAGE,
      wirePerson({
        contact_methods: [
          wireMethod(),
          wireMethod({ id: METHOD_EMAIL2_ID, value: 'b@example.org', is_primary: false }),
        ],
      }),
    )

    expect(screen.getByRole('button', { name: 'Edit profile' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add contact method' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Edit ada@example.org' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remove b@example.org' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Make b@example.org primary' })).toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Make ada@example.org primary' }),
    ).not.toBeInTheDocument() // already primary
  })

  it('explains a Person that does not exist, in its own words, and offers the way back', async () => {
    const api = serveOperator(operator(VIEW))
    api.on(RECORD, personNotFound())
    renderApp(`/people/${PERSON_ID}`)

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'That person or contact method could not be found.',
    )
    expect(screen.getByRole('link', { name: 'Back to people' })).toHaveAttribute('href', '/people')
  })

  it('has no structural accessibility violation, with a form open', async () => {
    const user = userEvent.setup()
    await openRecord()
    await user.click(screen.getByRole('button', { name: 'Edit profile' }))
    await user.click(screen.getByRole('button', { name: 'Add contact method' }))

    expect(screen.getByRole('form', { name: 'Edit profile' })).toBeInTheDocument()
    expect(screen.getByRole('form', { name: 'Add contact method' })).toBeInTheDocument()
    await expectNoAxeViolations()
  })
})

describe('editing the profile', () => {
  async function open() {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    api.on(RECORD, () => json(wirePerson()))
    api.on(INTERACTIONS, json(interactionsPage([])))
    renderApp(`/people/${PERSON_ID}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: 'Edit profile' }))
    return { user, api }
  }

  it('starts from what is recorded and labels every field', async () => {
    await open()

    expect(screen.getByRole('textbox', { name: 'Display name' })).toHaveValue('Ada Lovelace')
    expect(screen.getByRole('textbox', { name: 'How we know them' })).toHaveValue(
      'Met at the spring workshop',
    )
    expect(screen.getByRole('textbox', { name: 'Affiliation' })).toHaveValue('Analytical Guild')
  })

  it('sends only the field that was changed, so what it did not touch cannot be overwritten', async () => {
    const { user, api } = await open()
    api.on(
      PATCH_PERSON,
      json({
        person: { id: PERSON_ID, display_name: 'Ada Lovelace' },
        profile: {
          how_we_know: 'Met at the spring workshop',
          affiliation: 'Difference Engine Co',
          updated_at: null,
        },
      }),
    )
    api.on(RECORD, () =>
      json(
        wirePerson({
          profile: {
            how_we_know: 'Met at the spring workshop',
            affiliation: 'Difference Engine Co',
            updated_at: null,
          },
        }),
      ),
    )

    const affiliation = screen.getByRole('textbox', { name: 'Affiliation' })
    await user.clear(affiliation)
    await user.type(affiliation, 'Difference Engine Co')
    await user.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(await screen.findByText('Difference Engine Co')).toBeInTheDocument()
    expect(api.callsTo(PATCH_PERSON)).toHaveLength(1)
    expect(api.callsTo(PATCH_PERSON)[0]?.body).toEqual({ affiliation: 'Difference Engine Co' }) // no display_name, no how_we_know
    expect(screen.getByText('Saved.')).toBeInTheDocument()
  })

  it("sends a renamed Person's name through the same request, and shows the new name", async () => {
    const { user, api } = await open()
    api.on(
      PATCH_PERSON,
      json({
        person: { id: PERSON_ID, display_name: 'Ada King' },
        profile: { how_we_know: null, affiliation: null, updated_at: null },
      }),
    )
    api.on(RECORD, () => json(wirePerson({ person: { id: PERSON_ID, display_name: 'Ada King' } })))

    const name = screen.getByRole('textbox', { name: 'Display name' })
    await user.clear(name)
    await user.type(name, '  Ada King ')
    await user.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Ada King' })).toBeInTheDocument()
    expect(api.callsTo(PATCH_PERSON)[0]?.body).toEqual({ display_name: 'Ada King' })
  })

  it('clears a profile field by sending null', async () => {
    const { user, api } = await open()
    api.on(
      PATCH_PERSON,
      json({
        person: { id: PERSON_ID, display_name: 'Ada Lovelace' },
        profile: { how_we_know: null, affiliation: 'Analytical Guild', updated_at: null },
      }),
    )

    await user.clear(screen.getByRole('textbox', { name: 'How we know them' }))
    await user.click(screen.getByRole('button', { name: 'Save profile' }))

    await waitFor(() => {
      expect(api.callsTo(PATCH_PERSON)).toHaveLength(1)
    })
    expect(api.callsTo(PATCH_PERSON)[0]?.body).toEqual({ how_we_know: null })
  })

  it('sends nothing when nothing changed', async () => {
    const { user, api } = await open()

    await user.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(await screen.findByText('Nothing was changed.')).toBeInTheDocument()
    expect(api.callsTo(PATCH_PERSON)).toHaveLength(0)
    expect(screen.queryByRole('form', { name: 'Edit profile' })).not.toBeInTheDocument()
  })

  it('ties a validation refusal to its field, keeps what was typed, and asked for no verification', async () => {
    const { user, api } = await open()
    api.on(PATCH_PERSON, invalidContactInput('affiliation', 'That is limited to 255 characters.'))

    const affiliation = screen.getByRole('textbox', { name: 'Affiliation' })
    await user.clear(affiliation)
    await user.type(affiliation, 'Too much')
    await user.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(await screen.findByText('That is limited to 255 characters.')).toBeInTheDocument()
    expect(affiliation).toHaveAccessibleDescription('That is limited to 255 characters.')
    expect(affiliation).toBeInvalid()
    expect(affiliation).toHaveValue('Too much')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument() // no step-up prompt for routine maintenance
  })

  it('cancels without sending anything', async () => {
    const { user, api } = await open()

    await user.type(screen.getByRole('textbox', { name: 'Affiliation' }), ' and more')
    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByRole('form', { name: 'Edit profile' })).not.toBeInTheDocument()
    expect(api.callsTo(PATCH_PERSON)).toHaveLength(0)
  })
})

describe('contact methods', () => {
  async function open(record: Record<string, unknown> = wirePerson()) {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    api.on(RECORD, () => json(record))
    api.on(INTERACTIONS, json(interactionsPage([])))
    renderApp(`/people/${PERSON_ID}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    return { user, api }
  }

  const TWO_EMAILS = wirePerson({
    contact_methods: [
      wireMethod({ label: 'home' }),
      wireMethod({ id: METHOD_EMAIL2_ID, value: 'ada.work@example.org', is_primary: false }),
    ],
  })

  it('adds a method; the first of its kind is primary, so it offers no primary choice', async () => {
    const { user, api } = await open(wirePerson({ contact_methods: [] }))
    api.on(METHODS, json(wireMethod(), 201))
    api.on(RECORD, () => json(wirePerson()))

    await user.click(screen.getByRole('button', { name: 'Add contact method' }))
    expect(
      screen.getByText(/This will be the primary email, as it is the first/),
    ).toBeInTheDocument()
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    await user.type(
      screen.getByRole('textbox', { name: 'Email or phone number' }),
      'ada@example.org',
    )
    await user.type(screen.getByRole('textbox', { name: 'Label' }), 'home')
    await user.click(screen.getByRole('button', { name: 'Add contact method', description: '' }))

    await waitFor(() => {
      expect(api.callsTo(METHODS)).toHaveLength(1)
    })
    expect(api.callsTo(METHODS)[0]?.body).toEqual({
      kind: 'email',
      value: 'ada@example.org',
      label: 'home',
      is_primary: false,
    })
    expect(await screen.findByText('Contact method added.')).toBeInTheDocument()
    expect(
      within(screen.getByRole('list', { name: 'Contact methods' })).getByText('Primary'),
    ).toBeInTheDocument()
  })

  it('asks for a later method to be primary only when that is chosen', async () => {
    const { user, api } = await open()
    api.on(
      METHODS,
      json(wireMethod({ id: METHOD_EMAIL2_ID, value: 'new@example.org', is_primary: true }), 201),
    )

    await user.click(screen.getByRole('button', { name: 'Add contact method' }))
    await user.type(
      screen.getByRole('textbox', { name: 'Email or phone number' }),
      'new@example.org',
    )
    await user.click(screen.getByRole('checkbox', { name: 'Make this the primary email' }))
    await user.click(
      within(screen.getByRole('form', { name: 'Add contact method' })).getByRole('button', {
        name: 'Add contact method',
      }),
    )

    await waitFor(() => {
      expect(api.callsTo(METHODS)).toHaveLength(1)
    })
    expect(api.callsTo(METHODS)[0]?.body).toMatchObject({
      value: 'new@example.org',
      is_primary: true,
    })
  })

  it('treats a phone as its own kind with its own primary', async () => {
    const { user, api } = await open()
    api.on(
      METHODS,
      json(wireMethod({ id: METHOD_PHONE_ID, kind: 'phone', value: '555 010 0100' }), 201),
    )

    await user.click(screen.getByRole('button', { name: 'Add contact method' }))
    await user.selectOptions(screen.getByRole('combobox', { name: 'Kind' }), 'phone')
    expect(
      screen.getByText(/This will be the primary phone, as it is the first/),
    ).toBeInTheDocument() // an email exists; no phone does
    await user.type(screen.getByRole('textbox', { name: 'Email or phone number' }), '555 010 0100')
    await user.click(
      within(screen.getByRole('form', { name: 'Add contact method' })).getByRole('button', {
        name: 'Add contact method',
      }),
    )

    await waitFor(() => {
      expect(api.callsTo(METHODS)[0]?.body).toMatchObject({ kind: 'phone', value: '555 010 0100' })
    })
  })

  it('says so when the person already has that contact method, and keeps the form open', async () => {
    const { user, api } = await open()
    api.on(
      METHODS,
      json(
        {
          message: 'That Person already has that contact method.',
          code: 'duplicate_contact_method',
        },
        409,
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Add contact method' }))
    await user.type(
      screen.getByRole('textbox', { name: 'Email or phone number' }),
      'ADA@example.org',
    )
    await user.click(
      within(screen.getByRole('form', { name: 'Add contact method' })).getByRole('button', {
        name: 'Add contact method',
      }),
    )

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'That person already has that contact method.',
    )
    expect(screen.getByRole('form', { name: 'Add contact method' })).toBeInTheDocument()
  })

  it("shows the server's validation message beside the value it concerns", async () => {
    const { user, api } = await open()
    api.on(METHODS, invalidContactInput('value', 'Enter an email address like name@example.org.'))

    await user.click(screen.getByRole('button', { name: 'Add contact method' }))
    const value = screen.getByRole('textbox', { name: 'Email or phone number' })
    await user.type(value, 'not an email')
    await user.click(
      within(screen.getByRole('form', { name: 'Add contact method' })).getByRole('button', {
        name: 'Add contact method',
      }),
    )

    expect(
      await screen.findByText('Enter an email address like name@example.org.'),
    ).toBeInTheDocument()
    expect(value).toBeInvalid()
  })

  it('edits the value or label, sending only what changed, and never offers to change the kind', async () => {
    const { user, api } = await open(TWO_EMAILS)
    const edit =
      `PATCH /api/v1/admin/people/${PERSON_ID}/contact-methods/${METHOD_EMAIL2_ID}` as const
    api.on(
      edit,
      json(
        wireMethod({
          id: METHOD_EMAIL2_ID,
          value: 'ada.work@example.org',
          label: 'work',
          is_primary: false,
        }),
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Edit ada.work@example.org' }))
    const form = screen.getByRole('form', { name: 'Edit ada.work@example.org' })
    expect(within(form).queryByRole('combobox')).not.toBeInTheDocument() // no kind
    expect(within(form).queryByRole('checkbox')).not.toBeInTheDocument() // no primary: that is its own action
    await user.type(within(form).getByRole('textbox', { name: 'Label' }), 'work')
    await user.click(within(form).getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(api.callsTo(edit)).toHaveLength(1)
    })
    expect(api.callsTo(edit)[0]?.body).toEqual({ label: 'work' })
    expect(await screen.findByText('Contact method saved.')).toBeInTheDocument()
  })

  it('makes a method primary by asking the server, then shows what the server now holds', async () => {
    const { user, api } = await open(TWO_EMAILS)
    const promote =
      `PATCH /api/v1/admin/people/${PERSON_ID}/contact-methods/${METHOD_EMAIL2_ID}` as const
    api.on(
      promote,
      json(wireMethod({ id: METHOD_EMAIL2_ID, value: 'ada.work@example.org', is_primary: true })),
    )
    api.on(RECORD, () =>
      json(
        wirePerson({
          contact_methods: [
            wireMethod({ label: 'home', is_primary: false }),
            wireMethod({ id: METHOD_EMAIL2_ID, value: 'ada.work@example.org', is_primary: true }),
          ],
        }),
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Make ada.work@example.org primary' }))

    expect(
      await screen.findByText('ada.work@example.org is now the primary email.'),
    ).toBeInTheDocument()
    expect(api.callsTo(promote)[0]?.body).toEqual({ is_primary: true })
    const rows = within(screen.getByRole('list', { name: 'Contact methods' })).getAllByRole(
      'listitem',
    )
    expect(within(nth(rows, 0)).queryByText('Primary')).not.toBeInTheDocument() // demoted
    expect(within(nth(rows, 1)).getByText('Primary')).toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Make ada.work@example.org primary' }),
    ).not.toBeInTheDocument()
  })

  it('never offers to un-set a primary: the only way to move it is to promote another', async () => {
    await open(TWO_EMAILS)

    expect(
      screen.queryByRole('button', { name: 'Make ada@example.org primary' }),
    ).not.toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Make ada.work@example.org primary' }),
    ).toBeInTheDocument()
  })

  it('removes only after a deliberate confirmation, and Cancel sends nothing', async () => {
    const { user, api } = await open(TWO_EMAILS)
    const remove =
      `DELETE /api/v1/admin/people/${PERSON_ID}/contact-methods/${METHOD_EMAIL2_ID}` as const
    api.on(remove, new Response(null, { status: 204 }))
    api.on(RECORD, () => json(wirePerson()))

    await user.click(screen.getByRole('button', { name: 'Remove ada.work@example.org' }))
    const dialog = await screen.findByRole('dialog', { name: 'Remove this contact method?' })
    expect(within(dialog).getByText(/ada.work@example.org/)).toBeInTheDocument()
    expect(
      within(dialog).queryByText(/earliest one recorded becomes the primary/),
    ).not.toBeInTheDocument() // it is not the primary
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    expect(api.callsTo(remove)).toHaveLength(0)

    await user.click(screen.getByRole('button', { name: 'Remove ada.work@example.org' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Remove' }),
    )

    expect(await screen.findByText('ada.work@example.org was removed.')).toBeInTheDocument()
    expect(api.callsTo(remove)).toHaveLength(1)
    expect(
      screen.queryByRole('button', { name: 'Edit ada.work@example.org' }),
    ).not.toBeInTheDocument()
  })

  it('tells the Guardian what removing the primary does, and shows the promoted method afterwards', async () => {
    const { user, api } = await open(TWO_EMAILS)
    const remove =
      `DELETE /api/v1/admin/people/${PERSON_ID}/contact-methods/${METHOD_EMAIL_ID}` as const
    api.on(remove, new Response(null, { status: 204 }))
    api.on(RECORD, () =>
      json(
        wirePerson({
          contact_methods: [
            wireMethod({ id: METHOD_EMAIL2_ID, value: 'ada.work@example.org', is_primary: true }),
          ],
        }),
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Remove ada@example.org' }))
    const dialog = await screen.findByRole('dialog')
    expect(
      within(dialog).getByText(/earliest one recorded becomes the primary/),
    ).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Remove' }))

    await waitFor(() => {
      expect(
        within(screen.getByRole('list', { name: 'Contact methods' })).getAllByRole('listitem'),
      ).toHaveLength(1)
    })
    expect(
      within(screen.getByRole('list', { name: 'Contact methods' })).getByText('Primary'),
    ).toBeInTheDocument() // the promoted one
  })

  it('keeps the dialog open with a plain message if the removal fails', async () => {
    const { user, api } = await open(TWO_EMAILS)
    api.on(
      `DELETE /api/v1/admin/people/${PERSON_ID}/contact-methods/${METHOD_EMAIL2_ID}`,
      json({ message: 'SQLSTATE leak' }, 500),
    )

    await user.click(screen.getByRole('button', { name: 'Remove ada.work@example.org' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Remove' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'The service is temporarily unavailable.',
    )
    expect(dialog).not.toHaveTextContent('SQLSTATE')
  })
})

describe('adding a person', () => {
  const POST = 'POST /api/v1/admin/people' as const

  function openForm() {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    renderApp('/people/new')
    return { user, api }
  }

  async function fill(
    user: ReturnType<typeof userEvent.setup>,
    name: string,
    extra: { email?: string; phone?: string; how?: string; affiliation?: string } = {},
  ) {
    await user.type(await screen.findByRole('textbox', { name: 'Display name' }), name)
    if (extra.email) await user.type(screen.getByRole('textbox', { name: 'Email' }), extra.email)
    if (extra.phone) await user.type(screen.getByRole('textbox', { name: 'Phone' }), extra.phone)
    if (extra.how)
      await user.type(screen.getByRole('textbox', { name: 'How we know them' }), extra.how)
    if (extra.affiliation)
      await user.type(screen.getByRole('textbox', { name: 'Affiliation' }), extra.affiliation)
  }

  it('adds a person with what is known, as contact methods, and offers to open them', async () => {
    const { user, api } = openForm()
    api.on(POST, json(wirePerson(), 201))

    await fill(user, '  Ada Lovelace ', {
      email: 'ada@example.org',
      phone: '555 010 0100',
      how: 'Workshop',
      affiliation: 'Guild',
    })
    await user.click(screen.getByRole('button', { name: 'Add person' }))

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Person added' }),
    ).toBeInTheDocument()
    expect(api.callsTo(POST)).toHaveLength(1)
    expect(api.callsTo(POST)[0]?.body).toEqual({
      display_name: 'Ada Lovelace',
      how_we_know: 'Workshop',
      affiliation: 'Guild',
      contact_methods: [
        { kind: 'email', value: 'ada@example.org', label: null, is_primary: false },
        { kind: 'phone', value: '555 010 0100', label: null, is_primary: false },
      ],
    }) // no confirm_distinct on the ordinary path
    expect(screen.getByRole('link', { name: 'Open the person' })).toHaveAttribute(
      'href',
      `/people/${PERSON_ID}`,
    )
    expect(screen.getByRole('status')).toHaveTextContent('They have no account and no sign-in.')
  })

  it('needs only a name: the ordinary path stays simple', async () => {
    const { user, api } = openForm()
    api.on(POST, json(wirePerson({ contact_methods: [] }), 201))

    await fill(user, 'Solo Name')
    await user.click(screen.getByRole('button', { name: 'Add person' }))

    await screen.findByRole('heading', { level: 1, name: 'Person added' })
    expect(api.callsTo(POST)[0]?.body).toEqual({
      display_name: 'Solo Name',
      how_we_know: null,
      affiliation: null,
      contact_methods: [],
    })
  })

  it('ties a validation refusal to its field and keeps the form', async () => {
    const { user, api } = openForm()
    api.on(POST, invalidContactInput('how_we_know', 'That is limited to 2000 characters.'))

    await fill(user, 'Ada', { how: 'x' })
    await user.click(screen.getByRole('button', { name: 'Add person' }))

    expect(await screen.findByText('That is limited to 2000 characters.')).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'How we know them' })).toBeInvalid()
    expect(screen.getByRole('textbox', { name: 'Display name' })).toHaveValue('Ada')
  })

  it('shows a refused contact method beside the box it came from, not by its position in the request', async () => {
    const { user, api } = openForm()
    api.on(
      POST,
      json(
        {
          message: 'Enter a phone number of up to 64 characters with at least 3 digits.',
          code: 'invalid_contact_input',
          errors: {
            'contact_methods.1.value': [
              'Enter a phone number of up to 64 characters with at least 3 digits.',
            ],
          },
        },
        422,
      ),
    )

    await fill(user, 'Ada', { email: 'ada@example.org', phone: '12' })
    await user.click(screen.getByRole('button', { name: 'Add person' }))

    expect(await screen.findByText(/at least 3 digits/)).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Phone' })).toBeInvalid()
    expect(screen.getByRole('textbox', { name: 'Email' })).not.toBeInvalid()
  })

  describe('when the server advises that this may be someone already in the directory', () => {
    const CANDIDATES = [
      { id: PERSON_ID, display_name: 'Ada Lovelace', matched_on: ['email', 'display_name'] },
      { id: '01J00000000000000000PERSN2', display_name: 'Ada L.', matched_on: ['display_name'] },
    ]

    it('shows the candidates and what matched, creates nothing, and does not merge or adopt anyone', async () => {
      const { user, api } = openForm()
      api.on(POST, possibleDuplicate(CANDIDATES))

      await fill(user, 'Ada Lovelace', { email: 'ada@example.org' })
      await user.click(screen.getByRole('button', { name: 'Add person' }))

      const advice = await screen.findByRole('list', { name: 'Possible duplicates' })
      const items = within(advice).getAllByRole('listitem')
      expect(within(nth(items, 0)).getByRole('link', { name: 'Ada Lovelace' })).toHaveAttribute(
        'href',
        `/people/${PERSON_ID}`,
      )
      expect(items[0]).toHaveTextContent('same email, same name')
      expect(items[1]).toHaveTextContent('same name')
      expect(screen.getByText(/Nothing has been added yet/)).toBeInTheDocument()
      expect(screen.getByRole('alert')).toHaveTextContent('Ada Lovelace')
      expect(screen.queryByRole('heading', { name: 'Person added' })).not.toBeInTheDocument()
      expect(api.callsTo(POST)).toHaveLength(1)
      expect(api.callsTo(POST)[0]?.body).not.toHaveProperty('confirm_distinct')
      // The only calls ever made are the one refused registration: no PATCH, no PUT, no merge of any kind.
      expect(
        api.calls.filter((c) => c.method !== 'GET' && c.path.startsWith('/api/v1/admin')),
      ).toHaveLength(1)
      // The Guardian's form is still there, untouched.
      expect(screen.getByRole('textbox', { name: 'Display name' })).toHaveValue('Ada Lovelace')
    })

    it('registers a distinct person only on an explicit choice, with the same details and confirm_distinct', async () => {
      const { user, api } = openForm()
      api.on(POST, (call) =>
        (call.body as { confirm_distinct?: boolean }).confirm_distinct === true
          ? json(
              wirePerson({
                person: { id: '01J00000000000000000PERSN9', display_name: 'Ada Lovelace' },
              }),
              201,
            )
          : possibleDuplicate(CANDIDATES),
      )

      await fill(user, 'Ada Lovelace', { email: 'ada@example.org' })
      await user.click(screen.getByRole('button', { name: 'Add person' }))
      await user.click(
        await screen.findByRole('button', { name: 'This is a different person: add anyway' }),
      )

      expect(
        await screen.findByRole('heading', { level: 1, name: 'Person added' }),
      ).toBeInTheDocument()
      const [first, second] = api.callsTo(POST)
      expect(api.callsTo(POST)).toHaveLength(2)
      expect(second?.body).toEqual({ ...(first?.body as object), confirm_distinct: true })
      expect(screen.getByRole('link', { name: 'Open the person' })).toHaveAttribute(
        'href',
        '/people/01J00000000000000000PERSN9',
      ) // a NEW person, not an existing one
    })

    it('lets the Guardian go back and change the details, which asks the server again rather than assuming', async () => {
      const { user, api } = openForm()
      api.on(POST, possibleDuplicate(CANDIDATES))

      await fill(user, 'Ada Lovelace')
      await user.click(screen.getByRole('button', { name: 'Add person' }))
      await user.click(
        await screen.findByRole('button', { name: 'Go back and change the details' }),
      )

      expect(screen.queryByRole('list', { name: 'Possible duplicates' })).not.toBeInTheDocument()
      await user.type(screen.getByRole('textbox', { name: 'Display name' }), ' II')
      await user.click(screen.getByRole('button', { name: 'Add person' }))

      await waitFor(() => {
        expect(api.callsTo(POST)).toHaveLength(2)
      })
      expect(api.callsTo(POST)[1]?.body).toMatchObject({ display_name: 'Ada Lovelace II' })
      expect(api.callsTo(POST)[1]?.body).not.toHaveProperty('confirm_distinct')
    })

    it('treats a conflict that is not that advice, or is not shaped like it, as an ordinary refusal', async () => {
      const { user, api } = openForm()
      api.on(POST, json({ message: 'x', code: 'possible_duplicate', candidates: [{ id: 5 }] }, 409))

      await fill(user, 'Ada')
      await user.click(screen.getByRole('button', { name: 'Add person' }))

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'That could not be done in the current state. Reload and check.',
      )
      expect(screen.queryByRole('button', { name: /add anyway/ })).not.toBeInTheDocument()
    })
  })

  it('refuses the form without crm.people.manage, and the list without crm.people.view, each independently', async () => {
    const api = serveOperator(operator(VIEW))
    renderApp('/people/new')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
    ).toBeInTheDocument()
    expect(api.callsTo(POST)).toHaveLength(0)
  })

  it('has no structural accessibility violation, on the form and on the advice', async () => {
    const { user, api } = openForm()
    api.on(
      POST,
      possibleDuplicate([{ id: PERSON_ID, display_name: 'Ada Lovelace', matched_on: ['email'] }]),
    )
    await screen.findByRole('heading', { level: 1, name: 'Add a person' })
    await expectNoAxeViolations()

    await fill(user, 'Ada Lovelace')
    await user.click(screen.getByRole('button', { name: 'Add person' }))
    await screen.findByRole('list', { name: 'Possible duplicates' })
    await expectNoAxeViolations()
  })
})
