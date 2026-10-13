import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useMemo, useState, type ReactNode } from 'react'
import { Link, MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { CurrentAccount } from '../../api/auth.ts'
import App from '../../App.tsx'
import { AuthContext, type AuthContextValue } from '../../auth/auth-context.ts'
import { operator, serveOperator } from '../../test/admin.ts'
import { deferred } from '../../test/deferred.ts'
import { FakeApi, json } from '../../test/fakeApi.ts'
import {
  libraryOf,
  MY_RESOURCES,
  MY_RESOURCES_PACK,
  resourceNotFound,
  wireGroup,
  wireLibraryPack,
  wireListedEntry,
} from '../../test/library.ts'
import { LocationProbe } from '../../test/LocationProbe.tsx'
import { renderApp } from '../../test/renderApp.tsx'
import { CARD_ID, doc, PACK_ID } from '../../test/resources.ts'
import {
  DRAFT_PACK_ID,
  RESOURCE_LIBRARY,
  RESOURCE_LIBRARY_PACK,
  numbered,
  THREE_VIEWER_CARDS,
  VIEWER,
  VIEWER_GROUPS,
  viewerLibraryOf,
  wireViewerCard,
  wireViewerGroup,
  wireViewerListed,
  wireViewerPack,
} from '../../test/viewerLibrary.ts'
import { LibraryHomePage, LibraryPackPage } from './LibraryPages.tsx'
import { LibraryRoute } from './LibraryRoute.tsx'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

// ADR 0038, C1: the library is the viewer (policy A) or the audience reader (policy C), chosen from `/me`, and when the platform takes
// `resources.view` away the Console must stop showing everything the viewer held, ask `/me` again, and only then decide. These tests
// serve the two policies DIFFERENT content on purpose: the viewer has a Draft Pack, an uncategorized Pack and the editors' names, and the
// audience reader has one Published Pack. A test where both returned the same thing could not tell which of them had been asked.

const NOBODY = ['console.access']

/** Everything only the viewer is told. None of it may be on screen once the platform has refused the viewer. */
const PRIVILEGED = [
  'Secret draft plan',
  'Loose notes',
  'Uncategorized',
  'Hone Guardian',
  'Last edited',
  'For Volunteers',
  'Not aimed at anyone yet',
  'Draft Card',
  'Draft',
]

function expectNothingPrivileged() {
  const text = document.body.textContent
  // One assertion per value: a negated multi-value check passes as soon as ONE value is absent.
  for (const value of PRIVILEGED) expect(text, `"${value}" is still on screen`).not.toContain(value)
  expect(screen.queryByLabelText('Publication')).not.toBeInTheDocument()
  expect(screen.queryByLabelText('Audience')).not.toBeInTheDocument()
}

interface World {
  api: FakeApi
  /** What the SERVER does with the viewer's routes, now. `/me` is a separate snapshot. */
  viewerAllowed: boolean
  /** What `/me` says, now. */
  capabilities: string[]
}

function world(start: string[] = VIEWER): World {
  const state: World = {
    api: serveOperator(operator(start)),
    viewerAllowed: true,
    capabilities: start,
  }
  const { api } = state
  api.on('GET /api/v1/me', () => json(operator(state.capabilities)))

  const viewer = (body: () => Response) => () =>
    state.viewerAllowed ? body() : json({ message: 'Forbidden.' }, 403)
  api.on(
    `GET ${RESOURCE_LIBRARY}`,
    viewer(() => viewerLibraryOf(...VIEWER_GROUPS)),
  )
  api.on(
    `GET ${RESOURCE_LIBRARY}?publication=draft`,
    viewer(() => viewerLibraryOf(VIEWER_GROUPS[0])),
  )
  api.on(
    `GET ${RESOURCE_LIBRARY_PACK}`,
    viewer(() => json(wireViewerPack({ cards: THREE_VIEWER_CARDS }))),
  )
  api.on(
    `GET ${RESOURCE_LIBRARY}/packs/${DRAFT_PACK_ID}`,
    viewer(() =>
      json(wireViewerPack({ id: DRAFT_PACK_ID, title: 'Secret draft plan', state: 'draft' })),
    ),
  )

  // The audience reader: what this Person is eligible for, and a Draft Pack is simply not there.
  api.on(`GET ${MY_RESOURCES}`, () => libraryOf(wireGroup([wireListedEntry()])))
  api.on(`GET ${MY_RESOURCES_PACK}`, () => json(wireLibraryPack()))
  api.on(`GET ${MY_RESOURCES}/packs/${DRAFT_PACK_ID}`, () => resourceNotFound())
  return state
}

/** The platform takes `resources.view` away: its routes refuse, and `/me` now says so. */
function revoke(w: World) {
  w.viewerAllowed = false
  w.capabilities = NOBODY
}

const callsTo = (w: World, prefix: string) =>
  w.api.calls.filter((call) => call.method === 'GET' && call.path.startsWith(prefix))
const viewerCalls = (w: World) => callsTo(w, RESOURCE_LIBRARY)
const audienceCalls = (w: World) => callsTo(w, MY_RESOURCES)
const meCalls = (w: World) => callsTo(w, '/api/v1/me')

async function openViewerHome(w: World) {
  const user = userEvent.setup()
  renderApp('/resource-library')
  await screen.findByRole('heading', { level: 2, name: 'Uncategorized' })
  // The positive control: what the viewer holds really is on screen before the refusal.
  expect(screen.getByRole('link', { name: 'Secret draft plan' })).toBeInTheDocument()
  expect(w.api.calls.length).toBeGreaterThan(0)
  return user
}

describe('choosing the reader', () => {
  it('opens the viewer for someone whose /me holds resources.view, and asks only the viewer', async () => {
    const w = world(VIEWER)
    renderApp('/resource-library')
    await screen.findByRole('heading', { level: 2, name: 'Uncategorized' })
    expect(viewerCalls(w).length).toBeGreaterThan(0)
    expect(audienceCalls(w)).toEqual([])
  })

  it.each([
    ['nothing else', NOBODY],
    [
      'only resources.manage, since managing is not viewing',
      ['console.access', 'resources.manage'],
    ],
  ])(
    'opens the audience reader for someone with %s, and never asks the viewer',
    async (_who, caps) => {
      const w = world(caps)
      renderApp('/resource-library')
      await screen.findByRole('link', { name: 'Welcome pack' })
      expect(audienceCalls(w).length).toBeGreaterThan(0)
      expect(viewerCalls(w)).toEqual([])
      expectNothingPrivileged()
    },
  )

  it('is not chosen by the address: a query that asks for the other reader changes nothing', async () => {
    const w = world(NOBODY)
    renderApp('/resource-library?mode=viewer&reader=viewer&publication=draft&audience=volunteer')
    await screen.findByRole('link', { name: 'Welcome pack' })
    expect(viewerCalls(w)).toEqual([])
    // The audience reader sends nothing from the address either.
    expect(audienceCalls(w).map((call) => call.path)).toEqual([MY_RESOURCES])
  })
})

describe('when the platform takes resources.view away from a viewer', () => {
  it('removes everything the viewer held at once, asks /me again, decides nothing before it answers, then opens the audience reader at the same address', async () => {
    const w = world()
    const user = await openViewerHome(w)
    revoke(w)
    const meHeld = deferred<Response>()
    const audienceHeld = deferred<Response>()
    w.api.on('GET /api/v1/me', () => meHeld.promise)
    w.api.on(`GET ${MY_RESOURCES}`, () => audienceHeld.promise)
    const meBefore = meCalls(w).length

    // The next thing the viewer asks the platform is refused.
    await user.selectOptions(screen.getByLabelText('Publication'), 'draft')

    // Immediately: nothing of the viewer, and only a neutral "checking". The viewer is gone before /me has answered.
    expect(await screen.findByText('Checking your access to Resources…')).toBeVisible()
    expectNothingPrivileged()
    await waitFor(() => {
      expect(meCalls(w).length).toBe(meBefore + 1)
    })
    // Nothing is decided yet: the audience reader has not been asked, and the viewer has not been asked again. The screen says
    // so, and says nothing else: not "could not be confirmed" (that is for an answer that settled nothing), and nothing opened.
    await new Promise((resolve) => setTimeout(resolve, 30))
    expect(screen.getByText('Checking your access to Resources…')).toBeVisible()
    expect(screen.queryByText(/could not be confirmed/)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument()
    expect(audienceCalls(w)).toEqual([])
    expect(viewerCalls(w).filter((c) => c.path.includes('publication=draft'))).toHaveLength(1)

    meHeld.resolve(json(operator(NOBODY)))
    await waitFor(() => {
      expect(audienceCalls(w).map((call) => call.path)).toEqual([MY_RESOURCES])
    })
    // /me has answered and the audience reader is waiting: still nothing of the viewer, and no viewer-only controls.
    expectNothingPrivileged()

    audienceHeld.resolve(libraryOf(wireGroup([wireListedEntry()])))
    expect(await screen.findByRole('link', { name: 'Welcome pack' })).toBeVisible()
    expectNothingPrivileged()
    expect(screen.getByTestId('location').textContent).toBe('/resource-library')
    // The viewer was never asked again.
    expect(viewerCalls(w).filter((c) => c.path.includes('publication=draft'))).toHaveLength(1)
  })

  it('shows none of the viewer’s Pack, Cards, markers or audiences once a Pack request is refused, and reads the same address through the audience reader', async () => {
    const w = world()
    const user = await openViewerHome(w)
    revoke(w)

    // The viewer opens a Pack; the platform refuses.
    await user.click(screen.getByRole('link', { name: 'Welcome pack' }))

    // The same address, resolved again by policy C: this Pack is eligible, so it reads, and carries nothing the viewer's did.
    expect(await screen.findByRole('heading', { level: 1, name: 'Welcome pack' })).toBeVisible()
    expect(screen.getByTestId('location').textContent).toBe(`/resource-library/${PACK_ID}`)
    expectNothingPrivileged()
    expect(w.api.calls.filter((c) => c.path === RESOURCE_LIBRARY_PACK)).toHaveLength(1)
    expect(w.api.calls.filter((c) => c.path === MY_RESOURCES_PACK)).toHaveLength(1)
  })

  it('resolves a Draft Pack deep link again through the audience reader, where it is simply "not found"', async () => {
    const w = world()
    const user = await openViewerHome(w)
    revoke(w)

    await user.click(screen.getByRole('link', { name: 'Secret draft plan' }))

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Resource not found' }),
    ).toBeVisible()
    expect(screen.getByTestId('location').textContent).toBe(`/resource-library/${DRAFT_PACK_ID}`)
    // Said once, in the one wording, with nothing that says a Draft exists: the title was never on this screen.
    expectNothingPrivileged()
    expect(screen.getByText('That Resource could not be found.')).toBeVisible()
    expect(
      w.api.calls.filter((c) => c.path === `${MY_RESOURCES}/packs/${DRAFT_PACK_ID}`),
    ).toHaveLength(1)
  })

  it('keeps nothing of a Draft Pack the viewer had fully loaded: the deep link is resolved again by policy C, and a late viewer answer restores nothing', async () => {
    const w = world()
    const DRAFT_PATH = `${RESOURCE_LIBRARY}/packs/${DRAFT_PACK_ID}`
    const BODY = 'UNMISTAKABLE draft body'
    // A Draft Pack the viewer reads IN FULL: a Draft Card narrowed to Volunteers, with its audiences, its editor and its body.
    const secret = wireViewerPack({
      id: DRAFT_PACK_ID,
      title: 'Secret draft plan',
      state: 'draft',
      audiences: ['volunteer'],
      cards: numbered([
        wireViewerCard({
          id: CARD_ID,
          title: 'Embargoed first Card',
          state: 'draft',
          audience_mode: 'narrowed',
          audiences: ['volunteer'],
          content: { format: 'prosemirror', version: 1, document: doc(BODY) },
        }),
      ]),
    })
    w.api.on(`GET ${DRAFT_PATH}`, () =>
      w.viewerAllowed ? json(secret) : json({ message: 'Forbidden.' }, 403),
    )
    const privileged = ['Secret draft plan', 'Embargoed first Card', BODY, 'Hone Guardian']
    const expectNoneOfIt = () => {
      expectNothingPrivileged()
      for (const value of privileged)
        expect(document.body.textContent, `"${value}" is still on screen`).not.toContain(value)
    }

    // The test's own link stands for a pasted address or the Back button: in-app navigation to an address.
    render(
      <MemoryRouter initialEntries={[`/resource-library/${DRAFT_PACK_ID}`]}>
        <App />
        <LocationProbe />
        <Link to={`/resource-library/${DRAFT_PACK_ID}`}>Go to the pasted address</Link>
      </MemoryRouter>,
    )
    const user = userEvent.setup()

    // 1-3. The privileged Pack really is loaded and on screen, with everything that must never reach audience mode.
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Secret draft plan' }),
    ).toBeVisible()
    for (const value of [BODY, 'Embargoed first Card', 'Hone Guardian'])
      expect(
        document.body.textContent,
        `"${value}" should be on screen before the refusal`,
      ).toContain(value)
    expect(document.body.textContent).toContain('Draft')
    expect(document.body.textContent).toContain('Volunteers')
    expect(callsTo(w, DRAFT_PATH)).toHaveLength(1)

    // 4. To the library list.
    await user.click(
      within(screen.getByRole('navigation', { name: 'Breadcrumb' })).getByRole('link', {
        name: 'Library',
      }),
    )
    await screen.findByRole('heading', { level: 2, name: 'Uncategorized' })
    // A search is still unanswered when the platform changes its mind...
    const slow = deferred<Response>()
    w.api.on(`GET ${RESOURCE_LIBRARY}?q=slow`, () => slow.promise)
    await user.type(screen.getByLabelText('Search Resources'), 'slow')
    await user.click(screen.getByRole('button', { name: 'Search' }))

    // 5-7. ...the viewing capability goes, the next viewer request is refused, the viewer is gone, and /me is asked again.
    revoke(w)
    w.api.on(`GET ${RESOURCE_LIBRARY}?q=slow&publication=draft`, () => json({ message: 'x' }, 403))
    const meBefore = meCalls(w).length
    await user.selectOptions(screen.getByLabelText('Publication'), 'draft')
    expect(await screen.findByRole('link', { name: 'Welcome pack' })).toBeVisible()
    expect(meCalls(w).length).toBe(meBefore + 1)
    expect(screen.queryByLabelText('Publication')).not.toBeInTheDocument()
    expectNoneOfIt()
    const viewerCallsAtDowngrade = viewerCalls(w).length

    // 8-9. The same Pack address, now: policy C answers 404, and only policy C was asked.
    await user.click(screen.getByRole('link', { name: 'Go to the pasted address' }))
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Resource not found' }),
    ).toBeVisible()
    expect(screen.getByText('That Resource could not be found.')).toBeVisible()
    expect(screen.getByTestId('location').textContent).toBe(`/resource-library/${DRAFT_PACK_ID}`)
    expect(callsTo(w, `${MY_RESOURCES}/packs/${DRAFT_PACK_ID}`)).toHaveLength(1)
    // The privileged Pack was requested once, before the refusal, and never again.
    expect(callsTo(w, DRAFT_PATH)).toHaveLength(1)
    expect(viewerCalls(w)).toHaveLength(viewerCallsAtDowngrade)
    expectNoneOfIt()

    // 10. The old viewer answer finally arrives, carrying the Draft, and restores nothing.
    slow.resolve(
      viewerLibraryOf(
        wireViewerGroup([
          wireViewerListed({ id: DRAFT_PACK_ID, title: 'Secret draft plan', state: 'draft' }),
        ]),
      ),
    )
    await new Promise((resolve) => setTimeout(resolve, 30))
    expect(screen.getByRole('heading', { level: 1, name: 'Resource not found' })).toBeVisible()
    expectNoneOfIt()
  })

  it('cannot be undone by a viewer answer that arrives late', async () => {
    const w = world()
    const user = await openViewerHome(w)
    // A search the viewer has asked, still unanswered...
    const slow = deferred<Response>()
    w.api.on(`GET ${RESOURCE_LIBRARY}?q=slow`, () => slow.promise)
    await user.type(screen.getByLabelText('Search Resources'), 'slow')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    // ...and then the platform takes the capability away and refuses the next request.
    revoke(w)
    w.api.on(`GET ${RESOURCE_LIBRARY}?q=slow&publication=draft`, () => json({ message: 'x' }, 403))
    await user.selectOptions(screen.getByLabelText('Publication'), 'draft')
    expect(await screen.findByRole('link', { name: 'Welcome pack' })).toBeVisible()
    expectNothingPrivileged()

    // The old answer finally comes, with everything privileged in it.
    slow.resolve(
      viewerLibraryOf(
        wireViewerGroup([
          wireViewerListed({ id: DRAFT_PACK_ID, title: 'Secret draft plan', state: 'draft' }),
        ]),
      ),
    )
    await new Promise((resolve) => setTimeout(resolve, 30))

    expectNothingPrivileged()
    expect(screen.getByRole('link', { name: 'Welcome pack' })).toBeVisible()
  })

  it('starts the audience reader fresh: the viewer’s search and filters are not carried over', async () => {
    const w = world()
    const user = await openViewerHome(w)
    await user.type(screen.getByLabelText('Search Resources'), 'draft plan')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    revoke(w)
    w.api.on(`GET ${RESOURCE_LIBRARY}?q=draft+plan&publication=draft`, () =>
      json({ message: 'x' }, 403),
    )
    await user.selectOptions(screen.getByLabelText('Publication'), 'draft')

    await screen.findByRole('link', { name: 'Welcome pack' })
    expect(screen.getByLabelText('Search Resources')).toHaveValue('')
    // The audience reader asked for the whole of its library, with nothing the viewer had typed or chosen.
    expect(audienceCalls(w).map((call) => call.path)).toEqual([MY_RESOURCES])
  })

  it('refuses the Console when /me no longer admits the person, and never asks the audience reader', async () => {
    const w = world()
    const user = await openViewerHome(w)
    w.viewerAllowed = false
    w.capabilities = []

    await user.selectOptions(screen.getByLabelText('Publication'), 'draft')

    expect(await screen.findByRole('heading', { name: 'Access denied' })).toBeVisible()
    expectNothingPrivileged()
    expect(audienceCalls(w)).toEqual([])
  })

  it('shows the service-unavailable screen, and nothing of the viewer, when /me cannot be read', async () => {
    const w = world()
    const user = await openViewerHome(w)
    w.viewerAllowed = false
    w.api.on('GET /api/v1/me', () => json({ message: 'down' }, 503))

    await user.selectOptions(screen.getByLabelText('Publication'), 'draft')

    expect(await screen.findByRole('heading', { name: 'Service unavailable' })).toBeVisible()
    expectNothingPrivileged()
    // Nothing was guessed: the audience reader is not tried while who the person is remains unknown.
    expect(audienceCalls(w)).toEqual([])
  })

  it('does not loop when the platform refuses but /me still says the capability is held: it says so, shows nothing, and waits to be asked', async () => {
    const w = world()
    const user = await openViewerHome(w)
    w.viewerAllowed = false // the platform refuses; /me is unchanged and still lists resources.view
    const before = viewerCalls(w).length

    await user.selectOptions(screen.getByLabelText('Publication'), 'draft')

    expect(
      await screen.findByText(/access to the Resource Library could not be confirmed/),
    ).toBeVisible()
    expectNothingPrivileged()
    await new Promise((resolve) => setTimeout(resolve, 50))
    // Exactly one more viewer request (the refused one), and no audience request: nothing retried or elevated by itself.
    expect(viewerCalls(w)).toHaveLength(before + 1)
    expect(audienceCalls(w)).toEqual([])

    // Asked again, deliberately, and the platform now allows it: the viewer comes back from the beginning.
    w.viewerAllowed = true
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('heading', { level: 2, name: 'Uncategorized' })).toBeVisible()
  })

  it('does not change reader for a refusal of a request that is not the viewer’s: a missing Pack is a missing Pack', async () => {
    const w = world()
    const user = await openViewerHome(w)
    w.api.on(`GET ${RESOURCE_LIBRARY}/packs/${DRAFT_PACK_ID}`, () => resourceNotFound())

    await user.click(screen.getByRole('link', { name: 'Secret draft plan' }))

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Resource not found' }),
    ).toBeVisible()
    // Still the viewer: no /me refresh, no audience request.
    expect(audienceCalls(w)).toEqual([])
    expect(meCalls(w)).toHaveLength(1)
  })
})

