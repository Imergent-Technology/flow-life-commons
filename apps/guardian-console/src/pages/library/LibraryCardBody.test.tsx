import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import type { DeliveredCard } from '../../api/resourceLibrary.ts'
import { invalidFixtures, validFixtures } from '../../test/resourceFixtures.ts'
import { LibraryCardBody } from './LibraryCardBody.tsx'

// The library is the first screen that READS Resource content for its own sake, so it is held to the same corpus the platform and the
// editor are (apps/platform/tests/Fixtures/resource-content): every document the platform accepts is drawn in full, and every one it
// refuses is withheld whole, with nothing of it on screen and nothing in it active.

const card = (document: unknown): DeliveredCard => ({
  id: '01J00000000000000000CARD001',
  index: 1,
  type: 'basic',
  title: 'A Card',
  summary: 'Its summary.',
  uri: null,
  file: null,
  document,
})

const WITHHELD = 'This content can’t be displayed.'

describe('Card content in the library', () => {
  it.each(validFixtures().map((f) => [f.name, f] as const))(
    'draws the valid document %s',
    (_name, fixture) => {
      const { container } = render(<LibraryCardBody card={card(fixture.document)} />)

      expect(screen.queryByText(WITHHELD)).not.toBeInTheDocument()
      // Everything that was written is on the page (an empty document draws nothing but the Card's own summary).
      if (fixture.text.trim() !== '') {
        for (const word of fixture.text.split(/\s+/).filter((w) => w.length > 3)) {
          expect(container.textContent).toContain(word)
        }
      }
    },
  )

  it.each(invalidFixtures().map((f) => [f.name, f] as const))(
    'withholds the document %s whole, with nothing of it drawn',
    (_name, fixture) => {
      const { container } = render(<LibraryCardBody card={card(fixture.document)} />)

      expect(screen.getByText(WITHHELD)).toBeInTheDocument()
      // Not a word of the document is shown, and nothing active came with it.
      expect(container.querySelector('script, iframe, embed, object, img, style')).toBeNull()
      expect(container.querySelector('[onclick], [onerror], [style]')).toBeNull()
      expect(container.querySelector('a[href^="javascript" i]')).toBeNull()
      // It does not say why: the validator's reasons are not for readers.
      expect(container.textContent).not.toMatch(/content\.\d|path|profile|invalid/i)
    },
  )

  it('makes http and https links open in a new tab safely, and leaves mailto in this one', () => {
    const links = validFixtures().find((f) => f.name === 'links')
    render(<LibraryCardBody card={card(links?.document)} />)

    for (const anchor of screen.getAllByRole('link')) {
      const href = anchor.getAttribute('href') ?? ''
      if (/^https?:/i.test(href)) {
        expect(anchor).toHaveAttribute('target', '_blank')
        expect(anchor).toHaveAttribute('rel', 'noopener noreferrer')
      } else {
        expect(href).toMatch(/^mailto:/i)
        expect(anchor).not.toHaveAttribute('target')
      }
    }
    expect(screen.getAllByRole('link').length).toBeGreaterThanOrEqual(3)
  })

  it('draws a table as a table, with its header cells', () => {
    const table = validFixtures().find((f) => f.name === 'table')
    render(<LibraryCardBody card={card(table?.document)} />)

    expect(screen.getByRole('table')).toBeInTheDocument()
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'Role'])
  })

  it('refuses a table inside a table, at any depth, whatever the document says', () => {
    for (const fixture of invalidFixtures().filter((f) => f.name.startsWith('table-in-'))) {
      const { unmount } = render(<LibraryCardBody card={card(fixture.document)} />)
      expect(screen.getByText(WITHHELD)).toBeInTheDocument()
      expect(screen.queryByRole('table')).not.toBeInTheDocument()
      unmount()
    }
  })

  it('does not show the summary beside content that was refused: the Card has content, it just cannot be drawn', () => {
    render(<LibraryCardBody card={card({ type: 'doc', content: [{ type: 'script' }] })} />)

    expect(screen.getByText(WITHHELD)).toBeInTheDocument()
    expect(screen.queryByText('Its summary.')).not.toBeInTheDocument()
  })
})
