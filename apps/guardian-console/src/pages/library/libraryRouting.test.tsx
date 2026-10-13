import { screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { operator, serveOperator } from '../../test/admin.ts'
import { json } from '../../test/fakeApi.ts'
import {
  MY_RESOURCES,
  MY_RESOURCES_PACK,
  libraryOf,
  MANAGER_ONLY,
  READER,
  TWO_GROUPS,
  wireLibraryPack,
} from '../../test/library.ts'
import { renderApp } from '../../test/renderApp.tsx'
import { VIEWER } from '../../test/viewerLibrary.ts'
import {
  categoryList,
  PACK_ID,
  packsPage,
  wireCategory,
  wireListedPack,
} from '../../test/resources.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const ROUTES = ['/resource-library', `/resource-library/${PACK_ID}`]

function serve(capabilities: string[]) {
  const api = serveOperator(operator(capabilities))
  api.on(`GET ${MY_RESOURCES}`, () => libraryOf(...TWO_GROUPS))
  api.on(`GET ${MY_RESOURCES_PACK}`, () => json(wireLibraryPack()))
  api.on('GET /api/v1/admin/resources/categories', json(categoryList([wireCategory()])))
  api.on('GET /api/v1/admin/resources/packs', json(packsPage([wireListedPack()])))
  return api
}

const libraryCalls = (api: ReturnType<typeof serve>) =>
  api.calls.filter((call) => call.path.startsWith(MY_RESOURCES))
const viewerCalls = (api: ReturnType<typeof serve>) =>
  api.calls.filter((call) => call.path.startsWith('/api/v1/admin/resource-library'))
const managementCalls = (api: ReturnType<typeof serve>) =>
  api.calls.filter((call) => call.path.startsWith('/api/v1/admin/resources'))

describe('who may read the Resource Library', () => {
  it('opens for anyone the Console admits, as the audience reader, and offers the library but not management', async () => {
    const api = serve(READER)
    renderApp('/resource-library')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Resource Library' }),
    ).toBeInTheDocument()
    await screen.findByRole('heading', { level: 2, name: 'Training guides' })
    expect(libraryCalls(api).length).toBeGreaterThan(0)
    // Reading the library asks nothing of management, and nothing of the privileged viewer.
    expect(managementCalls(api)).toEqual([])
    expect(viewerCalls(api)).toEqual([])
    // Console admission alone offers the library, and none of the pages that manage.
    expect(screen.getAllByRole('link', { name: 'Resource Library' }).length).toBeGreaterThan(0)
    expect(screen.queryByRole('link', { name: 'All Resource Packs' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Add a Resource Pack' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Categories' })).not.toBeInTheDocument()
  })

  it.each(ROUTES)(
    'opens %s for someone who can only manage as the audience reader, and management is not reading',
    async (path) => {
      // Management does not grant viewing (ADR 0038, R4): the Console goes by what /me says, and /me says no `resources.view`.
      const api = serve(MANAGER_ONLY)
      renderApp(path)

      await screen.findByRole('heading', { level: 1 })
      await waitFor(() => {
        expect(libraryCalls(api).length).toBeGreaterThan(0)
      })
      expect(viewerCalls(api)).toEqual([])
      // Nothing of management was read to build a library, and it is still offered as its own place.
      expect(managementCalls(api)).toEqual([])
      expect(screen.getAllByRole('link', { name: 'Resource Library' }).length).toBeGreaterThan(0)
    },
  )

  it.each(ROUTES)(
    'opens %s for someone with no Resources capability at all, as the audience reader',
    async (path) => {
      const api = serve(['console.access'])
      renderApp(path)

      await screen.findByRole('heading', { level: 1 })
      await waitFor(() => {
        expect(libraryCalls(api).length).toBeGreaterThan(0)
      })
      expect(viewerCalls(api)).toEqual([])
      expect(screen.queryByRole('heading', { name: 'Not permitted' })).not.toBeInTheDocument()
    },
  )

  it('offers the library and management side by side to someone who holds both', async () => {
    serve([...READER, 'resources.manage'])
    renderApp('/resource-library')
    await screen.findByRole('heading', { level: 1, name: 'Resource Library' })

    const links = screen.getAllByRole('link').map((link) => link.textContent)
    expect(links).toContain('Resource Library')
    expect(links).toContain('All Resource Packs')
  })

  it('leaves the management pages where they were, and asks the management API only for them', async () => {
    const api = serve([...READER, 'resources.manage'])
    renderApp('/resources')

    expect(await screen.findByRole('heading', { level: 1, name: 'Resources' })).toBeInTheDocument()
    expect(
      within(document.body).queryByRole('heading', { name: 'Resource Library' }),
    ).not.toBeInTheDocument()
    expect(libraryCalls(api)).toEqual([])
  })

  it.each([
    ['someone admitted to the Console with nothing else', READER],
    ['a holder of resources.view, who reads every Pack', VIEWER],
  ])(
    'does not take reading for managing: %s is refused the management pages',
    async (_who, caps) => {
      const api = serve(caps)
      renderApp('/resources')

      expect(
        await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
      ).toBeInTheDocument()
      expect(managementCalls(api)).toEqual([])
    },
  )
})
