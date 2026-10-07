import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useNavigate } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import App from '../../App.tsx'
import { expectNoAxeViolations } from '../../test/a11y.ts'
import { operator, serveOperator } from '../../test/admin.ts'
import { pageBody } from '../../test/deferred.ts'
import { json } from '../../test/fakeApi.ts'
import { LocationProbe } from '../../test/LocationProbe.tsx'
import {
  filePath,
  LIBRARY,
  LIBRARY_PACK,
  numbered,
  READER,
  resourceNotFound,
  THREE_CARDS,
  wireDeliveredCard,
  wireDeliveredFile,
  wireFileCard,
  wireLibraryPack,
  wireLinkCard,
} from '../../test/library.ts'
import { renderApp } from '../../test/renderApp.tsx'
import { CARD2_ID, CARD3_ID, CARD_ID, EMPTY_DOC, PACK_ID } from '../../test/resources.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const PATH = `/resource-library/${PACK_ID}`

function servePack(pack: Record<string, unknown>) {
  const api = serveOperator(operator(READER))
  api.on(`GET ${LIBRARY_PACK}`, () => json(pack))
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
const linkIn = (nav: HTMLElement, name: string) => within(nav).getByRole('link', { name })

const series = (cards = THREE_CARDS) =>
  wireLibraryPack({ title: 'The course', is_series: true, cards })
const several = (cards = THREE_CARDS) => wireLibraryPack({ title: 'The guide', cards })

// --- One Card: a simple Resource ---------------------------------------------------------------------------------------------

describe('a Pack with one visible Card', () => {
  it('is read plainly: the content, and none of the browsing controls', async () => {
    await openPack(wireLibraryPack())

    expect(screen.getByText('We are open every day.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: 'Welcome pack' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 2, name: 'Opening hours' })).toBeInTheDocument()
    expect(
      screen.queryByRole('navigation', { name: 'Cards in this Resource' }),
    ).not.toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Series' })).not.toBeInTheDocument()
    expect(screen.queryByText(/Card 1 of 1/)).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Previous|Next/ })).not.toBeInTheDocument()
  })

  it('does not say the same title twice: a Card titled as its Pack has no heading of its own', async () => {
    await openPack(
      wireLibraryPack({
        title: 'Opening hours',
        cards: [wireDeliveredCard({ title: ' opening HOURS ' })],
      }),
    )

    expect(screen.getAllByRole('heading', { name: /opening hours/i })).toHaveLength(1)
    expect(screen.getByRole('article', { name: 'opening HOURS' })).toBeInTheDocument()
    expect(screen.getByText('We are open every day.')).toBeInTheDocument()
  })

  it('is just as simple when the Pack is a Series: one Card has no sequence to move through', async () => {
    await openPack(wireLibraryPack({ is_series: true }))

    expect(screen.queryByRole('navigation', { name: 'Series' })).not.toBeInTheDocument()
    expect(screen.queryByText(/Card \d+ of \d+/)).not.toBeInTheDocument()
  })

  it('shows the Pack’s summary and Category, and the Card’s summary only where there is no content to read', async () => {
    await openPack(
      wireLibraryPack({
        summary: 'Everything a new Guardian needs.',
        cards: [
          wireDeliveredCard({
            summary: 'A summary that would only repeat the content.',
          }),
        ],
      }),
    )

    expect(screen.getByText('Everything a new Guardian needs.')).toBeInTheDocument()
    expect(screen.getByText('In Training guides')).toBeInTheDocument()
    expect(
      screen.queryByText('A summary that would only repeat the content.'),
    ).not.toBeInTheDocument()
  })

  it('describes a Card that has no content by its summary', async () => {
    await openPack(
      wireLibraryPack({
        cards: [
          wireDeliveredCard({
            summary: 'Read this before you start.',
            content: { format: 'prosemirror', version: 1, document: EMPTY_DOC },
          }),
        ],
      }),
    )

    expect(screen.getByText('Read this before you start.')).toBeInTheDocument()
  })

  it('has no accessibility violations', async () => {
    await openPack(wireLibraryPack())
    await expectNoAxeViolations()
  })
})

