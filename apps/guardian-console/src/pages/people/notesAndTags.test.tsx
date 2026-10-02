import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { shown } from '../../admin/time.ts'
import { operator, serveOperator } from '../../test/admin.ts'
import { expectNoAxeViolations } from '../../test/a11y.ts'
import { empty, json, type FakeApi } from '../../test/fakeApi.ts'
import {
  INTERACTION_ID,
  INTERACTION2_ID,
  interactionNotFound,
  interactionsPage,
  invalidContactInput,
  PERSON_ID,
  TAG_ID,
  TAG2_ID,
  tagNotFound,
  wireInteraction,
  wirePerson,
  wireTag,
} from '../../test/people.ts'
import { renderApp } from '../../test/renderApp.tsx'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const VIEW = ['console.access', 'crm.people.view']
const MANAGE = ['console.access', 'crm.people.view', 'crm.people.manage']

const RECORD = `GET /api/v1/admin/people/${PERSON_ID}` as const
const INTERACTIONS = `GET /api/v1/admin/people/${PERSON_ID}/interactions` as const
const RECORD_INTERACTION = `POST /api/v1/admin/people/${PERSON_ID}/interactions` as const
const EDIT_INTERACTION =
  `PATCH /api/v1/admin/people/${PERSON_ID}/interactions/${INTERACTION_ID}` as const
const REMOVE_INTERACTION =
  `DELETE /api/v1/admin/people/${PERSON_ID}/interactions/${INTERACTION_ID}` as const
const SET_TAGS = `PUT /api/v1/admin/people/${PERSON_ID}/tags` as const
const TAGS = 'GET /api/v1/admin/contact-tags' as const

/** The item at `index`, or a failure that says so (the repository allows neither a cast nor a non-null assertion). */
function nth<T>(list: T[], index: number): T {
  const item = list[index]
  if (item === undefined) throw new Error(`There is no item ${String(index)}.`)
  return item
}

/** The interaction list requests (they carry a query string, which `callsTo` does not match). */
const listCalls = (api: FakeApi) =>
  api.calls.filter(
    (c) =>
      c.method === 'GET' && c.path.startsWith(`/api/v1/admin/people/${PERSON_ID}/interactions?`),
  )

/** Everything on the page has finished loading: no skeleton region is busy. */
const settled = () =>
  waitFor(() => {
    expect(document.querySelector('[aria-busy="true"]')).toBeNull()
  })

const pageText = () => document.querySelector('[data-page-width]')?.textContent ?? ''

async function openRecord(
  options: {
    capabilities?: string[]
    interactions?: unknown
    person?: Record<string, unknown>
    vocabulary?: unknown[]
  } = {},
) {
  const user = userEvent.setup()
  const api = serveOperator(operator(options.capabilities ?? MANAGE))
  api.on(RECORD, () => json(options.person ?? wirePerson({ tags: [] })))
  api.on(INTERACTIONS, json(options.interactions ?? interactionsPage([])))
  api.on(
    TAGS,
    json({ data: options.vocabulary ?? [wireTag(), wireTag({ id: TAG2_ID, name: 'Lead' })] }),
  )
  renderApp(`/people/${PERSON_ID}`)
  await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
  await settled()
  return { user, api }
}

