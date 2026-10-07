import { screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { operator, serveOperator } from '../../test/admin.ts'
import { json } from '../../test/fakeApi.ts'
import {
  LIBRARY,
  LIBRARY_PACK,
  libraryOf,
  MANAGER_ONLY,
  READER,
  TWO_GROUPS,
  wireLibraryPack,
} from '../../test/library.ts'
import { renderApp } from '../../test/renderApp.tsx'
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
  api.on(`GET ${LIBRARY}`, () => libraryOf(...TWO_GROUPS))
  api.on(`GET ${LIBRARY_PACK}`, () => json(wireLibraryPack()))
  api.on('GET /api/v1/admin/resources/categories', json(categoryList([wireCategory()])))
  api.on('GET /api/v1/admin/resources/packs', json(packsPage([wireListedPack()])))
  return api
}

const libraryCalls = (api: ReturnType<typeof serve>) =>
  api.calls.filter((call) => call.path.startsWith(LIBRARY))
const managementCalls = (api: ReturnType<typeof serve>) =>
  api.calls.filter((call) => call.path.startsWith('/api/v1/admin/resources'))

describe('who may read the Resource Library', () => {
  it('lets someone with resources.view in, and offers the library but not management', async () => {
    const api = serve(READER)
    renderApp('/resource-library')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Resource Library' }),
    ).toBeInTheDocument()
    await screen.findByRole('heading', { level: 2, name: 'Training guides' })
    expect(libraryCalls(api).length).toBeGreaterThan(0)
    // Reading the library asks nothing of management.
    expect(managementCalls(api)).toEqual([])
    // View alone offers the library, and none of the pages that manage.
    expect(screen.getAllByRole('link', { name: 'Resource Library' }).length).toBeGreaterThan(0)
    expect(screen.queryByRole('link', { name: 'All Resource Packs' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Add a Resource Pack' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Categories' })).not.toBeInTheDocument()
  })

  it.each(ROUTES)('refuses %s to someone who can only manage, and asks nothing', async (path) => {
    // Nothing is inferred: the platform's role catalog grants both together, but the Console goes by what /me says.
    const api = serve(MANAGER_ONLY)
    renderApp(path)

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
    ).toBeInTheDocument()
    expect(libraryCalls(api)).toEqual([])
    expect(screen.queryByRole('link', { name: 'Resource Library' })).not.toBeInTheDocument()
  })

  it.each(ROUTES)(
    'refuses %s to someone with neither capability, and asks nothing',
    async (path) => {
      const api = serve(['console.access'])
      renderApp(path)

      expect(
        await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
      ).toBeInTheDocument()
      expect(libraryCalls(api)).toEqual([])
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

  it('does not take management for the library: view-only is refused the management pages', async () => {
    const api = serve(READER)
    renderApp('/resources')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
    ).toBeInTheDocument()
    expect(managementCalls(api)).toEqual([])
  })
})