// --- Several Cards ------------------------------------------------------------------------------------------------------------

describe('a Pack with several visible Cards', () => {
  it('offers each Card by its title, in the order delivered, and reads the first', async () => {
    await openPack(several())

    const nav = cardNav()
    expect(
      within(nav)
        .getAllByRole('link')
        .map((a) => a.textContent),
    ).toEqual(['Before you start', 'On the day', 'Afterwards'])
    expect(screen.getByRole('heading', { level: 2, name: 'Before you start' })).toBeInTheDocument()
    expect(screen.getByText('We are open every day.')).toBeInTheDocument()
    expect(screen.queryByText('Arrive by nine.')).not.toBeInTheDocument()
  })

  it('does not sort: the order delivered is the order shown', async () => {
    await openPack(
      several(
        numbered([
          wireDeliveredCard({ id: CARD_ID, title: 'Zulu' }),
          wireDeliveredCard({ id: CARD2_ID, title: 'Alpha' }),
        ]),
      ),
    )

    expect(
      within(cardNav())
        .getAllByRole('link')
        .map((a) => a.textContent),
    ).toEqual(['Zulu', 'Alpha'])
  })

  it('marks the Card being read, and not by colour alone', async () => {
    await openPack(several())

    const current = linkIn(cardNav(), 'Before you start')
    expect(current).toHaveAttribute('aria-current', 'true')
    expect(linkIn(cardNav(), 'On the day')).not.toHaveAttribute('aria-current')
    // A heavier edge and a bolder weight carry it as well as the colour.
    expect(current.className).toContain('font-semibold')
    expect(current.className).toContain('border-primary')
  })

  it('shows the chosen Card at once, from what already arrived, and keeps the choice in the address', async () => {
    const { user, api } = await openPack(several())

    await user.click(linkIn(cardNav(), 'On the day'))

    expect(await screen.findByText('Arrive by nine.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 2, name: 'On the day' })).toBeInTheDocument()
    expect(screen.queryByText('We are open every day.')).not.toBeInTheDocument()
    expect(linkIn(cardNav(), 'On the day')).toHaveAttribute('aria-current', 'true')
    expect(location()).toBe(`${PATH}?card=${CARD2_ID}`)
    // Nothing was asked for to show it.
    expect(api.callsTo(`GET ${LIBRARY_PACK}`)).toHaveLength(1)
  })

  it('opens at the Card in the address, so a refresh or a shared link keeps the place', async () => {
    await openPack(several(), `${PATH}?card=${CARD3_ID}`)

    expect(screen.getByRole('heading', { level: 2, name: 'Afterwards' })).toBeInTheDocument()
    expect(linkIn(cardNav(), 'Afterwards')).toHaveAttribute('aria-current', 'true')
  })

  it('reads the first Card, saying nothing, when the address names one that was not delivered', async () => {
    await openPack(several(), `${PATH}?card=01J000000000000000NOSUCHCARD`)

    expect(screen.getByRole('heading', { level: 2, name: 'Before you start' })).toBeInTheDocument()
    expect(linkIn(cardNav(), 'Before you start')).toHaveAttribute('aria-current', 'true')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.queryByText(/not found|could not be found|no such/i)).not.toBeInTheDocument()
  })

  it('adds no Previous or Next to a Pack that is not a Series', async () => {
    await openPack(several())

    expect(screen.queryByRole('navigation', { name: 'Series' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Previous Card|Next Card/ })).not.toBeInTheDocument()
    expect(screen.queryByText(/Card \d+ of \d+/)).not.toBeInTheDocument()
  })

  it('follows the browser’s Back and Forward through the Cards read', async () => {
    const user = userEvent.setup()
    servePack(several())
    render(
      <MemoryRouter initialEntries={[PATH]}>
        <App />
        <LocationProbe />
        <History />
      </MemoryRouter>,
    )
    await screen.findByRole('heading', { level: 1, name: 'The guide' })

    await user.click(linkIn(cardNav(), 'On the day'))
    await screen.findByText('Arrive by nine.')
    await user.click(linkIn(cardNav(), 'Afterwards'))
    await screen.findByText('Send your notes.')

    await user.click(screen.getByRole('button', { name: 'history back' }))
    expect(await screen.findByText('Arrive by nine.')).toBeInTheDocument()
    expect(linkIn(cardNav(), 'On the day')).toHaveAttribute('aria-current', 'true')

    await user.click(screen.getByRole('button', { name: 'history back' }))
    expect(await screen.findByText('We are open every day.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'history forward' }))
    expect(await screen.findByText('Arrive by nine.')).toBeInTheDocument()
  })

  it('has no accessibility violations', async () => {
    await openPack(several())
    await expectNoAxeViolations()
  })
})

function History() {
  const navigate = useNavigate()
  return (
    <>
      <button
        type="button"
        onClick={() => {
          void navigate(-1)
        }}
      >
        history back
      </button>
      <button
        type="button"
        onClick={() => {
          void navigate(1)
        }}
      >
        history forward
      </button>
    </>
  )
}

// --- Series ---------------------------------------------------------------------------------------------------------------

describe('a Series', () => {
  const controls = () => screen.getByRole('navigation', { name: 'Series' })

  it('moves between its Cards with Previous and Next, saying where the reader is', async () => {
    const { user } = await openPack(series())

    expect(within(controls()).getByText('Card 1 of 3')).toBeInTheDocument()
    await user.click(within(controls()).getByRole('link', { name: 'Next Card: On the day' }))
    expect(await screen.findByText('Arrive by nine.')).toBeInTheDocument()
    expect(within(controls()).getByText('Card 2 of 3')).toBeInTheDocument()
    expect(
      within(controls()).getByRole('link', { name: 'Previous Card: Before you start' }),
    ).toBeInTheDocument()
    expect(
      within(controls()).getByRole('link', { name: 'Next Card: Afterwards' }),
    ).toBeInTheDocument()

    await user.click(
      within(controls()).getByRole('link', { name: 'Previous Card: Before you start' }),
    )
    expect(await screen.findByText('We are open every day.')).toBeInTheDocument()
  })

  it('has no Previous on the first Card and no Next on the last', async () => {
    const { user } = await openPack(series())

    expect(within(controls()).queryByRole('link', { name: /Previous/ })).not.toBeInTheDocument()
    expect(within(controls()).getByRole('link', { name: /Next Card/ })).toBeInTheDocument()

    await user.click(linkIn(cardNav(), 'Afterwards'))
    await screen.findByText('Send your notes.')
    expect(within(controls()).queryByRole('link', { name: /Next/ })).not.toBeInTheDocument()
    expect(within(controls()).getByRole('link', { name: /Previous Card/ })).toBeInTheDocument()
    expect(within(controls()).getByText('Card 3 of 3')).toBeInTheDocument()
  })

  it('still offers direct Card navigation as well', async () => {
    await openPack(series())
    expect(within(cardNav()).getAllByRole('link')).toHaveLength(3)
  })

  it('does not show a Card the viewer may not see, or a gap where it was: A and C are 1 and 2 of 2', async () => {
    // Delivery has already left the hidden middle Card out and numbered what remains 1..n.
    const delivered = numbered([THREE_CARDS[0] ?? {}, THREE_CARDS[2] ?? {}])
    const { user, api } = await openPack(series(delivered))

    expect(
      within(cardNav())
        .getAllByRole('link')
        .map((a) => a.textContent),
    ).toEqual(['Before you start', 'Afterwards'])
    expect(within(controls()).getByText('Card 1 of 2')).toBeInTheDocument()
    expect(screen.queryByText('On the day')).not.toBeInTheDocument()

    await user.click(within(controls()).getByRole('link', { name: 'Next Card: Afterwards' }))
    expect(await screen.findByText('Send your notes.')).toBeInTheDocument()
    expect(within(controls()).getByText('Card 2 of 2')).toBeInTheDocument()
    expect(
      within(controls()).getByRole('link', { name: 'Previous Card: Before you start' }),
    ).toBeInTheDocument()
    expect(within(controls()).queryByRole('link', { name: /Next/ })).not.toBeInTheDocument()
    expect(document.body.textContent).not.toContain('On the day')
    expect(location()).not.toContain(CARD2_ID)
    expect(api.calls.filter((c) => c.path.includes('/admin/resources'))).toEqual([])
  })

  it('has no accessibility violations', async () => {
    await openPack(series())
    await expectNoAxeViolations()
  })
})

// --- Focus --------------------------------------------------------------------------------------------------------------------

describe('where focus goes', () => {
  it('starts on the page’s heading, and moves to the Card’s title when another Card is chosen', async () => {
    const { user } = await openPack(series())
    await waitFor(() => {
      expect(screen.getByRole('heading', { level: 1 })).toHaveFocus()
    })

    await user.click(linkIn(cardNav(), 'On the day'))
    await waitFor(() => {
      expect(screen.getByRole('heading', { level: 2, name: 'On the day' })).toHaveFocus()
    })

    await user.click(screen.getByRole('link', { name: 'Next Card: Afterwards' }))
    await waitFor(() => {
      expect(screen.getByRole('heading', { level: 2, name: 'Afterwards' })).toHaveFocus()
    })
  })

  it('does not move focus when the Card has not changed', async () => {
    const { user } = await openPack(several())
    const link = linkIn(cardNav(), 'Before you start')
    await user.click(link)

    // The Card is the one already being read: choosing it again is not a change, so its title is not pulled to.
    expect(link).toHaveFocus()
    expect(screen.getByRole('heading', { level: 2, name: 'Before you start' })).not.toHaveFocus()
  })
})

// --- Summaries as help text ---------------------------------------------------------------------------------------------------

describe('a Card’s summary in the navigation', () => {
  it('appears on hover, and describes the link', async () => {
    const { user } = await openPack(several())
    const link = linkIn(cardNav(), 'On the day')

    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    await user.hover(link)

    const tip = await screen.findByRole('tooltip')
    expect(tip).toHaveTextContent('How the day runs.')
    expect(link).toHaveAccessibleDescription('How the day runs.')
    await user.unhover(link)
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
  })

  it('appears on keyboard focus too, and goes on Escape', async () => {
    const { user } = await openPack(several())

    // The page heading has focus; Tab reaches the Cards in order.
    await user.tab()
    const first = linkIn(cardNav(), 'Before you start')
    expect(first).toHaveFocus()
    expect(await screen.findByRole('tooltip')).toHaveTextContent('What to bring.')

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    expect(first).toHaveFocus()

    await user.tab()
    expect(linkIn(cardNav(), 'On the day')).toHaveFocus()
    expect(await screen.findByRole('tooltip')).toHaveTextContent('How the day runs.')
    await user.tab()
    expect(screen.getAllByRole('tooltip', { hidden: true }).filter((t) => !t.hidden)).toHaveLength(
      1,
    )
  })

  it('draws nothing for a Card with no summary, and says nothing about it to a screen reader', async () => {
    await openPack(
      several(
        numbered([
          wireDeliveredCard({ id: CARD_ID, title: 'Plain', summary: '' }),
          wireDeliveredCard({ id: CARD2_ID, title: 'Other', summary: 'Has one.' }),
        ]),
      ),
    )
    const plain = linkIn(cardNav(), 'Plain')

    expect(plain).not.toHaveAttribute('aria-describedby')
    expect(screen.getAllByRole('tooltip', { hidden: true })).toHaveLength(1)
  })

  it('shows the summary, never the content', async () => {
    const { user } = await openPack(several())
    await user.hover(linkIn(cardNav(), 'On the day'))

    const tip = await screen.findByRole('tooltip')
    expect(tip).not.toHaveTextContent('Arrive by nine.')
    expect(within(tip).queryByRole('link')).not.toBeInTheDocument()
    expect(within(tip).queryByRole('button')).not.toBeInTheDocument()
  })
})

// --- Not found, and non-disclosure --------------------------------------------------------------------------------------------

describe('a Resource that is not available', () => {
  async function missing(response: () => Response) {
    const api = serveOperator(operator(READER))
    api.on(`GET ${LIBRARY_PACK}`, response)
    renderApp(PATH)
    await screen.findByRole('heading', { level: 1, name: 'Resource not found' })
    return api
  }

  it('says it could not be found, and offers the way back, without saying why', async () => {
    const api = await missing(() => resourceNotFound())

    expect(screen.getByText('That Resource could not be found.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to the Resource Library' })).toHaveAttribute(
      'href',
      '/resource-library',
    )
    expect(document.body.textContent).not.toMatch(
      /draft|unpublished|member|audience|hidden|permission/i,
    )
    // It does not go looking in management for it.
    expect(api.calls.filter((c) => c.path.includes('/admin/resources'))).toEqual([])
    expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument()
  })

  it('is the same for every reason: whatever the 404 says', async () => {
    await missing(() => new Response(null, { status: 404 }))
    const page = document.querySelector('[data-page-width]')?.textContent

    expect(page).toContain('That Resource could not be found.')
    expect(page).not.toMatch(/draft|unpublished|member|audience|hidden/i)
  })

  it('is not made of an unknown Card in an available Pack: that reads the first Card', async () => {
    await openPack(several(), `${PATH}?card=01J00000000000000HIDDENCARD`)
    expect(screen.queryByText('Resource not found')).not.toBeInTheDocument()
  })

  it('says a failure is a failure and offers to try again', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(READER))
    api.on(`GET ${LIBRARY_PACK}`, () => json({ message: 'x' }, 503))
    renderApp(PATH)

    expect(
      await screen.findByText('The service is temporarily unavailable. Try again in a moment.'),
    ).toBeInTheDocument()
    api.on(`GET ${LIBRARY_PACK}`, () => json(wireLibraryPack()))
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Welcome pack' }),
    ).toBeInTheDocument()
  })

  it('asks only the library: never a management endpoint, for any Card or any Pack', async () => {
    const { user, api } = await openPack(series())
    await user.click(screen.getByRole('link', { name: 'Next Card: On the day' }))
    await screen.findByText('Arrive by nine.')

    expect(api.calls.filter((c) => c.path.includes('/admin/resources'))).toEqual([])
    expect(api.calls.filter((c) => c.path.startsWith(LIBRARY)).map((c) => c.path)).toEqual([
      LIBRARY_PACK,
    ])
  })
})