describe('notes and interactions: reading', () => {
  it("shows the history in the server's order, each with its kind in words, its time, its author and its text", async () => {
    await openRecord({
      interactions: interactionsPage([
        wireInteraction({
          kind: 'call',
          body: 'Rang about the workshop.\nSecond line.',
          occurred_at: '2026-10-02T09:00:00Z',
        }),
        wireInteraction({
          id: INTERACTION2_ID,
          kind: 'meeting',
          body: 'Coffee at the market.',
          occurred_at: '2026-09-20T10:00:00Z',
          author: { id: '01J000000000000000000AUTH02', display_name: 'Hone Guardian' },
        }),
      ]),
    })

    const list = screen.getByRole('list', { name: 'Notes and interactions' })
    const items = within(list).getAllByRole('listitem')
    expect(items).toHaveLength(2)
    // Server order is kept: the newer call first, however the Console might be tempted to sort.
    expect(nth(items, 0)).toHaveTextContent('Call')
    expect(nth(items, 1)).toHaveTextContent('Meeting')
    expect(within(nth(items, 0)).getByText(/Rang about the workshop\./)).toHaveTextContent(
      'Second line.',
    )
    expect(within(nth(items, 0)).getByText(shown('2026-10-02T09:00:00Z'))).toHaveAttribute(
      'datetime',
      '2026-10-02T09:00:00Z',
    )
    expect(nth(items, 0)).toHaveTextContent('Recorded by Gwen Guardian')
    expect(nth(items, 1)).toHaveTextContent('Recorded by Hone Guardian')
    expect(nth(items, 0)).not.toHaveTextContent(/Last edited/) // never edited: no claim of an edit
  })

  it('asks for one page only, the first, in a bounded size, and not for the rest', async () => {
    const { api } = await openRecord({
      interactions: interactionsPage([wireInteraction()], { total: 35, last_page: 4 }),
    })

    expect(listCalls(api).map((c) => c.path)).toEqual([
      `/api/v1/admin/people/${PERSON_ID}/interactions?page=1&per_page=10`,
    ])
  })

  it('says honestly who last edited a note and when, without implying any earlier version can be recovered', async () => {
    await openRecord({
      interactions: interactionsPage([
        wireInteraction({
          updated_by: { id: '01J000000000000000000AUTH02', display_name: 'Hone Guardian' },
          updated_at: '2026-10-02T11:00:00Z',
        }),
      ]),
    })

    const item = within(screen.getByRole('list', { name: 'Notes and interactions' })).getByRole(
      'listitem',
    )
    expect(item).toHaveTextContent('Recorded by Gwen Guardian')
    expect(item).toHaveTextContent(`Last edited by Hone Guardian, ${shown('2026-10-02T11:00:00Z')}`)
    expect(item).not.toHaveTextContent(/history|revision|version|restore|previous/i)
  })

  it('pages with the server, and shows no pager when everything fits on one page', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEW))
    api.on(RECORD, () => json(wirePerson({ tags: [] })))
    api.on(TAGS, json({ data: [] }))
    api.on(INTERACTIONS, (call) => {
      const page = Number(new URL(call.path, 'http://x').searchParams.get('page'))
      return json(
        interactionsPage([wireInteraction({ body: `Note on page ${String(page)}` })], {
          page,
          total: 25,
          last_page: 3,
        }),
      )
    })
    renderApp(`/people/${PERSON_ID}`)
    await screen.findByText('Note on page 1')

    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(await screen.findByText('Note on page 2')).toBeInTheDocument()
    expect(listCalls(api).at(-1)?.path).toContain('page=2')
  })

  it('has no pager for a single page', async () => {
    await openRecord({ interactions: interactionsPage([wireInteraction()]) })

    expect(screen.queryByRole('navigation', { name: 'Pages' })).not.toBeInTheDocument()
  })

  it('says so when there is nothing recorded', async () => {
    await openRecord()

    expect(screen.getByText('No notes or interactions yet.')).toBeInTheDocument()
  })

  it("recovers from a failed load, in plain words and without the server's own", async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEW))
    api.on(RECORD, () => json(wirePerson({ tags: [] })))
    api.on(TAGS, json({ data: [] }))
    let healthy = false
    api.on(INTERACTIONS, () =>
      healthy
        ? json(interactionsPage([wireInteraction()]))
        : json({ message: 'SQLSTATE[HY000] boom' }, 500),
    )
    renderApp(`/people/${PERSON_ID}`)

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(
      'The service is temporarily unavailable. Try again in a moment.',
    )
    expect(alert).not.toHaveTextContent(/SQLSTATE|boom/)

    healthy = true
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByText('Spoke about the spring workshop.')).toBeInTheDocument()
  })

  it('states once that everyone who can view people sees every note, and invents no confidentiality', async () => {
    await openRecord({ interactions: interactionsPage([wireInteraction()]) })

    expect(
      screen.getByText(
        /Visible to everyone who can view people\. Write them as though the person could one day ask to read them\./,
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByText(/private|confidential|only you|self-service|portal/i),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole('checkbox', { name: /private|confidential|visible/i }),
    ).not.toBeInTheDocument()
  })

  it('discloses nothing about an Account, access, Membership or security around an author', async () => {
    await openRecord({
      interactions: interactionsPage([
        wireInteraction({
          updated_by: { id: '01J000000000000000000AUTH02', display_name: 'Hone Guardian' },
        }),
      ]),
    })

    expect(pageText()).not.toMatch(
      /account|login|password|security|mfa|two-step|capabilit|membership|volunteer|\brole/i,
    )
  })
})

describe('notes and interactions: the view-only experience', () => {
  it('lets a view-only Guardian read the history and the tags, and offers no way to change either', async () => {
    const { api } = await openRecord({
      capabilities: VIEW,
      person: wirePerson({ tags: [{ id: TAG_ID, name: 'Partner' }] }),
      interactions: interactionsPage([wireInteraction()]),
    })

    expect(screen.getByText('Spoke about the spring workshop.')).toBeInTheDocument()
    expect(
      within(screen.getByRole('list', { name: 'Tags' })).getByText('Partner'),
    ).toBeInTheDocument()
    for (const name of [/Record a note/, /^Edit the /, /^Remove the /, /Edit tags/]) {
      expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    }
    expect(screen.queryByRole('link', { name: 'Manage the list of tags' })).not.toBeInTheDocument()
    expect(api.calls.filter((c) => c.method !== 'GET')).toEqual([])
  })

  it('offers the changes to crm.people.manage', async () => {
    await openRecord({ interactions: interactionsPage([wireInteraction()]) })

    expect(screen.getByRole('button', { name: 'Record a note' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^Edit the note from / })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^Remove the note from / })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Edit tags' })).toBeInTheDocument()
  })
})

