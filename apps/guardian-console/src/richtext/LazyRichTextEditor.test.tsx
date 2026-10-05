import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { deferred } from '../test/deferred'

afterEach(() => {
  vi.resetModules()
  vi.doUnmock('./RichTextEditor.tsx')
})

const props = (label = 'Card content') => ({
  value: { type: 'doc', content: [] },
  onChange: () => undefined,
  label,
})

/**
 * Each test imports a FRESH copy of the wrapper (its module-level `lazy` components are shared state), with the editor module
 * replaced by one the test controls, so what the person sees while the chunk is on its way is observable.
 */
async function wrapperWith(editorModule: () => Promise<unknown>) {
  vi.resetModules()
  vi.doMock('./RichTextEditor.tsx', editorModule)
  return import('./LazyRichTextEditor.tsx')
}

describe('the lazily loaded editor', () => {
  it('does not load the editor until a screen renders it, then says it is loading, then shows it', async () => {
    const gate = deferred<undefined>()
    const loaded = vi.fn()
    const { LazyRichTextEditor } = await wrapperWith(async () => {
      loaded()
      await gate.promise
      return {
        RichTextEditor: ({ label }: { label: string }) => <div role="textbox" aria-label={label} />,
      }
    })

    // Importing the wrapper loaded nothing: the editor is asked for only when it is drawn.
    expect(loaded).not.toHaveBeenCalled()

    render(<LazyRichTextEditor {...props()} />)

    const status = await screen.findByRole('status')
    expect(status).toHaveAttribute('aria-busy', 'true')
    expect(status).toHaveTextContent('Loading the editor for Card content…')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(loaded).toHaveBeenCalledTimes(1)

    gate.resolve(undefined)
    expect(await screen.findByRole('textbox', { name: 'Card content' })).toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('tells the person, without losing the page, when the editor cannot be fetched, and tries again when asked', async () => {
    const user = userEvent.setup()
    let asks = 0
    const { LazyRichTextEditor } = await wrapperWith(() => {
      asks += 1
      return asks === 1
        ? Promise.reject(new Error('Failed to fetch dynamically imported module'))
        : Promise.resolve({
            RichTextEditor: ({ label }: { label: string }) => (
              <div role="textbox" aria-label={label} />
            ),
          })
    })
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    render(
      <>
        <p>The rest of the form</p>
        <LazyRichTextEditor {...props()} />
      </>,
    )

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(
      'The editor could not be loaded. Check your connection and try again.',
    )
    expect(alert).not.toHaveTextContent('Failed to fetch')
    expect(screen.getByText('The rest of the form')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Try again' }))

    expect(await screen.findByRole('textbox', { name: 'Card content' })).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(asks).toBe(2)
  })

  it('stops offering to try again after a few failures, and says to reload instead', async () => {
    const user = userEvent.setup()
    const { LazyRichTextEditor } = await wrapperWith(() =>
      Promise.reject(new Error('Failed to fetch dynamically imported module')),
    )
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    render(<LazyRichTextEditor {...props()} />)

    for (let ask = 0; ask < 2; ask++) {
      await user.click(await screen.findByRole('button', { name: 'Try again' }))
    }
    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent(
        'Reload the page when your connection is back.',
      )
    })
    expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument()
  })
})