// --- Basic and External link --------------------------------------------------------------------------------------------------

describe('a Basic Card and an External link Card', () => {
  it('offers a Basic Card’s related link as a quiet action, safely', async () => {
    await openPack(
      wireLibraryPack({
        cards: [wireDeliveredCard({ uri: 'https://example.org/more', title: 'Opening hours' })],
      }),
    )

    const link = screen.getByRole('link', { name: /Open related link/ })
    expect(link).toHaveAttribute('href', 'https://example.org/more')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', 'noopener noreferrer')
    expect(screen.getByText('We are open every day.')).toBeInTheDocument()
  })

  it('does not invent a link for a Basic Card that has none', async () => {
    await openPack(wireLibraryPack())
    expect(screen.queryByRole('link', { name: /Open/ })).not.toBeInTheDocument()
  })

  it('offers an External link Card as a clear action to the validated address, in a new tab', async () => {
    const { api } = await openPack(
      wireLibraryPack({
        cards: [wireLinkCard({ title: 'The venue', uri: 'https://example.org/venue?a=1' })],
      }),
    )

    const link = screen.getByRole('link', {
      name: 'Open link: The venue (opens example.org in a new tab)',
    })
    expect(link).toHaveAttribute('href', 'https://example.org/venue?a=1')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', 'noopener noreferrer')
    expect(screen.getByText('Opens example.org in a new tab.')).toBeInTheDocument()
    // The summary stands in for the description, since the Card has no content.
    expect(screen.getByText('Where we meet.')).toBeInTheDocument()
    // The platform and the Console never visit it: nothing but the library was asked.
    expect(api.calls.filter((c) => c.path.includes('example.org'))).toEqual([])
  })

  it.each([
    'javascript:alert(1)',
    'data:text/html,<b>x</b>',
    'ftp://example.org/x',
    '//example.org/x',
  ])('never makes a link of an address that is not http or https: %s', async (uri) => {
    await openPack(wireLibraryPack({ cards: [wireLinkCard({ uri })] }))

    expect(screen.queryByRole('link', { name: /Open link/ })).not.toBeInTheDocument()
    expect(document.querySelector('a[href^="javascript"]')).toBeNull()
  })

  it('wraps a very long address and title rather than widening the page', async () => {
    await openPack(
      wireLibraryPack({
        cards: [
          wireLinkCard({
            title: `K${'e'.repeat(150)}y`,
            uri: `https://example.org/${'u'.repeat(400)}`,
          }),
        ],
      }),
    )
    expect(within(pageBody()).getByRole('heading', { level: 2 }).className).toContain(
      'wrap-anywhere',
    )
    expect(screen.getByText(/^Opens example\.org/).className).toContain('wrap-anywhere')
  })
})