describe('a refusal from the audience reader', () => {
  /** `/me` says one thing when the Console loads and another each time it is asked again, as it does when a role changes. */
  function meSays(w: World, first: string[], then: string[]) {
    let asked = 0
    w.api.on('GET /api/v1/me', () => json(operator((asked += 1) === 1 ? first : then)))
  }

  it('means Console admission is gone: the Console refuses, and it is never taken for an ineligible Resource', async () => {
    const w = world(NOBODY)
    w.api.on(`GET ${MY_RESOURCES}`, () => json({ message: 'Forbidden.' }, 403))
    meSays(w, NOBODY, [])
    renderApp('/resource-library')

    expect(await screen.findByRole('heading', { name: 'Access denied' })).toBeVisible()
    // It got there by asking /me again, not because /me said so from the start.
    expect(meCalls(w)).toHaveLength(2)
    expect(audienceCalls(w).length).toBeGreaterThan(0)
    expect(screen.queryByText('That Resource could not be found.')).not.toBeInTheDocument()
    expect(viewerCalls(w)).toEqual([])
  })

  it('is never turned into the viewer, even when /me now says resources.view is held', async () => {
    const w = world(NOBODY)
    w.api.on(`GET ${MY_RESOURCES}`, () => json({ message: 'Forbidden.' }, 403))
    meSays(w, NOBODY, VIEWER) // gained meanwhile
    renderApp('/resource-library')

    expect(
      await screen.findByText(/access to the Resource Library could not be confirmed/),
    ).toBeVisible()
    expect(meCalls(w)).toHaveLength(2)
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(viewerCalls(w)).toEqual([])
    expectNothingPrivileged()
  })
})

