import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { shown } from '../../admin/time.ts'
import { operator, serveOperator } from '../../test/admin.ts'
import { expectNoAxeViolations } from '../../test/a11y.ts'
import {
  DISCUSSION2_ID,
  DISCUSSION_ID,
  discussionsPage,
  invalidDiscussionInput,
  messagesPage,
  OTHER,
  wireDiscussion,
  wireMessage,
} from '../../test/discussions.ts'
import { json, type FakeApi } from '../../test/fakeApi.ts'
import { renderApp } from '../../test/renderApp.tsx'
import { navigation, visibleNavigation } from '../../shell/navigation.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const VIEW = ['console.access', 'discussions.view']
const PARTICIPATE = ['console.access', 'discussions.view', 'discussions.participate']

const LIST = 'GET /api/v1/admin/discussions' as const
const START = 'POST /api/v1/admin/discussions' as const

/** The list requests (they carry a query string, which `callsTo` does not match). */
const listCalls = (api: FakeApi) =>
  api.calls.filter((c) => c.method === 'GET' && c.path.startsWith('/api/v1/admin/discussions?'))

/** The item at `index`, or a failure that says so (the repository allows neither a cast nor a non-null assertion). */
function nth<T>(list: T[], index: number): T {
  const item = list[index]
  if (item === undefined) throw new Error(`There is no item ${String(index)}.`)
  return item
}

const lastList = (api: FakeApi) => {
  const call = listCalls(api).at(-1)
  if (call === undefined) throw new Error('no list request was made')
  return new URLSearchParams(call.path.split('?')[1])
}

const settled = () =>
  waitFor(() => {
    expect(document.querySelector('[aria-busy="true"]')).toBeNull()
  })

async function openList(options: { capabilities?: string[]; page?: unknown; at?: string } = {}) {
  const user = userEvent.setup()
  const api = serveOperator(operator(options.capabilities ?? PARTICIPATE))
  api.on(LIST, () => json(options.page ?? discussionsPage([wireDiscussion()])))
  renderApp(options.at ?? '/discussions')
  await screen.findByRole('heading', { level: 1, name: 'Discussions' })
  await settled()
  return { user, api }
}

