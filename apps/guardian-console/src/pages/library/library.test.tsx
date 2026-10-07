import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../test/a11y.ts'
import { operator, serveOperator } from '../../test/admin.ts'
import { deferred, nth, pageBody } from '../../test/deferred.ts'
import { json } from '../../test/fakeApi.ts'
import {
  LIBRARY,
  libraryOf,
  READER,
  TWO_GROUPS,
  wireGroup,
  wireListedEntry,
} from '../../test/library.ts'
import { renderApp } from '../../test/renderApp.tsx'
import { CATEGORY2_ID, PACK_ID } from '../../test/resources.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

function serve(groups: unknown[] = TWO_GROUPS) {
  const api = serveOperator(operator(READER))
  api.on(`GET ${LIBRARY}`, () => libraryOf(...groups))
  return api
}

async function openLibrary(groups?: unknown[]) {
  const user = userEvent.setup()
  const api = serve(groups)
  renderApp('/resource-library')
  await screen.findByRole('heading', { level: 1, name: 'Resource Library' })
  await within(pageBody()).findAllByRole('heading', { level: 2 })
  return { user, api }
}

const libraryRequests = (api: ReturnType<typeof serve>) =>
  api.calls.filter((call) => call.method === 'GET' && call.path.startsWith(LIBRARY))

const lastQuery = (api: ReturnType<typeof serve>) =>
  new URL(nth(libraryRequests(api), libraryRequests(api).length - 1).path, 'http://x').searchParams

