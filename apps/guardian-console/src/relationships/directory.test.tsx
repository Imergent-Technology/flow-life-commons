import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y.ts'
import { deferred, pageBody } from '../test/deferred.ts'
import { json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import {
  directoryPage,
  GWEN,
  OPERATOR_PERSON,
  PERSON,
  REL_ID_2,
  serveRelationships,
  wireApprenticeType,
  wireEntry,
  wireGuardianType,
  wireVolunteerType,
} from '../test/relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

const LIST = '/api/v1/admin/relationships/volunteers'
const listCalls = (api: { calls: { method: string; path: string }[] }, base = LIST) =>
  api.calls.filter((c) => c.method === 'GET' && c.path.startsWith(`${base}?`))

describe('a relationship directory', () => {
  it('lists the type with its status in words and a shape, the date, and the primary contact where the type reads basics', async () => {
    const api = serveRelationships([wireVolunteerType()])
    api.on(
      `GET ${LIST}`,
      json(
        directoryPage([
          wireEntry(),
          wireEntry({
            relationship_id: REL_ID_2,
            person: { id: OPERATOR_PERSON, display_name: 'Grace Hopper' },
            status: 'pending',
            basics: { primary_email: null, primary_phone: null },
          }),
        ]),
      ),
    )
    renderApp('/relationships/volunteers')

    expect(await screen.findByRole('heading', { level: 1, name: 'Volunteers' })).toBeVisible()
    const table = await screen.findByRole('table', { name: 'Volunteers' })
    expect(within(table).getByRole('link', { name: 'Ada Lovelace' })).toHaveAttribute(
      'href',
      `/relationships/volunteers/${PERSON}`,
    )
    expect(within(table).getByText('ada@example.org')).toBeInTheDocument()
    // The word, and a shape that repeats the definition's tone: pending is a caution (diamond), active positive (dot).
    expect(within(table).getByText('Pending')).toBeVisible()
    expect(within(table).getByText('Pending').querySelector('[data-indicator]')).toHaveAttribute(
      'data-indicator',
      'diamond',
    )
    expect(within(table).getByText('Active').querySelector('[data-indicator]')).toHaveAttribute(
      'data-indicator',
      'dot',
    )
    expect(within(table).getAllByText('—').length).toBeGreaterThanOrEqual(2)
    expect(within(table).getByRole('columnheader', { name: 'Status since' })).toBeInTheDocument()
    await expectNoAxeViolations()
  })

  it('asks the relationship API for one page of 25 and never for the broader People directory', async () => {
    const api = serveRelationships([wireVolunteerType()])
    api.on(`GET ${LIST}`, json(directoryPage([wireEntry()])))
    renderApp('/relationships/volunteers')
    await screen.findByRole('table', { name: 'Volunteers' })

    expect(listCalls(api).map((c) => c.path)).toEqual([`${LIST}?page=1&per_page=25`])
    expect(api.calls.filter((c) => c.path.startsWith('/api/v1/admin/people'))).toHaveLength(0)
  })

  it('searches only when submitted, trims the search, and returns to page 1', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireVolunteerType()])
    api.on(`GET ${LIST}`, (call) => {
      const page = new URL(call.path, 'http://x').searchParams.get('page')
      return json(
        directoryPage(
          [wireEntry({ person: { id: PERSON, display_name: `On page ${page ?? '?'}` } })],
          { page: Number(page), total: 60 },
        ),
      )
    })
    renderApp('/relationships/volunteers')
    await screen.findByRole('link', { name: 'On page 1' })
    expect(screen.getByRole('status', { name: '' })).toHaveTextContent(
      'Page 1 of 3 (60 volunteers)',
    )

    await user.click(screen.getByRole('button', { name: 'Next' }))
    await screen.findByRole('link', { name: 'On page 2' })

    await user.type(screen.getByRole('searchbox'), '  grace ')
    expect(listCalls(api)).toHaveLength(2) // typing asked for nothing
    await user.click(screen.getByRole('button', { name: 'Search' }))
    await screen.findByRole('link', { name: 'On page 1' })
    expect(listCalls(api).at(-1)?.path).toBe(`${LIST}?page=1&per_page=25&q=grace`)
  })

  it("filters by the definition's own statuses, and a new filter returns to page 1", async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireVolunteerType()])
    api.on(`GET ${LIST}`, json(directoryPage([wireEntry()], { total: 60 })))
    renderApp('/relationships/volunteers')
    await screen.findByRole('table', { name: 'Volunteers' })

    const select = screen.getByRole('combobox', { name: 'Status' })
    expect(
      within(select)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['All statuses', 'Pending', 'Active', 'Inactive'])
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await waitFor(() => {
      expect(listCalls(api).at(-1)?.path).toContain('page=2')
    })
    await user.selectOptions(select, 'inactive')
    await waitFor(() => {
      expect(listCalls(api).at(-1)?.path).toBe(`${LIST}?page=1&per_page=25&status=inactive`)
    })
  })

  it('ignores an answer to a search the person has already moved on from', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireVolunteerType()])
    const first = deferred<Response>()
    api.on(`GET ${LIST}`, (call) =>
      call.path.includes('q=slow')
        ? first.promise
        : json(directoryPage([wireEntry({ person: { id: PERSON, display_name: 'Fast answer' } })])),
    )
    renderApp('/relationships/volunteers')
    await screen.findByRole('link', { name: 'Fast answer' })

    await user.type(screen.getByRole('searchbox'), 'slow')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    await user.clear(screen.getByRole('searchbox'))
    await user.type(screen.getByRole('searchbox'), 'quick')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    await screen.findByRole('link', { name: 'Fast answer' })

    first.resolve(
      json(directoryPage([wireEntry({ person: { id: PERSON, display_name: 'Stale answer' } })])),
    )
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByText('Stale answer')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Fast answer' })).toBeInTheDocument()
  })

  it('says so when nobody is recorded, offering to record someone to a manager, and differently when a search finds none', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireVolunteerType()])
    api.on(`GET ${LIST}`, json(directoryPage([])))
    renderApp('/relationships/volunteers')

    expect(await screen.findByText('There are no volunteers yet.')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Record a Volunteer' }).length).toBeGreaterThan(0)
    await user.type(screen.getByRole('searchbox'), 'nobody')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    expect(await screen.findByText('No volunteers match.')).toBeInTheDocument()
  })

  it("recovers from a failed load, and never shows the server's own words", async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireVolunteerType()])
    let healthy = false
    api.on(`GET ${LIST}`, () =>
      healthy ? json(directoryPage([wireEntry()])) : json({ message: 'SQLSTATE[42S02] boom' }, 500),
    )
    renderApp('/relationships/volunteers')

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('The service is temporarily unavailable.')
    expect(alert).not.toHaveTextContent(/SQLSTATE|boom/)
    healthy = true
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('link', { name: 'Ada Lovelace' })).toBeInTheDocument()
  })

  it("tells a directory that is too broad to compose what to do, in the Console's words", async () => {
    const api = serveRelationships([wireVolunteerType()])
    api.on(`GET ${LIST}`, json({ message: 'Server sentence.', code: 'search_too_broad' }, 422))
    renderApp('/relationships/volunteers')
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'That search matches too many people to show.',
    )
  })

  it('renders a third type through the same component: its words, its statuses, no contact columns, an unknown tone as neutral', async () => {
    const api = serveRelationships([wireApprenticeType()])
    api.on(
      'GET /api/v1/admin/relationships/apprentices',
      json(
        directoryPage([
          wireEntry({ status: 'applied', basics: null }),
          wireEntry({ relationship_id: REL_ID_2, status: 'training', basics: null, person: GWEN }),
        ]),
      ),
    )
    renderApp('/relationships/apprentices')

    const table = await screen.findByRole('table', { name: 'Apprentices' })
    expect(screen.getByRole('searchbox', { name: 'Name' })).toBeInTheDocument()
    expect(
      within(table).queryByRole('columnheader', { name: 'Primary email' }),
    ).not.toBeInTheDocument()
    expect(within(table).getByText('In training')).toBeVisible()
    const unknownTone = within(table).getByText('Applied')
    expect(unknownTone.querySelector('[data-indicator]')).toHaveAttribute('data-indicator', 'ring')
    expect(within(table).getAllByRole('row')).toHaveLength(3)
  })

  it('shows a view-only operator the directory and no way to record, and a manager who cannot view neither', async () => {
    const viewOnly = serveRelationships([
      wireVolunteerType({
        actions: { view: true, manage: false, delete: false, provision_default_role: false },
      }),
    ])
    viewOnly.on(`GET ${LIST}`, json(directoryPage([wireEntry()])))
    const view = renderApp('/relationships/volunteers')
    await screen.findByRole('table', { name: 'Volunteers' })
    expect(screen.queryByRole('link', { name: 'Record a Volunteer' })).not.toBeInTheDocument()
    view.unmount()

    const manageOnly = serveRelationships([
      wireVolunteerType({
        actions: { view: false, manage: true, delete: true, provision_default_role: false },
      }),
    ])
    renderApp('/relationships/volunteers')
    expect(await screen.findByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    expect(listCalls(manageOnly)).toHaveLength(0)
    expect(pageBody()).not.toHaveTextContent('Ada')
  })

  it('is not found for a slug the served definitions do not hold, without asking the server for it', async () => {
    const api = serveRelationships([wireGuardianType()])
    renderApp('/relationships/nothing-here')
    expect(await screen.findByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible()
    expect(api.calls.filter((c) => c.path.includes('/relationships/nothing-here'))).toHaveLength(0)
  })
})