describe('the discussion list', () => {
  it("shows each discussion's title, state in words, who started it, how many messages and when it was last active", async () => {
    await openList({
      page: discussionsPage([
        wireDiscussion({ last_activity_at: '2026-10-02T09:00:00Z', message_count: 4 }),
        wireDiscussion({
          id: DISCUSSION2_ID,
          title: 'Budget review',
          state: 'resolved',
          creator: { id: OTHER.id, display_name: null },
          message_count: 1,
        }),
      ]),
    })

    const table = screen.getByRole('table', { name: 'Discussions' })
    const rows = within(table).getAllByRole('row').slice(1)
    expect(rows).toHaveLength(2)
    const first = within(nth(rows, 0))
    expect(first.getByRole('link', { name: 'Where do we meet?' })).toHaveAttribute(
      'href',
      `/discussions/${DISCUSSION_ID}`,
    )
    expect(rows[0]).toHaveTextContent('Open') // state is text, not colour alone
    expect(rows[0]).toHaveTextContent('Hone Guardian')
    expect(rows[0]).toHaveTextContent('4')
    expect(first.getByText(shown('2026-10-02T09:00:00Z'))).toHaveAttribute(
      'datetime',
      '2026-10-02T09:00:00Z',
    )
    expect(rows[1]).toHaveTextContent('Resolved')
    // An author Identity no longer holds is "Unknown person", never a raw id.
    expect(rows[1]).toHaveTextContent('Unknown person')
    expect(rows[1]).not.toHaveTextContent(OTHER.id)
  })

  it("keeps the server's order, whatever the titles and times would sort to", async () => {
    await openList({
      page: discussionsPage([
        wireDiscussion({
          id: DISCUSSION2_ID,
          title: 'Zebra',
          last_activity_at: '2026-01-01T00:00:00Z',
        }),
        wireDiscussion({ title: 'Aardvark', last_activity_at: '2026-12-01T00:00:00Z' }),
      ]),
    })

    const titles = within(screen.getByRole('table', { name: 'Discussions' }))
      .getAllByRole('link')
      .map((a) => a.textContent)
    expect(titles).toEqual(['Zebra', 'Aardvark'])
  })

  it('asks for the first page, bounded, and shows no reply preview or message-search affordance', async () => {
    const { api } = await openList()

    const params = lastList(api)
    expect(params.get('page')).toBe('1')
    expect(params.get('per_page')).toBe('25')
    expect(params.has('state')).toBe(false)
    expect(params.has('q')).toBe(false)
    expect(screen.getByRole('searchbox', { name: 'Search titles' })).toBeInTheDocument()
    expect(screen.queryByRole('searchbox', { name: /message|text|reply/i })).not.toBeInTheDocument()
    expect(document.body).not.toHaveTextContent('Thoughts on the venue')
  })

  it('searches titles only when the search is submitted, not on every keystroke, and returns to page 1', async () => {
    const { user, api } = await openList({
      page: discussionsPage([wireDiscussion()], { total: 60, last_page: 3 }),
    })
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await waitFor(() => {
      expect(lastList(api).get('page')).toBe('2')
    })
    const before = listCalls(api).length

    await user.type(screen.getByRole('searchbox', { name: 'Search titles' }), 'venue')
    expect(listCalls(api)).toHaveLength(before) // typing alone asks for nothing

    await user.click(screen.getByRole('button', { name: 'Search' }))
    await waitFor(() => {
      expect(lastList(api).get('q')).toBe('venue')
    })
    expect(lastList(api).get('page')).toBe('1')
  })

  it('filters by Open or Resolved, returns to page 1, and can show all again', async () => {
    const { user, api } = await openList({
      page: discussionsPage([wireDiscussion()], { total: 60, last_page: 3 }),
    })
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await waitFor(() => {
      expect(lastList(api).get('page')).toBe('2')
    })

    await user.selectOptions(screen.getByRole('combobox', { name: 'Show' }), 'resolved')
    await waitFor(() => {
      expect(lastList(api).get('state')).toBe('resolved')
    })
    expect(lastList(api).get('page')).toBe('1')

    await user.selectOptions(screen.getByRole('combobox', { name: 'Show' }), 'open')
    await waitFor(() => {
      expect(lastList(api).get('state')).toBe('open')
    })

    await user.selectOptions(screen.getByRole('combobox', { name: 'Show' }), '')
    await waitFor(() => {
      expect(lastList(api).has('state')).toBe(false)
    })
  })

  it('pages by the server, with a labelled pager and the total in words', async () => {
    const { user, api } = await openList({
      page: discussionsPage([wireDiscussion()], { total: 60, last_page: 3 }),
    })

    const pager = screen.getByRole('navigation', { name: 'Pages' })
    expect(pager).toHaveTextContent('Page 1 of 3 (60 discussions)')
    expect(within(pager).getByRole('button', { name: 'Previous' })).toBeDisabled()
    await user.click(within(pager).getByRole('button', { name: 'Next' }))
    await waitFor(() => {
      expect(lastList(api).get('page')).toBe('2')
    })
  })

  it('says there is nothing yet, and offers the first discussion only to someone who may start one', async () => {
    await openList({ page: discussionsPage([]) })
    expect(screen.getByText('There are no discussions yet.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Start the first discussion' })).toHaveAttribute(
      'href',
      '/discussions/new',
    )
  })

  it('says there is nothing yet without an offer to someone who may only read', async () => {
    await openList({ capabilities: VIEW, page: discussionsPage([]) })
    expect(screen.getByText('There are no discussions yet.')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /start/i })).not.toBeInTheDocument()
  })

  it('says nothing matches when a search or filter finds none, and does not offer to start one', async () => {
    const { user, api } = await openList()
    api.on(LIST, json(discussionsPage([])))

    await user.type(screen.getByRole('searchbox', { name: 'Search titles' }), 'nothing like it')
    await user.click(screen.getByRole('button', { name: 'Search' }))

    expect(await screen.findByText('No discussions match.')).toBeInTheDocument()
    expect(
      screen.queryByRole('link', { name: 'Start the first discussion' }),
    ).not.toBeInTheDocument()
  })

  it('says plainly that it could not load, and asks again on request', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEW))
    api.on(LIST, json({ message: 'SQLSTATE[HY000] secret detail' }, 500))
    renderApp('/discussions')

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(
      'The service is temporarily unavailable. Try again in a moment.',
    )
    expect(alert).not.toHaveTextContent('SQLSTATE')

    api.on(LIST, json(discussionsPage([wireDiscussion()])))
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('link', { name: 'Where do we meet?' })).toBeInTheDocument()
  })

  it('has no accessibility violation', async () => {
    await openList({ page: discussionsPage([wireDiscussion()], { total: 60, last_page: 3 }) })
    await expectNoAxeViolations()
  })
})