describe('the Resource Library home', () => {
  it('groups Packs under their Category, in the order the server sent them', async () => {
    await openLibrary()

    expect(
      within(pageBody())
        .getAllByRole('heading', { level: 2 })
        .map((h) => h.textContent),
    ).toEqual(['Training guides', 'Recipes'])
    const training = screen.getByRole('region', { name: 'Training guides' })
    expect(
      within(training)
        .getAllByRole('link')
        .map((a) => a.textContent),
    ).toEqual(['Welcome pack', 'Zebra rules'])
    expect(
      within(screen.getByRole('region', { name: 'Recipes' })).getByRole('link', { name: 'Soup' }),
    ).toBeInTheDocument()
  })

  it('does not sort: a server order that is not alphabetical is shown as it came', async () => {
    await openLibrary([
      wireGroup(
        [
          wireListedEntry({ id: PACK_ID, title: 'Zebra rules' }),
          wireListedEntry({ id: '01J00000000000000000PACK002', title: 'Apple guide' }),
        ],
        { id: CATEGORY2_ID, name: 'Zed' },
      ),
      wireGroup([wireListedEntry({ id: '01J00000000000000000PACK003', title: 'Moss' })], {
        id: '01J000000000000000CATEGORY1',
        name: 'Alpha',
      }),
    ])
    expect(
      within(pageBody())
        .getAllByRole('heading', { level: 2 })
        .map((h) => h.textContent),
    ).toEqual(['Zed', 'Alpha'])
    const zed = screen.getByRole('region', { name: 'Zed' })
    expect(
      within(zed)
        .getAllByRole('link')
        .map((a) => a.textContent),
    ).toEqual(['Zebra rules', 'Apple guide'])
  })

  it('links each Pack to its page, shows a summary, and says Series and size only where they help', async () => {
    await openLibrary([
      wireGroup([
        wireListedEntry({ title: 'One card', summary: 'A short thing.', card_count: 1 }),
        wireListedEntry({ id: '01J00000000000000000PACK002', title: 'Several', card_count: 4 }),
        wireListedEntry({
          id: '01J00000000000000000PACK003',
          title: 'A course',
          is_series: true,
          card_count: 5,
        }),
        wireListedEntry({
          id: '01J00000000000000000PACK004',
          title: 'A lone series',
          is_series: true,
          card_count: 1,
        }),
      ]),
    ])

    expect(screen.getByRole('link', { name: 'One card' })).toHaveAttribute(
      'href',
      `/resource-library/${PACK_ID}`,
    )
    expect(screen.getByText('A short thing.')).toBeInTheDocument()
    expect(screen.getByText('4 Cards')).toBeInTheDocument()
    expect(screen.getByText('Series · 5 Cards')).toBeInTheDocument()
    // One visible Card is a simple Resource: neither a count nor Series is made of it.
    expect(screen.queryByText(/1 Card/)).not.toBeInTheDocument()
    expect(screen.queryByText('Series')).not.toBeInTheDocument()
  })

  it('asks only the library: nothing of management is ever requested', async () => {
    const { api } = await openLibrary()

    expect(api.calls.filter((call) => call.path.includes('/admin/resources'))).toEqual([])
    expect(libraryRequests(api)).toHaveLength(1)
    expect(nth(libraryRequests(api), 0).path).toBe(LIBRARY)
  })

  it('lets the server search: the words are sent, and nothing is filtered here', async () => {
    const { user, api } = await openLibrary()
    api.on(`GET ${LIBRARY}?q=zebra`, () =>
      libraryOf(wireGroup([wireListedEntry({ id: PACK_ID, title: 'Zebra rules' })])),
    )

    await user.type(screen.getByLabelText('Search Resources'), '  zebra ')
    await user.click(screen.getByRole('button', { name: 'Search' }))

    await waitFor(() => {
      expect(lastQuery(api).get('q')).toBe('zebra')
    })
    expect(await screen.findByRole('link', { name: 'Zebra rules' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Welcome pack' })).not.toBeInTheDocument()
  })

  it('filters by Category through the server, offering the Categories the whole library listed', async () => {
    const { user, api } = await openLibrary()
    api.on(`GET ${LIBRARY}?category=${CATEGORY2_ID}`, () =>
      libraryOf(
        wireGroup([wireListedEntry({ id: '01J00000000000000000PACK003', title: 'Soup' })], {
          id: CATEGORY2_ID,
          name: 'Recipes',
        }),
      ),
    )
    const select = screen.getByLabelText('Category')
    expect(
      within(select)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['All Categories', 'Training guides', 'Recipes'])

    await user.selectOptions(select, CATEGORY2_ID)

    await waitFor(() => {
      expect(lastQuery(api).get('category')).toBe(CATEGORY2_ID)
    })
    expect(await screen.findByRole('link', { name: 'Soup' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Training guides' })).not.toBeInTheDocument()
    // The filtered answer lists one Category, but the filter still offers all of them.
    expect(within(screen.getByLabelText('Category')).getAllByRole('option')).toHaveLength(3)
  })

  it('says there is nothing yet, when the library is empty', async () => {
    serve([])
    renderApp('/resource-library')

    expect(await screen.findByText('There are no Resources for you yet.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Clear search/ })).not.toBeInTheDocument()
  })

  it('says nothing matches, and clears the search and the filter, when a search finds nothing', async () => {
    const { user, api } = await openLibrary()
    api.on(`GET ${LIBRARY}?q=nothing`, () => libraryOf())

    await user.type(screen.getByLabelText('Search Resources'), 'nothing')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    expect(await screen.findByText('No Resources match.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Clear search and filter' }))
    expect(
      await screen.findByRole('heading', { level: 2, name: 'Training guides' }),
    ).toBeInTheDocument()
    expect(screen.getByLabelText('Search Resources')).toHaveValue('')
    expect(lastQuery(api).has('q')).toBe(false)
  })

  it('says it could not load, and asks again on request', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(READER))
    api.on(`GET ${LIBRARY}`, () => json({ message: 'x' }, 503))
    renderApp('/resource-library')

    expect(
      await screen.findByText('The service is temporarily unavailable. Try again in a moment.'),
    ).toBeInTheDocument()
    expect(within(pageBody()).queryByRole('heading', { level: 2 })).not.toBeInTheDocument()

    api.on(`GET ${LIBRARY}`, () => libraryOf(...TWO_GROUPS))
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(
      await screen.findByRole('heading', { level: 2, name: 'Training guides' }),
    ).toBeInTheDocument()
  })

  it('never lets an older answer replace a newer search', async () => {
    const { user, api } = await openLibrary()
    const slow = deferred<Response>()
    api.on(`GET ${LIBRARY}?q=old`, () => slow.promise)
    api.on(`GET ${LIBRARY}?q=new`, () =>
      libraryOf(wireGroup([wireListedEntry({ id: PACK_ID, title: 'The new answer' })])),
    )

    await user.type(screen.getByLabelText('Search Resources'), 'old')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    await user.clear(screen.getByLabelText('Search Resources'))
    await user.type(screen.getByLabelText('Search Resources'), 'new')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    expect(await screen.findByRole('link', { name: 'The new answer' })).toBeInTheDocument()

    slow.resolve(libraryOf(wireGroup([wireListedEntry({ title: 'The stale answer' })])))
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByRole('link', { name: 'The stale answer' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'The new answer' })).toBeInTheDocument()
  })

  it('has one h1, labelled search and no accessibility violations', async () => {
    await openLibrary()

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getByRole('search', { name: 'Find Resources' })).toBeInTheDocument()
    await expectNoAxeViolations()
  })
})