describe('recording a note', () => {
  it('needs only the text: the kind is a note, the time is now, and the author is not asked for or sent', async () => {
    const { user, api } = await openRecord()
    api.on(RECORD_INTERACTION, json(wireInteraction(), 201))
    let recorded = false
    api.on(INTERACTIONS, () => json(interactionsPage(recorded ? [wireInteraction()] : [])))

    await user.click(screen.getByRole('button', { name: 'Record a note' }))
    const form = screen.getByRole('form', { name: 'Record a note' })
    expect(within(form).getByRole('combobox', { name: 'Kind' })).toHaveValue('note')
    expect(within(form).queryByRole('combobox', { name: /author|by|who/i })).not.toBeInTheDocument()
    await user.type(
      within(form).getByRole('textbox', { name: 'Details' }),
      '  Asked for the newsletter.  ',
    )
    recorded = true
    await user.click(within(form).getByRole('button', { name: 'Record' }))

    expect(await screen.findByText('Recorded.')).toBeInTheDocument()
    expect(api.callsTo(RECORD_INTERACTION)).toHaveLength(1)
    // Exactly the fields the Guardian gave: no author, no person, no time when none was entered.
    expect(api.callsTo(RECORD_INTERACTION)[0]?.body).toEqual({
      body: '  Asked for the newsletter.  ',
      kind: 'note',
    })
    expect(await screen.findByText('Spoke about the spring workshop.')).toBeInTheDocument() // re-read from the server
  })

  it('records the chosen kind and the time the Guardian entered, as an instant', async () => {
    const { user, api } = await openRecord()
    api.on(RECORD_INTERACTION, json(wireInteraction({ kind: 'call' }), 201))

    await user.click(screen.getByRole('button', { name: 'Record a note' }))
    const form = screen.getByRole('form', { name: 'Record a note' })
    await user.selectOptions(within(form).getByRole('combobox', { name: 'Kind' }), 'call')
    await user.type(within(form).getByRole('textbox', { name: 'Details' }), 'Rang her back.')
    fireEvent.change(within(form).getByLabelText('When it happened'), {
      target: { value: '2026-09-30T08:30' },
    })
    await user.click(within(form).getByRole('button', { name: 'Record' }))

    await waitFor(() => {
      expect(api.callsTo(RECORD_INTERACTION)).toHaveLength(1)
    })
    expect(api.callsTo(RECORD_INTERACTION)[0]?.body).toEqual({
      body: 'Rang her back.',
      kind: 'call',
      occurred_at: new Date('2026-09-30T08:30').toISOString(),
    })
  })

  it('offers each kind the server knows, by name', async () => {
    const { user } = await openRecord()

    await user.click(screen.getByRole('button', { name: 'Record a note' }))
    const kinds = within(screen.getByRole('combobox', { name: 'Kind' })).getAllByRole('option')
    expect(kinds.map((o) => o.textContent)).toEqual(['Note', 'Call', 'Email', 'Meeting'])
  })

  it('returns to the first page after recording, where the newest are', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    api.on(RECORD, () => json(wirePerson({ tags: [] })))
    api.on(TAGS, json({ data: [] }))
    api.on(RECORD_INTERACTION, json(wireInteraction(), 201))
    api.on(INTERACTIONS, (call) => {
      const page = Number(new URL(call.path, 'http://x').searchParams.get('page'))
      return json(
        interactionsPage([wireInteraction({ body: `Note on page ${String(page)}` })], {
          page,
          total: 25,
          last_page: 3,
        }),
      )
    })
    renderApp(`/people/${PERSON_ID}`)
    await screen.findByText('Note on page 1')
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await screen.findByText('Note on page 2')

    await user.click(screen.getByRole('button', { name: 'Record a note' }))
    await user.type(screen.getByRole('textbox', { name: 'Details' }), 'Another.')
    await user.click(
      within(screen.getByRole('form', { name: 'Record a note' })).getByRole('button', {
        name: 'Record',
      }),
    )

    expect(await screen.findByText('Note on page 1')).toBeInTheDocument()
    expect(listCalls(api).at(-1)?.path).toContain('page=1')
  })

  it('ties a refusal to its field, keeps what was typed, and asks for no verification', async () => {
    const { user, api } = await openRecord()
    api.on(
      RECORD_INTERACTION,
      invalidContactInput('occurred_at', 'An interaction cannot have happened in the future.'),
    )

    await user.click(screen.getByRole('button', { name: 'Record a note' }))
    const form = screen.getByRole('form', { name: 'Record a note' })
    await user.type(within(form).getByRole('textbox', { name: 'Details' }), 'From the future.')
    fireEvent.change(within(form).getByLabelText('When it happened'), {
      target: { value: '2099-01-01T00:00' },
    })
    await user.click(within(form).getByRole('button', { name: 'Record' }))

    const when = within(form).getByLabelText('When it happened')
    expect(
      await screen.findByText('An interaction cannot have happened in the future.'),
    ).toBeInTheDocument()
    expect(when).toBeInvalid()
    expect(when).toHaveAccessibleDescription(/cannot have happened in the future/)
    expect(within(form).getByRole('textbox', { name: 'Details' })).toHaveValue('From the future.')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('shows a refused body beside the text, and cancels without sending anything', async () => {
    const { user, api } = await openRecord()
    api.on(
      RECORD_INTERACTION,
      invalidContactInput('body', 'That may not contain control characters.'),
    )

    await user.click(screen.getByRole('button', { name: 'Record a note' }))
    await user.type(screen.getByRole('textbox', { name: 'Details' }), 'x')
    await user.click(screen.getByRole('button', { name: 'Record' }))
    expect(await screen.findByText('That may not contain control characters.')).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Details' })).toBeInvalid()

    await user.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('form', { name: 'Record a note' })).not.toBeInTheDocument()
    expect(api.callsTo(RECORD_INTERACTION)).toHaveLength(1)
  })
})

