import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y.ts'
import { json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import { guardianBoth, serveRecord } from '../test/relationshipPages.ts'
import {
  GWEN,
  notFound,
  operatorWith,
  PERSON,
  REL_ID,
  REL_ID_2,
  wireApprenticeType,
  wireGuardianType,
  wireManagement,
  wireRecord,
  wireVolunteerType,
} from '../test/relationships.ts'
import { pageBody } from '../test/deferred.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

const G = `/api/v1/admin/relationships/guardians/${PERSON}`
const GUARDIAN_PAGE = `/relationships/guardians/${PERSON}`

const noControls = () => {
  for (const name of [
    /^Change to /,
    'Edit details',
    'Edit contact details',
    'Grant access',
    'Withdraw access',
  ]) {
    expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
  }
  expect(screen.queryByRole('button', { name: /Delete this/ })).not.toBeInTheDocument()
}

describe('a relationship record, by the capabilities the operator holds', () => {
  it('view only: reads the record and nothing else, shows history and provenance, and offers no change', async () => {
    const { api } = serveRecord({
      types: [
        wireGuardianType({
          actions: { view: true, manage: false, delete: false, provision_default_role: false },
        }),
      ],
      slug: 'guardians',
      view: () =>
        json(wireRecord({ fields: { stewardship: 'The garden', recognized_on: '2026-01-02' } })),
    })
    renderApp(GUARDIAN_PAGE)

    expect(await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })).toBeVisible()
    // Never asks for what it may not read.
    expect(api.callsTo(`GET ${G}/management`)).toHaveLength(0)
    expect(api.callsTo(`GET ${G}`)).toHaveLength(1)

    const body = pageBody()
    expect(within(body).getByText('The garden')).toBeVisible()
    expect(within(body).getByText('January 2, 2026')).toBeVisible() // a calendar date has no zone
    expect(within(body).getByText('ada@example.org')).toBeVisible()
    // Provenance and history, as the view read supplies them.
    const history = within(body).getByRole('list', { name: 'Status history' })
    expect(within(history).getAllByRole('listitem')).toHaveLength(3)
    expect(within(history).getByText('Recorded as')).toBeVisible()
    expect(within(body).getAllByText(/Gwen Operator/).length).toBeGreaterThan(0)
    noControls()
    await expectNoAxeViolations(pageBody())
  })

  it('manage only: reads the management view and never the record, so it shows no history or provenance', async () => {
    const { api } = serveRecord({
      types: [
        wireGuardianType({
          actions: { view: false, manage: true, delete: true, provision_default_role: true },
        }),
      ],
      slug: 'guardians',
      management: () => json(wireManagement()),
    })
    renderApp(GUARDIAN_PAGE)

    expect(await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })).toBeVisible()
    expect(api.callsTo(`GET ${G}`)).toHaveLength(0)
    expect(api.callsTo(`GET ${G}/management`)).toHaveLength(1)
    expect(api.calls.some((c) => c.path === '/api/v1/admin/relationships/guardians')).toBe(false)

    const body = pageBody()
    expect(within(body).queryByRole('heading', { name: 'History' })).not.toBeInTheDocument()
    expect(within(body).queryByText('Status since')).not.toBeInTheDocument()
    expect(within(body).queryByText('Status set by')).not.toBeInTheDocument()
    expect(within(body).queryByText('Recorded')).not.toBeInTheDocument()
    // It can manage: the controls are there.
    expect(screen.getByRole('button', { name: 'Change to Inactive' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Edit details' })).toBeVisible()
    expect(screen.getByRole('button', { name: /Delete this guardian/ })).toBeVisible()
    await expectNoAxeViolations(pageBody())
  })

  it('both capabilities: one record, combined once, with no panel shown twice', async () => {
    const { api } = guardianBoth()
    renderApp(GUARDIAN_PAGE)

    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await screen.findByRole('heading', { level: 2, name: 'History' })
    expect(api.callsTo(`GET ${G}`)).toHaveLength(1)
    expect(api.callsTo(`GET ${G}/management`)).toHaveLength(1)
    const body = pageBody()
    for (const title of ['Status', 'Details', 'History']) {
      expect(within(body).getAllByRole('heading', { level: 2, name: title })).toHaveLength(1)
    }
    expect(within(body).getByRole('button', { name: 'Change to Inactive' })).toBeVisible()
  })

  it('shows "Open full record" only to an operator who can view People, and links to the person', async () => {
    guardianBoth({ account: operatorWith('crm.people.view') })
    const first = renderApp(GUARDIAN_PAGE)
    expect(await screen.findByRole('link', { name: 'Open full record' })).toHaveAttribute(
      'href',
      `/people/${PERSON}`,
    )
    first.unmount()

    guardianBoth()
    renderApp(GUARDIAN_PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(screen.queryByRole('link', { name: 'Open full record' })).not.toBeInTheDocument()
  })

  it('says a person is not recorded when the relationship does not exist, and names nothing of any other type', async () => {
    const { api } = serveRecord({
      types: [wireVolunteerType()],
      slug: 'volunteers',
      view: () => notFound(),
      management: () => notFound(),
    })
    renderApp(`/relationships/volunteers/${PERSON}`)

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This person is not recorded as a Volunteer.',
    )
    expect(api.calls.some((c) => c.path.includes('/guardians'))).toBe(false)
    expect(screen.getByRole('link', { name: 'Back to volunteers' })).toHaveAttribute(
      'href',
      '/relationships/volunteers',
    )
  })

  it('sends a manager who cannot view back to somewhere they can go', async () => {
    serveRecord({
      types: [
        wireVolunteerType({
          actions: { view: false, manage: true, delete: true, provision_default_role: false },
        }),
      ],
      slug: 'volunteers',
      management: () => notFound(),
    })
    renderApp(`/relationships/volunteers/${PERSON}`)
    expect(await screen.findByRole('link', { name: 'Record someone' })).toHaveAttribute(
      'href',
      '/relationships/volunteers/new',
    )
  })

  it('refuses to offer changes while the two reads disagree about the instance, until it is read again', async () => {
    const user = userEvent.setup()
    let healed = false
    serveRecord({
      types: [wireGuardianType()],
      slug: 'guardians',
      view: () => json(wireRecord({ relationship_id: healed ? REL_ID : REL_ID_2 })),
      management: () => json(wireManagement({ relationship_id: REL_ID })),
    })
    renderApp(GUARDIAN_PAGE)

    expect(await screen.findByText(/changed while the page was loading/)).toBeVisible()
    expect(screen.getByRole('button', { name: 'Change to Inactive' })).toBeDisabled()
    // Nothing can be edited while it is unsure what it is looking at.
    expect(screen.queryByRole('button', { name: 'Edit details' })).not.toBeInTheDocument()
    healed = true
    await user.click(screen.getByRole('button', { name: 'Reload' }))
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Change to Inactive' })).toBeEnabled()
    })
    expect(screen.queryByText(/changed while the page was loading/)).not.toBeInTheDocument()
  })

  it('renders a type it has never seen: its words, its states, its groups, and no editor for a field it cannot edit', async () => {
    const { api } = serveRecord({
      types: [wireApprenticeType()],
      slug: 'apprentices',
      view: () =>
        json(
          wireRecord({
            type: 'apprentice',
            status: { key: 'applied', since: '2026-09-01T10:00:00Z', by: GWEN },
            fields: { mentor: 'Ola', own_tools: true, started_on: '2026-03-04', signature: 'ink' },
            default_role: null,
            basics: null,
          }),
        ),
      management: () =>
        json(
          wireManagement({
            type: 'apprentice',
            status: 'applied',
            transitions: ['training'],
            fields: {
              track: 'wood',
              mentor: 'Ola',
              own_tools: true,
              started_on: '2026-03-04',
              signature: 'ink',
            },
            default_role: null,
            basics: null,
          }),
        ),
    })
    const user = userEvent.setup()
    renderApp(`/relationships/apprentices/${PERSON}`)

    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    const body = pageBody()
    expect(within(body).getAllByText('Applied').length).toBeGreaterThan(0)
    expect(within(body).getByText('Programme')).toBeVisible() // a group heading, from the definition
    expect(within(body).getByText('Track')).toBeVisible()
    expect(within(body).getByText('Yes')).toBeVisible() // a boolean
    expect(within(body).getByText('March 4, 2026')).toBeVisible()
    expect(within(body).getByText('ink')).toBeVisible() // an unknown type is shown as the text it is
    // No default-role panel, no contact editor, no provisioning anywhere: the definition has none.
    expect(within(body).queryByText(/access/i)).not.toBeInTheDocument()
    expect(
      within(body).queryByRole('button', { name: 'Edit contact details' }),
    ).not.toBeInTheDocument()

    await user.click(within(body).getByRole('button', { name: 'Edit details' }))
    expect(screen.getByRole('combobox', { name: 'Track (required)' })).toHaveValue('wood')
    expect(screen.getByRole('textbox', { name: 'Mentor' })).toHaveValue('Ola')
    expect(screen.getByRole('checkbox', { name: 'Brings their own tools' })).toBeChecked()
    expect(screen.getByLabelText('Started on')).toHaveAttribute('type', 'date')
    // The field type with no control is shown and explained; it is never a free-form box.
    expect(screen.getByText(/cannot be edited in this version of the Console/)).toBeVisible()
    expect(screen.queryByRole('textbox', { name: 'Signature' })).not.toBeInTheDocument()
    expect(
      api.callsTo(`PATCH /api/v1/admin/relationships/apprentices/${PERSON}/fields`),
    ).toHaveLength(0)
    await expectNoAxeViolations(pageBody())
  })

  it('shows nothing from the Person record: no account, role, membership or security data', async () => {
    guardianBoth({ account: operatorWith('crm.people.view') })
    renderApp(GUARDIAN_PAGE)
    await screen.findByRole('heading', { level: 2, name: 'History' })
    const text = pageBody().textContent
    expect(text).not.toMatch(/membership|password|two-step|login|sign-in email|\brole\b/i)
  })
})
