import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../../test/a11y.ts'
import { operator, serveOperator } from '../../../test/admin.ts'
import { pageBody } from '../../../test/deferred.ts'
import { json } from '../../../test/fakeApi.ts'
import { filePath, resourceNotFound } from '../../../test/library.ts'
import { renderApp } from '../../../test/renderApp.tsx'
import { CARD2_ID, CARD_ID, CATEGORY_ID, PACK_ID } from '../../../test/resources.ts'
import {
  RESOURCE_LIBRARY_PACK,
  THREE_VIEWER_CARDS,
  VIEWER,
  viewerFilePath,
  wireViewerCard,
  wireViewerFileCard,
  wireViewerPack,
} from '../../../test/viewerLibrary.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const PATH = `/resource-library/${PACK_ID}`

function servePack(pack: Record<string, unknown>) {
  const api = serveOperator(operator(VIEWER))
  api.on(`GET ${RESOURCE_LIBRARY_PACK}`, () => json(pack))
  return api
}

async function openPack(pack: Record<string, unknown>, path = PATH) {
  const user = userEvent.setup()
  const api = servePack(pack)
  renderApp(path)
  await screen.findByRole('heading', { level: 1, name: String(pack.title) })
  return { user, api }
}

const cardNav = () => screen.getByRole('navigation', { name: 'Cards in this Resource' })
const location = () => screen.getByTestId('location').textContent

// Three Cards: a published one, a Draft, and a published one narrowed to Volunteers inside a Pack for Guardians and Volunteers.
const course = (extra: Record<string, unknown> = {}) =>
  wireViewerPack({
    title: 'The course',
    is_series: true,
    audiences: ['guardian', 'volunteer'],
    cards: THREE_VIEWER_CARDS,
    ...extra,
  })

describe('the viewer reading a Pack', () => {
  it('offers every Card, the Draft too, by title, in the order sent, numbered across all of them', async () => {
    await openPack(course())

    const links = within(cardNav()).getAllByRole('link')
    expect(links.map((link) => link.textContent)).toEqual([
      'Before you start',
      'On the day (still being written)Draft',
      'Afterwards',
    ])
    expect(within(cardNav()).getByRole('link', { name: /Before you start/ })).toHaveAttribute(
      'aria-current',
      'true',
    )
    // A Series is numbered over all its Cards, Drafts included: 1 of 3, not 1 of 2.
    expect(screen.getByText('Card 1 of 3')).toBeInTheDocument()
  })

  it('marks the Pack as it was written: state, audiences, Cards and Drafts, Category, and who last edited', async () => {
    await openPack(course({ state: 'draft' }))

    const header = pageBody()
    expect(within(header).getByRole('heading', { level: 1, name: 'The course' })).toBeVisible()
    expect(header).toHaveTextContent('Draft')
    expect(header).toHaveTextContent('In Training guides')
    expect(header).toHaveTextContent('For Guardians, Volunteers')
    expect(header).toHaveTextContent('Series · 3 Cards, 1 Draft Card')
    expect(header).toHaveTextContent('Last edited by Hone Guardian on')
  })

  it('says a Published Pack is Published, and does not call it a Draft', async () => {
    await openPack(course({ state: 'published' }))
    const status = within(pageBody()).getAllByText(/^(Draft|Published)$/)
    expect(status[0]?.textContent).toBe('Published')
  })

  it('marks a Draft Card when it is read, with whom it is for, and says when a Card narrows its Pack', async () => {
    const { user } = await openPack(course())

    // The first Card follows its Pack and is published.
    let facts = screen.getByRole('article')
    expect(facts).toHaveTextContent('For Guardians')
    expect(facts).not.toHaveTextContent('Narrower than its Pack')

    await user.click(within(cardNav()).getByRole('link', { name: /On the day/ }))
    facts = screen.getByRole('article')
    expect(within(facts).getByText('Draft')).toBeInTheDocument()
    expect(location()).toContain(`?card=${CARD2_ID}`)

    await user.click(within(cardNav()).getByRole('link', { name: /Afterwards/ }))
    facts = screen.getByRole('article')
    expect(facts).toHaveTextContent('For Volunteers')
    expect(facts).toHaveTextContent(
      'Narrower than its Pack: the Pack is for Guardians, Volunteers.',
    )
    expect(within(facts).queryByText('Draft')).not.toBeInTheDocument()
  })

  it('opens at the Card in the address, Draft or not, and reads the first when it names none that exists', async () => {
    await openPack(course(), `${PATH}?card=${CARD2_ID}`)
    expect(screen.getByRole('heading', { level: 2, name: /On the day/ })).toBeVisible()
  })

  it('reads the first Card when the address names one that is not in the Pack', async () => {
    await openPack(course(), `${PATH}?card=01J0000000000000000000NOPE`)
    expect(screen.getByRole('heading', { level: 2, name: /Before you start/ })).toBeVisible()
  })

  it('moves through a Series over all its Cards with Previous and Next', async () => {
    const { user } = await openPack(course())
    const series = screen.getByRole('navigation', { name: 'Series' })
    expect(within(series).queryByRole('link', { name: /Previous/ })).not.toBeInTheDocument()

    await user.click(within(series).getByRole('link', { name: /Next Card: On the day/ }))
    expect(screen.getByText('Card 2 of 3')).toBeInTheDocument()
    expect(
      within(screen.getByRole('navigation', { name: 'Series' })).getByRole('link', {
        name: /Previous Card: Before you start/,
      }),
    ).toBeInTheDocument()
    expect(location()).toContain(CARD2_ID)
  })

  it('says an empty Pack is empty, with its Category and audiences, rather than drawing nothing', async () => {
    await openPack(
      wireViewerPack({ title: 'Nothing yet', cards: [], card_count: 0, audiences: ['member'] }),
    )
    expect(screen.getByText('This Pack has no Cards yet.')).toBeVisible()
    expect(pageBody()).toHaveTextContent('For Members')
    expect(pageBody()).toHaveTextContent('No Cards yet')
    expect(
      screen.queryByRole('navigation', { name: 'Cards in this Resource' }),
    ).not.toBeInTheDocument()
    expect(screen.queryByRole('article')).not.toBeInTheDocument()
  })

  it('shows a Pack that has no Category as Uncategorized', async () => {
    await openPack(wireViewerPack({ category: null }))
    expect(pageBody()).toHaveTextContent('In Uncategorized')
    expect(pageBody()).not.toHaveTextContent(CATEGORY_ID)
  })

  it('reads a single Card plainly, with no browsing controls, but still says it is a Draft', async () => {
    await openPack(
      wireViewerPack({
        title: 'One draft',
        state: 'draft',
        cards: [wireViewerCard({ state: 'draft', title: 'Only Card' })],
      }),
    )
    expect(
      screen.queryByRole('navigation', { name: 'Cards in this Resource' }),
    ).not.toBeInTheDocument()
    expect(within(screen.getByRole('article')).getByText('Draft')).toBeInTheDocument()
  })

  it('says it could not be found, without detail, for a Pack that does not exist, and offers the way back', async () => {
    const api = serveOperator(operator(VIEWER))
    api.on(`GET ${RESOURCE_LIBRARY_PACK}`, () => resourceNotFound())
    renderApp(PATH)

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Resource not found' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to the Resource Library' })).toHaveAttribute(
      'href',
      '/resource-library',
    )
    expect(screen.queryByText(/Server wording/)).not.toBeInTheDocument()
  })

  it('asks only the viewer’s Pack route: never the audience reader, and nothing of management', async () => {
    const { api } = await openPack(course())
    expect(api.callsTo(`GET ${RESOURCE_LIBRARY_PACK}`)).toHaveLength(1)
    expect(api.calls.filter((c) => c.path.startsWith('/api/v1/admin/my-resources'))).toEqual([])
    expect(api.calls.filter((c) => c.path.startsWith('/api/v1/admin/resources'))).toEqual([])
  })

  it('offers no way to change anything: no edit, publish, reorder, delete or approve', async () => {
    await openPack(course({ state: 'draft' }))
    expect(
      screen.queryByRole('button', { name: /edit|publish|unpublish|delete|reorder|approve|move/i }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole('link', { name: /edit|publish|delete|manage|reorder|approve/i }),
    ).not.toBeInTheDocument()
  })

  it('has no accessibility violations, with Draft markers, narrowing and a Series', async () => {
    const { user } = await openPack(course({ state: 'draft' }))
    await expectNoAxeViolations()
    await user.click(within(cardNav()).getByRole('link', { name: /Afterwards/ }))
    await expectNoAxeViolations()
  })
})