describe('reaching Discussions', () => {
  it('shows the section to someone who may view, with the start link only for someone who may take part', () => {
    const labels = (held: string[]) =>
      visibleNavigation(navigation, (c) => held.includes(c))
        .find((section) => section.id === 'discussions')
        ?.groups?.flatMap((g) => g.items.map((i) => i.label))

    expect(labels(VIEW)).toEqual(['All discussions'])
    expect(labels(PARTICIPATE)).toEqual(['All discussions', 'Start a discussion'])
    // Participating alone reaches only the start page: reading is its own capability, and one never stands in for the other.
    expect(labels(['console.access', 'discussions.participate'])).toEqual(['Start a discussion'])
    expect(labels(['console.access'])).toBeUndefined()
  })

  it('puts the section in the rail for someone who may view', async () => {
    await openList({ capabilities: VIEW })
    expect(
      within(screen.getByRole('navigation', { name: 'Console' })).getByRole('link', {
        name: 'Discussions',
      }),
    ).toBeInTheDocument()
  })

  it('refuses the list without discussions.view, and asks the server for nothing', async () => {
    const api = serveOperator(operator(['console.access', 'discussions.participate']))
    renderApp('/discussions')

    expect(await screen.findByRole('heading', { name: 'Not permitted' })).toBeInTheDocument()
    expect(api.calls.filter((c) => c.path.includes('/admin/discussions'))).toEqual([])
  })

  it('does not let participate stand in for view: the thread is refused too', async () => {
    const api = serveOperator(operator(['console.access', 'discussions.participate']))
    renderApp(`/discussions/${DISCUSSION_ID}`)

    expect(await screen.findByRole('heading', { name: 'Not permitted' })).toBeInTheDocument()
    expect(api.calls.filter((c) => c.path.includes('/admin/discussions'))).toEqual([])
  })

  it('offers no Discussions at all to someone with neither capability', async () => {
    serveOperator(operator(['console.access']))
    renderApp('/')
    await screen.findByRole('navigation', { name: 'Console' })
    expect(screen.queryByRole('link', { name: 'Discussions' })).not.toBeInTheDocument()
  })

  it('refuses the start page without discussions.participate: reading does not stand in for it', async () => {
    serveOperator(operator(VIEW))
    renderApp('/discussions/new')
    expect(await screen.findByRole('heading', { name: 'Not permitted' })).toBeInTheDocument()
  })
})

describe('starting a discussion', () => {
  async function openStart() {
    const user = userEvent.setup()
    const api = serveOperator(operator(PARTICIPATE))
    renderApp('/discussions/new')
    await screen.findByRole('heading', { level: 1, name: 'Start a discussion' })
    return { user, api }
  }

  it('asks for a title and an opening message and nothing else: no author, category, priority or audience', async () => {
    await openStart()

    const form = screen.getByRole('form', { name: 'Start a discussion' })
    expect(
      within(form)
        .getAllByRole('textbox')
        .map((c) => c.getAttribute('name')),
    ).toEqual(['title', 'body'])
    expect(within(form).queryByRole('combobox')).not.toBeInTheDocument()
    expect(within(form).queryByRole('checkbox')).not.toBeInTheDocument()
    expect(form).not.toHaveTextContent(/author|category|priority|audience|assign|private|attach/i)
  })

  it('sends only the title and the text, then opens the new discussion', async () => {
    const { user, api } = await openStart()
    api.on(START, () =>
      json(
        wireDiscussion({
          id: DISCUSSION2_ID,
          title: 'New topic',
          creator: { id: '01J0000000000000000000PRSN', display_name: 'Gwen Guardian' },
        }),
        201,
      ),
    )
    api.on(`GET /api/v1/admin/discussions/${DISCUSSION2_ID}`, () =>
      json(wireDiscussion({ id: DISCUSSION2_ID, title: 'New topic' })),
    )
    api.on(
      `GET /api/v1/admin/discussions/${DISCUSSION2_ID}/messages`,
      json(messagesPage([wireMessage()])),
    )

    await user.type(screen.getByRole('textbox', { name: 'Title' }), 'New topic')
    await user.type(screen.getByRole('textbox', { name: 'Opening message' }), 'First words')
    await user.click(screen.getByRole('button', { name: 'Start discussion' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'New topic' })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent(`/discussions/${DISCUSSION2_ID}`)
    // Exactly the two fields: the author is never sent, because it is never chosen.
    expect(api.callsTo(START)[0]?.body).toEqual({ title: 'New topic', body: 'First words' })
  })

  it("shows the server's validation beside the field, and stays on the form", async () => {
    const { user, api } = await openStart()
    api.on(
      START,
      invalidDiscussionInput(
        'title',
        'A title is a single line and may not contain control characters.',
      ),
    )

    await user.type(screen.getByRole('textbox', { name: 'Title' }), 'Bad title')
    await user.type(screen.getByRole('textbox', { name: 'Opening message' }), 'Words')
    await user.click(screen.getByRole('button', { name: 'Start discussion' }))

    const title = await screen.findByRole('textbox', { name: 'Title' })
    await waitFor(() => {
      expect(title).toHaveAccessibleDescription(
        'A title is a single line and may not contain control characters.',
      )
    })
    expect(title).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByTestId('location')).toHaveTextContent('/discussions/new')
  })

  it('says plainly when the service is unavailable', async () => {
    const { user, api } = await openStart()
    api.on(START, json({ message: 'boom' }, 503))

    await user.type(screen.getByRole('textbox', { name: 'Title' }), 'T')
    await user.type(screen.getByRole('textbox', { name: 'Opening message' }), 'B')
    await user.click(screen.getByRole('button', { name: 'Start discussion' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('temporarily unavailable')
  })

  it('has no accessibility violation', async () => {
    await openStart()
    await expectNoAxeViolations()
  })
})