// --- Files --------------------------------------------------------------------------------------------------------------------

describe('a File Card', () => {
  const png = (overrides: Record<string, unknown> = {}) =>
    wireFileCard({
      title: 'Floor plan',
      file: wireDeliveredFile({
        name: 'floor-plan.png',
        media_type: 'image/png',
        byte_size: 204_800,
        ...overrides,
      }),
    })

  it('shows a raster image in the Card, through the library’s own file route, named by its title', async () => {
    await openPack(wireLibraryPack({ cards: [png()] }))

    const image = screen.getByRole('img', { name: 'Floor plan' })
    expect(image).toHaveAttribute('src', `${filePath()}?disposition=inline`)
    expect(image).toHaveAttribute('loading', 'lazy')
    expect(image.className).toContain('max-w-full')
    expect(image.getAttribute('src')).not.toContain('/admin/resources/')
    expect(screen.getByRole('link', { name: /Download/ })).toHaveAttribute('href', filePath())
    // No "View PDF" for an image: it is already in the page.
    expect(screen.queryByRole('link', { name: /View PDF/ })).not.toBeInTheDocument()
  })

  it.each(['image/jpeg', 'image/webp', 'image/gif'])('shows %s the same way', async (mediaType) => {
    await openPack(wireLibraryPack({ cards: [png({ media_type: mediaType })] }))
    expect(screen.getByRole('img', { name: 'Floor plan' })).toBeInTheDocument()
  })

  it('says an image that will not load cannot be shown, offers to try again, and offers no download of it', async () => {
    const user = userEvent.setup()
    servePack(wireLibraryPack({ cards: [png()] }))
    renderApp(PATH)
    const image = await screen.findByRole('img', { name: 'Floor plan' })

    fireEvent.error(image)

    expect(await screen.findByText('This image cannot be shown right now.')).toBeInTheDocument()
    expect(within(pageBody()).queryByRole('img')).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Download/ })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('img', { name: 'Floor plan' })).toBeInTheDocument()
  })

  it('offers a PDF to be viewed in a new tab, and to be downloaded, and does not embed it', async () => {
    await openPack(wireLibraryPack({ cards: [wireFileCard()] }))

    const view = screen.getByRole('link', { name: /View PDF/ })
    expect(view).toHaveAttribute('href', `${filePath()}?disposition=inline`)
    expect(view).toHaveAttribute('target', '_blank')
    expect(view).toHaveAttribute('rel', 'noopener noreferrer')
    expect(screen.getByRole('link', { name: /Download/ })).toHaveAttribute('href', filePath())
    expect(document.querySelector('iframe, embed, object')).toBeNull()
    expect(within(pageBody()).queryByRole('img')).not.toBeInTheDocument()
  })

  it.each([
    ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Word document'],
    ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Excel workbook'],
    [
      'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'PowerPoint presentation',
    ],
    ['text/plain', 'Text file'],
    ['text/csv', 'CSV file'],
  ])('offers %s as a download alone', async (mediaType, label) => {
    await openPack(
      wireLibraryPack({
        cards: [
          wireFileCard({ file: wireDeliveredFile({ name: 'notes.bin', media_type: mediaType }) }),
        ],
      }),
    )

    expect(screen.getByRole('link', { name: /Download/ })).toHaveAttribute('href', filePath())
    expect(screen.queryByRole('link', { name: /View PDF/ })).not.toBeInTheDocument()
    expect(within(pageBody()).queryByRole('img')).not.toBeInTheDocument()
    expect(screen.getByText(label)).toBeInTheDocument()
  })

  it('shows the file name, the kind of file and the size, and nothing about where it is kept', async () => {
    await openPack(wireLibraryPack({ cards: [wireFileCard()] }))

    expect(screen.getByText('Welcome handbook.pdf', { selector: 'dd' })).toBeInTheDocument()
    expect(screen.getByText('PDF')).toBeInTheDocument()
    expect(screen.getByText('1.0 MB')).toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/storage|disk|digest|sha-?256|asset|\/private\//i)
  })

  it('never uses a path that is not the library’s own file route', async () => {
    await openPack(
      wireLibraryPack({
        cards: [
          wireFileCard({
            file: wireDeliveredFile({
              download_path: `/api/v1/admin/resources/packs/${PACK_ID}/cards/${CARD_ID}/file`,
            }),
          }),
        ],
      }),
    )

    expect(screen.getByText('This file cannot be shown right now.')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Download|View PDF/ })).not.toBeInTheDocument()
  })

  it('uses the Card’s own route when several Cards have files', async () => {
    const { user } = await openPack(
      series(
        numbered([
          wireFileCard({
            id: CARD_ID,
            title: 'First file',
            file: wireDeliveredFile({ download_path: filePath(PACK_ID, CARD_ID) }),
          }),
          wireFileCard({
            id: CARD2_ID,
            title: 'Second file',
            file: wireDeliveredFile({
              name: 'two.pdf',
              download_path: filePath(PACK_ID, CARD2_ID),
            }),
          }),
        ]),
      ),
    )
    expect(screen.getByRole('link', { name: /Download/ })).toHaveAttribute(
      'href',
      filePath(PACK_ID, CARD_ID),
    )

    await user.click(screen.getByRole('link', { name: 'Next Card: Second file' }))
    await waitFor(() => {
      expect(screen.getByRole('link', { name: /Download/ })).toHaveAttribute(
        'href',
        filePath(PACK_ID, CARD2_ID),
      )
    })
  })

  it('has no accessibility violations, for an image and for a PDF', async () => {
    await openPack(wireLibraryPack({ cards: [png(), wireFileCard({ id: CARD2_ID })] }))
    await expectNoAxeViolations()
  })
})

// --- Capabilities -----------------------------------------------------------------------------------------------------------

describe('the Pack page is behind resources.view', () => {
  it('asks for nothing from someone who lacks it', async () => {
    const api = serveOperator(operator(['console.access', 'resources.manage']))
    api.on(`GET ${LIBRARY_PACK}`, () => json(wireLibraryPack()))
    renderApp(PATH)

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
    ).toBeInTheDocument()
    expect(api.calls.filter((c) => c.path.startsWith(LIBRARY))).toEqual([])
  })
})
