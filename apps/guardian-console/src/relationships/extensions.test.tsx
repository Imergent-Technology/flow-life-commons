import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { guardianBoth } from '../test/relationshipPages.ts'
import { PERSON } from '../test/relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.resetModules()
  vi.doUnmock('./extensions.ts')
})

describe('the extension registry', () => {
  it('registers nothing in G10: both types are served by the generic components alone', async () => {
    const { relationshipExtensions } = await import('./extensions.ts')
    expect(relationshipExtensions).toEqual({})
  })

  it('lets a type add a section of its own after the generic panels, without the generic components changing', async () => {
    vi.resetModules()
    vi.doMock('./extensions.ts', () => ({
      relationshipExtensions: {
        guardian: {
          Section: ({ type, personId }: { type: { slug: string }; personId: string }) => (
            <section aria-label="A section only this type adds">{`${type.slug} ${personId}`}</section>
          ),
        },
      },
    }))
    const { default: ReloadedApp } = await import('../App.tsx')
    const { render } = await import('@testing-library/react')
    const { MemoryRouter } = await import('react-router')
    guardianBoth()
    render(
      <MemoryRouter initialEntries={[`/relationships/guardians/${PERSON}`]}>
        <ReloadedApp />
      </MemoryRouter>,
    )
    expect(
      await screen.findByRole('region', { name: 'A section only this type adds' }),
    ).toHaveTextContent(`guardians ${PERSON}`)
    // The generic panels are all still there.
    expect(screen.getByRole('region', { name: 'Status' })).toBeVisible()
  })
})
