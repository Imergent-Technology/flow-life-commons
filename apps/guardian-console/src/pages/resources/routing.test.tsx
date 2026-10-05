import { screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { operator, serveOperator } from '../../test/admin.ts'
import { json } from '../../test/fakeApi.ts'
import { renderApp } from '../../test/renderApp.tsx'
import {
  CARD_ID,
  categoryList,
  MANAGE,
  PACK_ID,
  packsPage,
  VIEW_ONLY,
  wireCard,
  wireCategory,
  wirePack,
} from '../../test/resources.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const ROUTES = [
  '/resources',
  '/resources/new',
  '/resources/categories',
  `/resources/packs/${PACK_ID}`,
  `/resources/packs/${PACK_ID}/cards/new`,
  `/resources/packs/${PACK_ID}/cards/${CARD_ID}`,
]

/** Serves what every management page may ask for, so a page that is allowed in can finish loading. */
function serve(capabilities: string[]) {
  const api = serveOperator(operator(capabilities))
  api.on('GET /api/v1/admin/resources/categories', json(categoryList([wireCategory()])))
  api.on('GET /api/v1/admin/resources/packs', json(packsPage([])))
  api.on(`GET /api/v1/admin/resources/packs/${PACK_ID}`, json(wirePack()))
  api.on(`GET /api/v1/admin/resources/packs/${PACK_ID}/cards/${CARD_ID}`, json(wireCard()))
  return api
}

const resourceCalls = (api: ReturnType<typeof serve>) =>
  api.calls.filter((call) => call.path.startsWith('/api/v1/admin/resources'))

describe('who may manage Resources', () => {
  it('lets someone with resources.manage in, and offers the section in the navigation', async () => {
    const api = serve(MANAGE)
    renderApp('/resources')

    expect(await screen.findByRole('heading', { level: 1, name: 'Resources' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Not permitted' })).not.toBeInTheDocument()
    // The page asks for its Packs once it is drawn: waited for, not assumed to have happened by the time the heading is.
    await waitFor(() => {
      expect(resourceCalls(api).length).toBeGreaterThan(0)
    })
    // The rail names the section, and the secondary navigation leads to each page.
    expect(screen.getAllByRole('link', { name: 'Resources' }).length).toBeGreaterThan(0)
  })

  it.each(ROUTES)(
    'refuses %s to someone who may only view Resources, and asks nothing',
    async (path) => {
      const api = serve(VIEW_ONLY)
      renderApp(path)

      expect(
        await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
      ).toBeInTheDocument()
      expect(resourceCalls(api)).toEqual([])
    },
  )

  it.each(ROUTES)(
    'refuses %s to someone with no Resources capability, and asks nothing',
    async (path) => {
      const api = serve(['console.access'])
      renderApp(path)

      expect(
        await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
      ).toBeInTheDocument()
      expect(resourceCalls(api)).toEqual([])
    },
  )

  it('does not offer the section to someone who may only view Resources', async () => {
    serve(VIEW_ONLY)
    renderApp('/')

    await screen.findByRole('heading', { level: 1 })
    expect(screen.queryByRole('link', { name: 'Resources' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Categories' })).not.toBeInTheDocument()
  })
})
