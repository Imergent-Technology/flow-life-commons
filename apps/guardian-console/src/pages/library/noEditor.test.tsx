import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { operator, serveOperator } from '../../test/admin.ts'
import { json } from '../../test/fakeApi.ts'
import {
  LIBRARY,
  LIBRARY_PACK,
  libraryOf,
  numbered,
  READER,
  THREE_CARDS,
  TWO_GROUPS,
  wireFileCard,
  wireLibraryPack,
  wireLinkCard,
} from '../../test/library.ts'
import { renderApp } from '../../test/renderApp.tsx'
import { PACK_ID } from '../../test/resources.ts'
import { LazyRichTextEditor } from '../../richtext/LazyRichTextEditor.tsx'
import { EMPTY_DOCUMENT } from '../../richtext/contract.ts'

// The editor (Tiptap and ProseMirror, about 225 kB gzipped) belongs to the screens that WRITE rich text. The library only reads it,
// through the renderer, so reading a Resource must never ask for the editor's module. The module is replaced by one that records that
// it was asked for, which is the same moment a bundler's `import()` would have fetched the chunk; a control proves the recording works.

const editor = vi.hoisted(() => ({ requested: false }))
vi.mock('../../richtext/RichTextEditor.tsx', () => {
  editor.requested = true
  return { RichTextEditor: () => null }
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  editor.requested = false
})

describe('the editor and the library', () => {
  // One page per test: each is a single render and a single wait, so none of them is long enough to be a victim of a busy machine.
  const SERIES = numbered([
    ...THREE_CARDS.slice(0, 1),
    wireLinkCard({ id: '01J00000000000000000CARD009' }),
    wireFileCard({ id: '01J00000000000000000CARD008' }),
  ])

  it.each([
    ['the library home', '/resource-library', { role: 'link', name: 'Welcome pack' }],
    [
      'a Series, at its first Card',
      `/resource-library/${PACK_ID}`,
      { role: 'heading', name: 'The course' },
    ],
    [
      'an External link Card',
      `/resource-library/${PACK_ID}?card=01J00000000000000000CARD009`,
      { role: 'link', name: /Open link/ },
    ],
    [
      'a File Card',
      `/resource-library/${PACK_ID}?card=01J00000000000000000CARD008`,
      { role: 'link', name: /Download/ },
    ],
  ] as const)('is not requested by %s', async (_name, path, ready) => {
    const api = serveOperator(operator(READER))
    api.on(`GET ${LIBRARY}`, () => libraryOf(...TWO_GROUPS))
    api.on(`GET ${LIBRARY_PACK}`, () =>
      json(wireLibraryPack({ title: 'The course', is_series: true, cards: SERIES })),
    )
    renderApp(path)

    await screen.findByRole(ready.role, { name: ready.name })

    expect(editor.requested).toBe(false)
  })

  it('is requested when something that edits asks for it (the control: the record works)', async () => {
    render(
      <LazyRichTextEditor
        value={EMPTY_DOCUMENT}
        onChange={() => undefined}
        onRefusal={() => undefined}
        label="Content"
        labelledBy="x"
      />,
    )

    await waitFor(() => {
      expect(editor.requested).toBe(true)
    })
  })
})