describe('correcting a note', () => {
  async function openEditing() {
    const world = await openRecord({
      interactions: interactionsPage([wireInteraction({ occurred_at: '2026-10-01T15:00:30Z' })]),
    })
    await world.user.click(screen.getByRole('button', { name: /^Edit the note from / }))
    return { ...world, form: screen.getByRole('form', { name: 'Edit this note' }) }
  }

  it('starts from what is recorded', async () => {
    const { form } = await openEditing()

    expect(within(form).getByRole('textbox', { name: 'Details' })).toHaveValue(
      'Spoke about the spring workshop.',
    )
    expect(within(form).getByRole('combobox', { name: 'Kind' })).toHaveValue('note')
  })

  it('sends only the text when only the text changed: not the kind, not the time, never the author or the person', async () => {
    const { user, api, form } = await openEditing()
    api.on(EDIT_INTERACTION, json(wireInteraction({ body: 'Corrected text.' })))

    const body = within(form).getByRole('textbox', { name: 'Details' })
    await user.clear(body)
    await user.type(body, 'Corrected text.')
    await user.click(within(form).getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('Saved.')).toBeInTheDocument()
    expect(api.callsTo(EDIT_INTERACTION)).toHaveLength(1)
    expect(api.callsTo(EDIT_INTERACTION)[0]?.body).toEqual({ body: 'Corrected text.' })
  })

  it('sends only the kind when only the kind changed', async () => {
    const { user, api, form } = await openEditing()
    api.on(EDIT_INTERACTION, json(wireInteraction({ kind: 'meeting' })))

    await user.selectOptions(within(form).getByRole('combobox', { name: 'Kind' }), 'meeting')
    await user.click(within(form).getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(api.callsTo(EDIT_INTERACTION)).toHaveLength(1)
    })
    expect(api.callsTo(EDIT_INTERACTION)[0]?.body).toEqual({ kind: 'meeting' })
  })

  it('sends a changed time as an instant, and leaves an untouched one alone (it would lose its seconds)', async () => {
    const { user, api, form } = await openEditing()
    api.on(EDIT_INTERACTION, json(wireInteraction()))

    fireEvent.change(within(form).getByLabelText('When it happened'), {
      target: { value: '2026-09-29T18:45' },
    })
    await user.click(within(form).getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(api.callsTo(EDIT_INTERACTION)).toHaveLength(1)
    })
    expect(api.callsTo(EDIT_INTERACTION)[0]?.body).toEqual({
      occurred_at: new Date('2026-09-29T18:45').toISOString(),
    })
  })

  it('sends nothing, and says nothing, when nothing changed', async () => {
    const { user, api, form } = await openEditing()

    await user.click(within(form).getByRole('button', { name: 'Save' }))

    expect(api.callsTo(EDIT_INTERACTION)).toHaveLength(0)
    expect(screen.queryByRole('form', { name: 'Edit this note' })).not.toBeInTheDocument()
    expect(screen.queryByText('Saved.')).not.toBeInTheDocument()
  })

  it('says a note that was removed in the meantime is gone, in its own words', async () => {
    const { user, api, form } = await openEditing()
    api.on(EDIT_INTERACTION, interactionNotFound())

    await user.type(within(form).getByRole('textbox', { name: 'Details' }), ' more')
    await user.click(within(form).getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'That note could not be found. It may already have been removed.',
    )
  })

  it('cancels without sending anything', async () => {
    const { user, api, form } = await openEditing()

    await user.type(within(form).getByRole('textbox', { name: 'Details' }), ' more')
    await user.click(within(form).getByRole('button', { name: 'Cancel' }))

    expect(api.callsTo(EDIT_INTERACTION)).toHaveLength(0)
    expect(screen.queryByRole('form', { name: 'Edit this note' })).not.toBeInTheDocument()
  })
})

