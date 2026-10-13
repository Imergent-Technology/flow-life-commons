import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../../test/a11y.ts'
import { operator, serveOperator } from '../../../test/admin.ts'
import { deferred, nth, pageBody } from '../../../test/deferred.ts'
import { json } from '../../../test/fakeApi.ts'
import { renderApp } from '../../../test/renderApp.tsx'
import { CATEGORY2_ID } from '../../../test/resources.ts'
import {
  DRAFT_PACK_ID,
  RESOURCE_LIBRARY,
  UNCATEGORIZED_PACK_ID,
  VIEWER,
  VIEWER_GROUPS,
  viewerLibraryOf,
  wireViewerGroup,
  wireViewerListed,
} from '../../../test/viewerLibrary.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

function serve(groups: unknown[] = VIEWER_GROUPS) {
  const api = serveOperator(operator(VIEWER))
  api.on(`GET ${RESOURCE_LIBRARY}`, () => viewerLibraryOf(...groups))
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

const requests = (api: ReturnType<typeof serve>) =>
  api.calls.filter((call) => call.method === 'GET' && call.path.startsWith(RESOURCE_LIBRARY))
const lastQuery = (api: ReturnType<typeof serve>) =>
  new URL(nth(requests(api), requests(api).length - 1).path, 'http://x').searchParams
const entry = (title: string) => {
  const link = within(pageBody()).getByRole('link', { name: title })
  const item = link.closest('li')
  if (item === null) throw new Error(`${title} is not in a list.`)
  return item
}

describe('the privileged viewer’s library home', () => {
  it('lists every Pack, Drafts included, under its Category, with the Packs that have none last as Uncategorized', async () => {
    await openLibrary()

    expect(
      within(pageBody())
        .getAllByRole('heading', { level: 2 })
        .map((heading) => heading.textContent),
    ).toEqual(['Training guides', 'Recipes', 'Uncategorized'])
    const training = screen.getByRole('region', { name: 'Training guides' })
    expect(
      within(training)
        .getAllByRole('link')
        .map((link) => link.textContent),
    ).toEqual(['Welcome pack', 'Secret draft plan'])
    const loose = screen.getByRole('region', { name: 'Uncategorized' })
    expect(within(loose).getByRole('link', { name: 'Loose notes' })).toHaveAttribute(
      'href',
      `/resource-library/${UNCATEGORIZED_PACK_ID}`,
    )
  })

  it('says how each Pack was written, in words: Draft or Published, whom it is for, how many Cards and Drafts, and who last edited', async () => {
    await openLibrary()

    const welcome = entry('Welcome pack')
    expect(welcome).toHaveTextContent('Published')
    expect(welcome).toHaveTextContent('For Guardians, Members')
    expect(welcome).toHaveTextContent('3 Cards, 1 Draft Card')
    expect(welcome).toHaveTextContent('Last edited by Hone Guardian on')

    const secret = entry('Secret draft plan')
    expect(secret).toHaveTextContent('Draft')
    expect(secret).not.toHaveTextContent('Published')
    expect(secret).toHaveTextContent('For Volunteers')
    expect(secret).toHaveTextContent('2 Cards, 2 Draft Cards')

    // An empty Pack, and a Pack aimed at no one yet, are said, not left blank.
    expect(entry('Soup')).toHaveTextContent('No Cards yet')
    expect(entry('Loose notes')).toHaveTextContent('Not aimed at anyone yet')
  })

  it('does not suggest the viewer is limited to those audiences, or that the labels are about the reader', async () => {
    await openLibrary()
    const text = pageBody().textContent
    expect(text).toContain('whoever it is aimed at')
    expect(text).toContain('You can read all of it')
    expect(text).not.toMatch(/visible only to|only visible to|you are eligible|for you/i)
  })

  it('marks Draft and Published by a shape as well as words, never colour alone', async () => {
    await openLibrary()
    const badges = within(pageBody())
      .getAllByText(/^(Draft|Published)$/)
      .map((badge) => badge.textContent)
    expect(badges).toContain('Draft')
    expect(badges).toContain('Published')
    // The Badge draws its shape beside the words, and hides the shape from assistive technology.
    const draft = within(entry('Secret draft plan')).getByText('Draft')
    expect(draft.querySelector('[aria-hidden="true"]')).not.toBeNull()
  })

  it('asks only the privileged viewer: never the audience reader, and nothing of management', async () => {
    const { api } = await openLibrary()
    expect(requests(api).length).toBeGreaterThan(0)
    expect(api.calls.filter((c) => c.path.startsWith('/api/v1/admin/my-resources'))).toEqual([])
    expect(api.calls.filter((c) => c.path.startsWith('/api/v1/admin/resources'))).toEqual([])
  })
})

describe('the viewer’s filters', () => {
  it('offers All, Published and Drafts for publication, and the three audiences, defaulting to everything', async () => {
    await openLibrary()
    const publication = screen.getByLabelText('Publication')
    expect(publication).toHaveValue('')
    expect(
      within(publication)
        .getAllByRole('option')
        .map((option) => option.textContent),
    ).toEqual(['All', 'Published', 'Drafts'])
    const audience = screen.getByLabelText('Audience')
    expect(audience).toHaveValue('')
    expect(
      within(audience)
        .getAllByRole('option')
        .map((option) => option.textContent),
    ).toEqual(['All audiences', 'Guardians', 'Members', 'Volunteers'])
  })

  it('sends the publication filter to the server, and shows what the server answered without filtering it here', async () => {
    const { user, api } = await openLibrary()
    api.on(`GET ${RESOURCE_LIBRARY}?publication=draft`, () =>
      viewerLibraryOf(
        wireViewerGroup([
          wireViewerListed({ id: DRAFT_PACK_ID, title: 'Secret draft plan', state: 'draft' }),
          // A Published Pack that holds a Draft Card is part of "Drafts" (R8), and it is the server's to say so.
          wireViewerListed({ title: 'Welcome pack', draft_card_count: 1, card_count: 3 }),
        ]),
      ),
    )

    await user.selectOptions(screen.getByLabelText('Publication'), 'draft')

    await waitFor(() => {
      expect(lastQuery(api).get('publication')).toBe('draft')
    })
    await waitFor(() => {
      expect(
        within(pageBody())
          .getAllByRole('link')
          .map((link) => link.textContent),
      ).toEqual(['Secret draft plan', 'Welcome pack'])
    })
    // The Published Pack that holds a Draft Card is in the answer because the server put it there, and says so.
    expect(entry('Welcome pack')).toHaveTextContent('3 Cards, 1 Draft Card')
  })

  it('sends the audience filter to the server', async () => {
    const { user, api } = await openLibrary()
    api.on(`GET ${RESOURCE_LIBRARY}?audience=volunteer`, () =>
      viewerLibraryOf(wireViewerGroup([wireViewerListed({ title: 'Only for Volunteers' })])),
    )

    await user.selectOptions(screen.getByLabelText('Audience'), 'volunteer')

    await waitFor(() => {
      expect(lastQuery(api).get('audience')).toBe('volunteer')
    })
    expect(await screen.findByRole('link', { name: 'Only for Volunteers' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Soup' })).not.toBeInTheDocument()
  })

  it('combines the Category, the words and both filters, and offers the Categories the whole library listed', async () => {
    const { user, api } = await openLibrary()
    api.on(`GET ${RESOURCE_LIBRARY}?category=${CATEGORY2_ID}`, () =>
      viewerLibraryOf(VIEWER_GROUPS[1]),
    )
    const category = screen.getByLabelText('Category')
    await waitFor(() => {
      expect(category).toBeEnabled()
    })
    // The unfiltered library listed named Categories only: Uncategorized cannot be filtered to.
    expect(
      within(category)
        .getAllByRole('option')
        .map((option) => option.textContent),
    ).toEqual(['All Categories', 'Training guides', 'Recipes'])

    await user.selectOptions(category, CATEGORY2_ID)
    await waitFor(() => {
      expect(lastQuery(api).get('category')).toBe(CATEGORY2_ID)
    })
    // The filtered answer lists fewer Categories, and the choices do not shrink to match it.
    await screen.findByRole('link', { name: 'Soup' })
    expect(within(screen.getByLabelText('Category')).getAllByRole('option')).toHaveLength(3)

    api.on(
      `GET ${RESOURCE_LIBRARY}?category=${CATEGORY2_ID}&q=soup&publication=published&audience=member`,
      () => viewerLibraryOf(VIEWER_GROUPS[1]),
    )
    await user.type(screen.getByLabelText('Search Resources'), '  soup ')
    await user.selectOptions(screen.getByLabelText('Publication'), 'published')
    await user.selectOptions(screen.getByLabelText('Audience'), 'member')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    await waitFor(() => {
      const query = lastQuery(api)
      expect(query.get('category')).toBe(CATEGORY2_ID)
      expect(query.get('q')).toBe('soup')
      expect(query.get('publication')).toBe('published')
      expect(query.get('audience')).toBe('member')
    })
  })

  it('searches only when submitted', async () => {
    const { user, api } = await openLibrary()
    const before = requests(api).length
    await user.type(screen.getByLabelText('Search Resources'), 'draft plan')
    expect(requests(api)).toHaveLength(before)

    await user.click(screen.getByRole('button', { name: 'Search' }))
    await waitFor(() => {
      expect(lastQuery(api).get('q')).toBe('draft plan')
    })
  })

  it('says nothing matches, and clears every filter, when a filtered answer is empty', async () => {
    const { user, api } = await openLibrary()
    api.on(`GET ${RESOURCE_LIBRARY}?publication=draft&audience=member`, () => viewerLibraryOf())
    await user.selectOptions(screen.getByLabelText('Publication'), 'draft')
    await user.selectOptions(screen.getByLabelText('Audience'), 'member')

    expect(await screen.findByText('No Resources match.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Clear search and filters' }))

    expect(await screen.findByRole('heading', { level: 2, name: 'Training guides' })).toBeVisible()
    expect(screen.getByLabelText('Publication')).toHaveValue('')
    expect(screen.getByLabelText('Audience')).toHaveValue('')
    expect(lastQuery(api).size).toBe(0)
  })

  it('says there is nothing yet when the library is empty, without a clearing action', async () => {
    serve([])
    renderApp('/resource-library')
    expect(await screen.findByText('There are no Resources yet.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Clear/ })).not.toBeInTheDocument()
  })

  it('shows the server’s refusal of a filter value, says nothing was changed, and offers to try again', async () => {
    const { user, api } = await openLibrary()
    let refused = true
    api.on(`GET ${RESOURCE_LIBRARY}?publication=draft`, () =>
      refused
        ? json(
            { message: 'The selected publication is invalid.', errors: { publication: ['x'] } },
            422,
          )
        : viewerLibraryOf(VIEWER_GROUPS[0]),
    )

    await user.selectOptions(screen.getByLabelText('Publication'), 'draft')
    expect(await screen.findByRole('alert')).toBeInTheDocument()
    // The refusal is shown as a failure, and the page does not keep the previous answer on screen as if it were this one's.
    expect(screen.queryByRole('heading', { level: 2, name: 'Recipes' })).not.toBeInTheDocument()

    refused = false
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('heading', { level: 2, name: 'Training guides' })).toBeVisible()
  })

  it('never lets an older answer replace a newer search', async () => {
    const { user, api } = await openLibrary()
    const slow = deferred<Response>()
    api.on(`GET ${RESOURCE_LIBRARY}?q=old`, () => slow.promise)
    api.on(`GET ${RESOURCE_LIBRARY}?q=new`, () =>
      viewerLibraryOf(wireViewerGroup([wireViewerListed({ title: 'The new answer' })])),
    )

    await user.type(screen.getByLabelText('Search Resources'), 'old')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    await user.clear(screen.getByLabelText('Search Resources'))
    await user.type(screen.getByLabelText('Search Resources'), 'new')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    expect(await screen.findByRole('link', { name: 'The new answer' })).toBeInTheDocument()

    slow.resolve(
      viewerLibraryOf(wireViewerGroup([wireViewerListed({ title: 'The stale answer' })])),
    )
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByRole('link', { name: 'The stale answer' })).not.toBeInTheDocument()
  })

  it('says it could not load, and asks again on request', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEWER))
    let calls = 0
    api.on(`GET ${RESOURCE_LIBRARY}`, () => {
      calls += 1
      return calls === 1 ? json({ message: 'x' }, 500) : viewerLibraryOf(...VIEWER_GROUPS)
    })
    renderApp('/resource-library')

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('heading', { level: 2, name: 'Training guides' })).toBeVisible()
  })

  it('has one h1, labelled search, labelled filters and no accessibility violations', async () => {
    await openLibrary()
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getByRole('search', { name: 'Find Resources' })).toBeInTheDocument()
    await expectNoAxeViolations()
  })

  it('offers no action on any Pack: it is a reader, not management', async () => {
    await openLibrary()
    expect(
      within(pageBody()).queryByRole('button', {
        name: /edit|publish|unpublish|delete|reorder|approve|add a/i,
      }),
    ).not.toBeInTheDocument()
  })
})
