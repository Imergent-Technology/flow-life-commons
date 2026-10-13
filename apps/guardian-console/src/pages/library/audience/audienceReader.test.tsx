import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { operator, serveOperator } from '../../../test/admin.ts'
import { pageBody } from '../../../test/deferred.ts'
import { json } from '../../../test/fakeApi.ts'
import {
  filePath,
  libraryOf,
  MY_RESOURCES,
  MY_RESOURCES_PACK,
  READER,
  wireDeliveredCard,
  wireDeliveredFile,
  wireFileCard,
  wireGroup,
  wireLibraryPack,
  wireListedEntry,
} from '../../../test/library.ts'
import { renderApp } from '../../../test/renderApp.tsx'
import { CARD_ID, PACK_ID } from '../../../test/resources.ts'
import {
  RESOURCE_LIBRARY,
  viewerFilePath,
  VIEWER_GROUPS,
  viewerLibraryOf,
  wireViewerPack,
} from '../../../test/viewerLibrary.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

// The audience reader (policy C) says nothing of what it cannot show (ADR 0038, R11, R14). These tests serve it things it must refuse
// to draw, and look for any trace of what a privileged reader would have seen.

function serve() {
  return serveOperator(operator(READER))
}

describe('the audience reader’s library', () => {
  it('says "There are no Resources for you yet." and does not claim the library is for Guardians alone', async () => {
    const api = serve()
    api.on(`GET ${MY_RESOURCES}`, () => libraryOf())
    renderApp('/resource-library')

    expect(await screen.findByText('There are no Resources for you yet.')).toBeVisible()
    const text = pageBody().textContent
    expect(text).toContain('When Resources are published for you they will appear here.')
    // The eligible audiences may be Guardian, Volunteer, Member or any mix: the page names none of them.
    for (const word of ['Guardian', 'Volunteer', 'Member']) {
      expect(text, `"${word}" appears on the audience reader's page`).not.toContain(word)
    }
  })

  it('has no publication or audience control, and marks nothing as Draft, Published or for anyone', async () => {
    const api = serve()
    api.on(`GET ${MY_RESOURCES}`, () => libraryOf(wireGroup([wireListedEntry()])))
    renderApp('/resource-library')
    await screen.findByRole('link', { name: 'Welcome pack' })

    expect(screen.queryByLabelText('Publication')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Audience')).not.toBeInTheDocument()
    const text = pageBody().textContent
    for (const word of ['Draft', 'Published', 'For Guardians', 'Last edited', 'Uncategorized']) {
      expect(text, `"${word}" appears on the audience reader's page`).not.toContain(word)
    }
  })

  it('refuses to draw a privileged answer from the audience route, rather than showing what it did not mean to receive', async () => {
    const api = serve()
    api.on(`GET ${MY_RESOURCES}`, () => viewerLibraryOf(...VIEWER_GROUPS))
    renderApp('/resource-library')

    expect(await screen.findByRole('alert')).toBeVisible()
    expect(screen.getByRole('button', { name: 'Try again' })).toBeVisible()
    for (const word of ['Secret draft plan', 'Loose notes', 'Hone Guardian', 'Draft']) {
      expect(pageBody().textContent, `"${word}" was drawn`).not.toContain(word)
    }
  })

  it('refuses a privileged Pack from the audience route, and a Card that carries a Draft marker', async () => {
    const api = serve()
    api.on(`GET ${MY_RESOURCES_PACK}`, () => json(wireViewerPack({ title: 'A privileged Pack' })))
    renderApp(`/resource-library/${PACK_ID}`)

    expect(await screen.findByRole('alert')).toBeVisible()
    expect(pageBody().textContent).not.toContain('A privileged Pack')
  })

  it('serves a file through the audience route only, and offers none named by the viewer’s', async () => {
    const api = serve()
    api.on(`GET ${MY_RESOURCES_PACK}`, () =>
      json(
        wireLibraryPack({
          cards: [
            wireFileCard({
              id: CARD_ID,
              file: wireDeliveredFile({ download_path: viewerFilePath(PACK_ID, CARD_ID) }),
            }),
          ],
        }),
      ),
    )
    renderApp(`/resource-library/${PACK_ID}`)

    expect(await screen.findByText('This file cannot be shown right now.')).toBeVisible()
    expect(screen.queryByRole('link', { name: /Download/ })).not.toBeInTheDocument()
    expect(document.querySelector(`[href*="${RESOURCE_LIBRARY}"]`)).toBeNull()
  })

  it('reads a File Card from the audience route when the path is its own', async () => {
    const api = serve()
    api.on(`GET ${MY_RESOURCES_PACK}`, () => json(wireLibraryPack({ cards: [wireFileCard()] })))
    renderApp(`/resource-library/${PACK_ID}`)

    const download = await screen.findByRole('link', { name: /Download/ })
    expect(download).toHaveAttribute('href', filePath(PACK_ID, CARD_ID))
    expect(download.getAttribute('href')).toContain('/my-resources/')
  })

  it('numbers and offers only the Cards it was sent, and says nothing about any other', async () => {
    const user = userEvent.setup()
    const api = serve()
    api.on(`GET ${MY_RESOURCES_PACK}`, () =>
      json(
        wireLibraryPack({
          is_series: true,
          cards: [
            wireDeliveredCard({ id: CARD_ID, index: 1, title: 'First' }),
            wireDeliveredCard({ id: '01J00000000000000000CARD002', index: 2, title: 'Last' }),
          ],
        }),
      ),
    )
    renderApp(`/resource-library/${PACK_ID}`)
    await screen.findByRole('heading', { level: 1, name: 'Welcome pack' })

    expect(screen.getByText('Card 1 of 2')).toBeVisible()
    await user.click(
      within(screen.getByRole('navigation', { name: 'Series' })).getByRole('link', {
        name: /Next/,
      }),
    )
    expect(screen.getByText('Card 2 of 2')).toBeVisible()
    expect(pageBody().textContent).not.toMatch(/Draft|hidden|other Card/i)
  })
})