describe('removing a note', () => {
  async function openRemoving(interactions: unknown = interactionsPage([wireInteraction()])) {
    const world = await openRecord({ interactions })
    await world.user.click(screen.getByRole('button', { name: /^Remove the note from / }))
    return { ...world, dialog: await screen.findByRole('dialog', { name: 'Remove this note?' }) }
  }

  it('says plainly that it is permanent and cannot be restored, and Cancel sends nothing', async () => {
    const { user, api, dialog } = await openRemoving()

    expect(dialog).toHaveTextContent('permanently removes it from Ada Lovelace’s record')
    expect(dialog).toHaveTextContent('It cannot be restored.')
    expect(dialog).toHaveTextContent('Spoke about the spring workshop.') // which one
    expect(dialog).not.toHaveTextContent(/undo|trash|archive|recover/i)
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    expect(api.callsTo(REMOVE_INTERACTION)).toHaveLength(0)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByText('Spoke about the spring workshop.')).toBeInTheDocument()
  })

  it('removes it, then re-reads the list from the server', async () => {
    const { user, api, dialog } = await openRemoving()
    api.on(REMOVE_INTERACTION, empty())
    api.on(INTERACTIONS, json(interactionsPage([])))

    await user.click(within(dialog).getByRole('button', { name: 'Remove' }))

    expect(await screen.findByText('The note was removed.')).toBeInTheDocument()
    expect(api.callsTo(REMOVE_INTERACTION)).toHaveLength(1)
    expect(await screen.findByText('No notes or interactions yet.')).toBeInTheDocument()
  })

  it('steps back a page when it removes the last note on a later page', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    api.on(RECORD, () => json(wirePerson({ tags: [] })))
    api.on(TAGS, json({ data: [] }))
    let removed = false
    api.on(INTERACTIONS, (call) => {
      const page = Number(new URL(call.path, 'http://x').searchParams.get('page'))
      if (page === 2) {
        return removed
          ? json(interactionsPage([], { page: 2, total: 10, last_page: 1 })) // would be empty
          : json(
              interactionsPage([wireInteraction({ body: 'The only one on page two' })], {
                page: 2,
                total: 11,
                last_page: 2,
              }),
            )
      }
      return json(
        interactionsPage([wireInteraction({ id: INTERACTION2_ID, body: 'First page note' })], {
          page: 1,
          total: removed ? 10 : 11,
          last_page: removed ? 1 : 2,
        }),
      )
    })
    api.on(REMOVE_INTERACTION, empty())
    renderApp(`/people/${PERSON_ID}`)
    await screen.findByText('First page note')
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await screen.findByText('The only one on page two')

    await user.click(screen.getByRole('button', { name: /^Remove the note from / }))
    removed = true
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Remove' }),
    )

    expect(await screen.findByText('First page note')).toBeInTheDocument()
    expect(listCalls(api).at(-1)?.path).toContain('page=1')
  })

  it('keeps the dialog open with a plain message if the removal fails', async () => {
    const { user, api, dialog } = await openRemoving()
    api.on(REMOVE_INTERACTION, json({ message: 'SQLSTATE leak' }, 500))

    await user.click(within(dialog).getByRole('button', { name: 'Remove' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'The service is temporarily unavailable.',
    )
    expect(dialog).not.toHaveTextContent('SQLSTATE')
  })
})