describe('a File Card the viewer reads', () => {
  const withFile = (card: Record<string, unknown>) =>
    wireViewerPack({ title: 'Files', cards: [card] })

  it('is served through the viewer’s own file route, as a PDF to view and to download', async () => {
    await openPack(withFile(wireViewerFileCard({ id: CARD_ID, title: 'Handbook', state: 'draft' })))

    const view = screen.getByRole('link', { name: /View PDF/ })
    expect(view).toHaveAttribute('href', `${viewerFilePath(PACK_ID, CARD_ID)}?disposition=inline`)
    expect(view).toHaveAttribute('target', '_blank')
    expect(screen.getByRole('link', { name: /Download/ })).toHaveAttribute(
      'href',
      viewerFilePath(PACK_ID, CARD_ID),
    )
  })

  it('offers no file at all when the answer named another reader’s route or a management one', async () => {
    for (const path of [
      filePath(PACK_ID, CARD_ID),
      `/api/v1/admin/resources/packs/${PACK_ID}/cards/${CARD_ID}/file`,
      `https://evil.example${viewerFilePath()}`,
    ]) {
      const { unmount } = await renderPackWithPath(path)
      expect(screen.getByText('This file cannot be shown right now.')).toBeVisible()
      expect(screen.queryByRole('link', { name: /Download/ })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /View PDF/ })).not.toBeInTheDocument()
      unmount()
    }
  })

  async function renderPackWithPath(path: string) {
    const card = wireViewerFileCard({
      id: CARD_ID,
      file: {
        name: 'a.pdf',
        media_type: 'application/pdf',
        byte_size: 10,
        download_path: path,
      },
    })
    const api = servePack(withFile(card))
    const view = renderApp(PATH)
    await screen.findByRole('heading', { level: 1, name: 'Files' })
    expect(api.calls.length).toBeGreaterThan(0)
    return view
  }
})