describe('a capability that changes while the library is open', () => {
  /** The library on its own, with `/me` held in a state a test can change, and a `refresh` that reads it. */
  function Harness({
    api,
    initial,
    children,
  }: {
    api: FakeApi
    initial: CurrentAccount
    children?: ReactNode
  }) {
    const [current, setCurrent] = useState(initial)
    const value = useMemo<AuthContextValue>(
      () => ({
        state: { status: 'authenticated', current },
        signIn: vi.fn(),
        completeSecondFactor: vi.fn(),
        endPendingSignIn: vi.fn(),
        signOut: vi.fn(),
        refresh: () => Promise.resolve(),
      }),
      [current],
    )
    return (
      <AuthContext value={value}>
        <MemoryRouter initialEntries={['/resource-library']}>
          <Routes>
            <Route path="resource-library" element={<LibraryRoute />}>
              <Route index element={<LibraryHomePage />} />
              <Route path=":packId" element={<LibraryPackPage />} />
            </Route>
          </Routes>
        </MemoryRouter>
        <button
          onClick={() => {
            setCurrent(operator(NOBODY))
          }}
        >
          lose in /me
        </button>
        <button
          onClick={() => {
            setCurrent(operator(VIEWER))
          }}
        >
          gain in /me
        </button>
        <button
          onClick={() => {
            setCurrent({ ...current })
          }}
        >
          same in /me
        </button>
        {children}
        <span data-testid="api-installed">{api.calls.length >= 0 ? 'yes' : 'no'}</span>
      </AuthContext>
    )
  }

  function harnessWorld(initial: string[]) {
    const api = new FakeApi()
    api.on(`GET ${RESOURCE_LIBRARY}`, () => viewerLibraryOf(...VIEWER_GROUPS))
    api.on(`GET ${MY_RESOURCES}`, () => libraryOf(wireGroup([wireListedEntry()])))
    api.install()
    return { api, initial: operator(initial) }
  }

  it('is not raised in place: gaining resources.view in /me leaves the audience reader as it was, until the library is entered again', async () => {
    const { api, initial } = harnessWorld(NOBODY)
    const user = userEvent.setup()
    const view = render(<Harness api={api} initial={initial} />)
    await screen.findByRole('link', { name: 'Welcome pack' })

    await user.click(screen.getByRole('button', { name: 'gain in /me' }))
    await new Promise((resolve) => setTimeout(resolve, 30))

    expect(screen.getByRole('link', { name: 'Welcome pack' })).toBeVisible()
    expectNothingPrivileged()
    expect(api.calls.filter((c) => c.path.startsWith(RESOURCE_LIBRARY))).toEqual([])

    // Entered again (a new visit, as after navigation or a reload): now the viewer.
    view.unmount()
    render(<Harness api={api} initial={operator(VIEWER)} />)
    expect(await screen.findByRole('heading', { level: 2, name: 'Uncategorized' })).toBeVisible()
  })

  it('leaves the viewer intact when /me is read again and says the same: nothing is reset or asked again', async () => {
    const { api, initial } = harnessWorld(VIEWER)
    const user = userEvent.setup()
    render(<Harness api={api} initial={initial} />)
    await screen.findByRole('heading', { level: 2, name: 'Uncategorized' })
    await user.type(screen.getByLabelText('Search Resources'), 'half-typed')
    const before = api.calls.length

    await user.click(screen.getByRole('button', { name: 'same in /me' }))
    await new Promise((resolve) => setTimeout(resolve, 30))

    expect(screen.getByLabelText('Search Resources')).toHaveValue('half-typed')
    expect(screen.getByRole('heading', { level: 2, name: 'Uncategorized' })).toBeVisible()
    expect(api.calls).toHaveLength(before)
  })

  it('does not drop the viewer because /me alone now lacks the capability: the platform’s answer decides, not the snapshot', async () => {
    const { api, initial } = harnessWorld(VIEWER)
    const user = userEvent.setup()
    render(<Harness api={api} initial={initial} />)
    await screen.findByRole('heading', { level: 2, name: 'Uncategorized' })

    await user.click(screen.getByRole('button', { name: 'lose in /me' }))
    await new Promise((resolve) => setTimeout(resolve, 30))

    // Nothing has been refused, so nothing changes. (The server is still what enforces: its 403 is what moves the library.)
    expect(screen.getByRole('heading', { level: 2, name: 'Uncategorized' })).toBeVisible()
    expect(api.calls.filter((c) => c.path.startsWith(MY_RESOURCES))).toEqual([])
    expect(within(document.body).getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })
})