describe("a Person's tags", () => {
  it('shows them as words in a list, with no meaning beyond a label', async () => {
    await openRecord({
      person: wirePerson({
        tags: [
          { id: TAG_ID, name: 'Partner' },
          { id: TAG2_ID, name: 'Volunteer Interest' },
        ],
      }),
    })

    const tags = within(screen.getByRole('list', { name: 'Tags' })).getAllByRole('listitem')
    expect(tags.map((t) => t.textContent)).toEqual(['Partner', 'Volunteer Interest'])
    expect(
      screen.getByText('Labels for finding and grouping people. A tag grants no access.'),
    ).toBeInTheDocument()
    // A label that happens to be a word of authority is still only text: nothing links, filters or grants.
    expect(
      within(screen.getByRole('list', { name: 'Tags' })).queryByRole('link'),
    ).not.toBeInTheDocument()
  })

  it('says so when there are none, and invents no default', async () => {
    await openRecord()

    expect(screen.getByText('No tags.')).toBeInTheDocument()
  })

  it('shows which tags are held in the form, by their checked state, and sends exactly the chosen set', async () => {
    const { user, api } = await openRecord({
      person: wirePerson({ tags: [{ id: TAG_ID, name: 'Partner' }] }),
    })
    api.on(SET_TAGS, json({ data: [{ id: TAG2_ID, name: 'Lead' }] }))
    api.on(RECORD, () => json(wirePerson({ tags: [{ id: TAG2_ID, name: 'Lead' }] })))

    await user.click(screen.getByRole('button', { name: 'Edit tags' }))
    const form = await screen.findByRole('form', { name: 'Edit tags' })
    const partner = within(form).getByRole('checkbox', { name: 'Partner' })
    const lead = within(form).getByRole('checkbox', { name: 'Lead' })
    expect(partner).toBeChecked()
    expect(lead).not.toBeChecked()
    expect(within(form).getByRole('group', { name: 'Tags' })).toBeInTheDocument()

    await user.click(lead)
    await user.click(partner)
    await user.click(within(form).getByRole('button', { name: 'Save tags' }))

    expect(await screen.findByText('Tags saved.')).toBeInTheDocument()
    expect(api.callsTo(SET_TAGS)).toHaveLength(1)
    expect(api.callsTo(SET_TAGS)[0]?.body).toEqual({ tag_ids: [TAG2_ID] })
    expect(within(screen.getByRole('list', { name: 'Tags' })).getByText('Lead')).toBeInTheDocument()
    expect(
      within(screen.getByRole('list', { name: 'Tags' })).queryByText('Partner'),
    ).not.toBeInTheDocument()
  })

  it('keeps the tags already held when another is added: the whole set is sent, not just the addition', async () => {
    const { user, api } = await openRecord({
      person: wirePerson({ tags: [{ id: TAG_ID, name: 'Partner' }] }),
    })
    api.on(
      SET_TAGS,
      json({
        data: [
          { id: TAG_ID, name: 'Partner' },
          { id: TAG2_ID, name: 'Lead' },
        ],
      }),
    )

    await user.click(screen.getByRole('button', { name: 'Edit tags' }))
    await user.click(await screen.findByRole('checkbox', { name: 'Lead' }))
    await user.click(screen.getByRole('button', { name: 'Save tags' }))

    await waitFor(() => {
      expect(api.callsTo(SET_TAGS)).toHaveLength(1)
    })
    expect([...(api.callsTo(SET_TAGS)[0]?.body as { tag_ids: string[] }).tag_ids].sort()).toEqual(
      [TAG_ID, TAG2_ID].sort(),
    )
  })

  it('can remove every tag: an empty set is a real answer', async () => {
    const { user, api } = await openRecord({
      person: wirePerson({ tags: [{ id: TAG_ID, name: 'Partner' }] }),
    })
    api.on(SET_TAGS, json({ data: [] }))

    await user.click(screen.getByRole('button', { name: 'Edit tags' }))
    await user.click(await screen.findByRole('checkbox', { name: 'Partner' }))
    await user.click(screen.getByRole('button', { name: 'Save tags' }))

    await waitFor(() => {
      expect(api.callsTo(SET_TAGS)).toHaveLength(1)
    })
    expect(api.callsTo(SET_TAGS)[0]?.body).toEqual({ tag_ids: [] })
  })

  it('sends nothing when the set did not change', async () => {
    const { user, api } = await openRecord({
      person: wirePerson({ tags: [{ id: TAG_ID, name: 'Partner' }] }),
    })

    await user.click(screen.getByRole('button', { name: 'Edit tags' }))
    await user.click(await screen.findByRole('button', { name: 'Save tags' }))

    expect(api.callsTo(SET_TAGS)).toHaveLength(0)
    expect(screen.queryByRole('form', { name: 'Edit tags' })).not.toBeInTheDocument()
  })

  it('points to the Tags page when there are no tags to choose from', async () => {
    const { user } = await openRecord({ vocabulary: [] })

    await user.click(screen.getByRole('button', { name: 'Edit tags' }))

    expect(await screen.findByText(/There are no tags yet\./)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Tags page' })).toHaveAttribute('href', '/people/tags')
  })

  it('shows a tag deleted since the form opened as no longer there, and the vocabulary as it now is', async () => {
    const { user, api } = await openRecord({ person: wirePerson({ tags: [] }) })
    api.on(
      SET_TAGS,
      json(
        {
          message: 'One or more of those tags do not exist.',
          code: 'unknown_tag',
          errors: { tag_ids: ['One or more of those tags do not exist.'] },
        },
        422,
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Edit tags' }))
    await user.click(await screen.findByRole('checkbox', { name: 'Partner' }))
    api.on(TAGS, json({ data: [wireTag({ id: TAG2_ID, name: 'Lead' })] })) // Partner was deleted meanwhile
    await user.click(screen.getByRole('button', { name: 'Save tags' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'One or more of those tags do not exist.',
    )
    await waitFor(() => {
      expect(screen.queryByRole('checkbox', { name: 'Partner' })).not.toBeInTheDocument()
    })
    expect(screen.getByRole('checkbox', { name: 'Lead' })).toBeInTheDocument()
  })
})

describe('the Tags page', () => {
  async function openTags(options: { capabilities?: string[]; tags?: unknown[] } = {}) {
    const user = userEvent.setup()
    const api = serveOperator(operator(options.capabilities ?? MANAGE))
    api.on(
      TAGS,
      json({
        data: options.tags ?? [
          wireTag({ person_count: 3 }),
          wireTag({ id: TAG2_ID, name: 'Lead', person_count: 1 }),
        ],
      }),
    )
    renderApp('/people/tags')
    await screen.findByRole('heading', { level: 1, name: 'Tags' })
    await settled()
    return { user, api }
  }

  it('lists the vocabulary with how many people hold each, and says a tag is only a label', async () => {
    await openTags()

    const items = within(await screen.findByRole('list', { name: 'Tags' })).getAllByRole('listitem')
    expect(nth(items, 0)).toHaveTextContent('Partner3 people')
    expect(nth(items, 1)).toHaveTextContent('Lead1 person')
    expect(
      screen.getByText(
        /A tag grants no access and says nothing about membership or volunteering\./,
      ),
    ).toBeInTheDocument()
  })

  it('is reachable from the navigation by crm.people.view alone', async () => {
    serveOperator(operator(VIEW))
    renderApp('/')

    const link = await screen.findByRole('link', { name: 'People' })
    expect(link).toHaveAttribute('href', '/people')
  })

  it('lets a view-only Guardian read the list and offers no controls', async () => {
    const { api } = await openTags({ capabilities: VIEW })

    await screen.findByRole('list', { name: 'Tags' })
    expect(screen.queryByRole('form', { name: 'Create a tag' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Rename / })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Delete / })).not.toBeInTheDocument()
    expect(api.calls.filter((c) => c.method !== 'GET')).toEqual([])
  })

  it('refuses the page without crm.people.view, and asks the server for nothing', async () => {
    const api = serveOperator(operator(['console.access', 'crm.people.manage']))
    renderApp('/people/tags')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
    ).toBeInTheDocument()
    expect(api.callsTo(TAGS)).toHaveLength(0)
  })

  it('says so when there are no tags yet', async () => {
    await openTags({ tags: [] })

    expect(await screen.findByText('There are no tags yet.')).toBeInTheDocument()
  })

  it('creates a tag and shows the list as the server now holds it', async () => {
    const { user, api } = await openTags()
    api.on(
      'POST /api/v1/admin/contact-tags',
      json(wireTag({ id: '01J0000000000000000000TAG3', name: 'Artist' }), 201),
    )

    await user.type(screen.getByRole('textbox', { name: 'New tag' }), 'Artist')
    api.on(TAGS, json({ data: [wireTag({ id: '01J0000000000000000000TAG3', name: 'Artist' })] }))
    await user.click(screen.getByRole('button', { name: 'Add tag' }))

    expect(await screen.findByText('The tag “Artist” was created.')).toBeInTheDocument()
    expect(api.callsTo('POST /api/v1/admin/contact-tags')[0]?.body).toEqual({ name: 'Artist' })
    expect(await screen.findByText('Artist')).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'New tag' })).toHaveValue('')
  })

  it('says a tag with that name already exists, keeps what was typed, and creates nothing', async () => {
    const { user, api } = await openTags()
    api.on(
      'POST /api/v1/admin/contact-tags',
      json({ message: 'A tag with that name already exists.', code: 'duplicate_tag' }, 409),
    )

    await user.type(screen.getByRole('textbox', { name: 'New tag' }), 'PARTNER ')
    await user.click(screen.getByRole('button', { name: 'Add tag' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'A tag with that name already exists.',
    )
    expect(screen.getByRole('textbox', { name: 'New tag' })).toHaveValue('PARTNER ')
  })

  it('ties a validation refusal to the name', async () => {
    const { user, api } = await openTags()
    api.on(
      'POST /api/v1/admin/contact-tags',
      invalidContactInput('name', 'A tag name is 1 to 64 characters.'),
    )

    await user.type(screen.getByRole('textbox', { name: 'New tag' }), 'x')
    await user.click(screen.getByRole('button', { name: 'Add tag' }))

    expect(await screen.findByText('A tag name is 1 to 64 characters.')).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'New tag' })).toBeInvalid()
  })

  it('renames a tag, sending the new name only when it changed', async () => {
    const { user, api } = await openTags()
    const rename = `PATCH /api/v1/admin/contact-tags/${TAG_ID}` as const
    api.on(rename, json(wireTag({ name: 'Collaborator', person_count: 3 })))

    await user.click(screen.getByRole('button', { name: 'Rename Partner' }))
    const form = screen.getByRole('form', { name: 'Rename Partner' })
    await user.click(within(form).getByRole('button', { name: 'Save' })) // unchanged
    expect(api.callsTo(rename)).toHaveLength(0)
    expect(screen.queryByRole('form', { name: 'Rename Partner' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Rename Partner' }))
    const again = screen.getByRole('form', { name: 'Rename Partner' })
    const name = within(again).getByRole('textbox', { name: 'Tag name' })
    await user.clear(name)
    await user.type(name, 'Collaborator')
    await user.click(within(again).getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('The tag was renamed.')).toBeInTheDocument()
    expect(api.callsTo(rename)).toHaveLength(1)
    expect(api.callsTo(rename)[0]?.body).toEqual({ name: 'Collaborator' })
  })

  it('refuses a rename onto an existing name, and keeps the tag as it was', async () => {
    const { user, api } = await openTags()
    api.on(
      `PATCH /api/v1/admin/contact-tags/${TAG_ID}`,
      json({ message: 'x', code: 'duplicate_tag' }, 409),
    )

    await user.click(screen.getByRole('button', { name: 'Rename Partner' }))
    const name = screen.getByRole('textbox', { name: 'Tag name' })
    await user.clear(name)
    await user.type(name, 'lead')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'A tag with that name already exists.',
    )
    expect(screen.getByRole('form', { name: 'Rename Partner' })).toBeInTheDocument()
  })

  it('deletes an unused tag only after a confirmation, and Cancel sends nothing', async () => {
    const { user, api } = await openTags({ tags: [wireTag({ person_count: 0 })] })
    const remove = `DELETE /api/v1/admin/contact-tags/${TAG_ID}` as const
    api.on(remove, empty())

    await user.click(screen.getByRole('button', { name: 'Delete Partner' }))
    const dialog = await screen.findByRole('dialog', { name: 'Delete this tag?' })
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    expect(api.callsTo(remove)).toHaveLength(0)

    await user.click(screen.getByRole('button', { name: 'Delete Partner' }))
    api.on(TAGS, json({ data: [] }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete' }),
    )

    expect(await screen.findByText('The tag “Partner” was deleted.')).toBeInTheDocument()
    expect(api.callsTo(remove)).toHaveLength(1)
    expect(await screen.findByText('There are no tags yet.')).toBeInTheDocument()
  })

  it('refuses to delete a tag that is in use, says why, keeps the tag and removes it from nobody', async () => {
    const { user, api } = await openTags({ tags: [wireTag({ person_count: 3 })] })
    api.on(
      `DELETE /api/v1/admin/contact-tags/${TAG_ID}`,
      json(
        {
          message: 'That tag is on at least one Person, so it cannot be deleted.',
          code: 'tag_in_use',
        },
        409,
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Delete Partner' }))
    const dialog = await screen.findByRole('dialog', { name: 'Delete this tag?' })
    expect(dialog).toHaveTextContent('It is currently on 3 people.')
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'That tag is on at least one person, so it was not deleted. Remove it from them first.',
    )
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    expect(
      within(screen.getByRole('list', { name: 'Tags' })).getByText('Partner'),
    ).toBeInTheDocument() // preserved
    // The only write was the refused delete: no tag assignment was changed to make it succeed.
    expect(api.calls.filter((c) => c.method !== 'GET').map((c) => `${c.method} ${c.path}`)).toEqual(
      [`DELETE /api/v1/admin/contact-tags/${TAG_ID}`],
    )
  })

  it('says a tag that is already gone is gone', async () => {
    const { user, api } = await openTags()
    api.on(`DELETE /api/v1/admin/contact-tags/${TAG_ID}`, tagNotFound())

    await user.click(screen.getByRole('button', { name: 'Delete Partner' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete' }),
    )

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'That tag could not be found. It may already have been deleted.',
    )
  })

  it('gives a tag no meaning beyond its name: no colour, role, status or permission control', async () => {
    await openTags()
    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Rename Partner' }))

    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
    expect(
      screen.queryByLabelText(/colou?r|role|permission|status|group|parent/i),
    ).not.toBeInTheDocument()
    expect(pageText()).not.toMatch(/account|login|password|security|capabilit|\brole/i)
  })
})

describe('accessibility of the notes and tag states', () => {
  it('has no structural violation with history, the note form and the tag form all open', async () => {
    const { user } = await openRecord({
      person: wirePerson({ tags: [{ id: TAG_ID, name: 'Partner' }] }),
      interactions: interactionsPage(
        [
          wireInteraction(),
          wireInteraction({
            id: INTERACTION2_ID,
            kind: 'call',
            updated_by: { id: '01J000000000000000000AUTH02', display_name: 'Hone Guardian' },
          }),
        ],
        { total: 30, last_page: 3 },
      ),
    })
    await user.click(screen.getByRole('button', { name: 'Record a note' }))
    await user.click(screen.getByRole('button', { name: 'Edit tags' }))
    await screen.findByRole('form', { name: 'Edit tags' })

    await expectNoAxeViolations()
  })

  it('has no structural violation on the editing of a note', async () => {
    const { user } = await openRecord({ interactions: interactionsPage([wireInteraction()]) })
    await user.click(screen.getByRole('button', { name: /^Edit the note from / }))

    expect(screen.getByRole('form', { name: 'Edit this note' })).toBeInTheDocument()
    await expectNoAxeViolations()
  })

  it('has no structural violation on the Tags page, with a rename form open', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    api.on(TAGS, json({ data: [wireTag({ person_count: 2 })] }))
    renderApp('/people/tags')
    await screen.findByRole('list', { name: 'Tags' })
    await user.click(screen.getByRole('button', { name: 'Rename Partner' }))

    await expectNoAxeViolations()
  })

  it('names the removal dialog and puts Cancel first, so the safe choice is the easy one', async () => {
    const { user } = await openRecord({ interactions: interactionsPage([wireInteraction()]) })
    await user.click(screen.getByRole('button', { name: /^Remove the note from / }))

    const dialog = await screen.findByRole('dialog', { name: 'Remove this note?' })
    const buttons = within(dialog)
      .getAllByRole('button')
      .map((b) => b.textContent)
    expect(buttons).toEqual(['Cancel', 'Remove'])
    await expectNoAxeViolations()
  })
})
