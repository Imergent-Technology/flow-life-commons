import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y.ts'
import { Tooltip } from './Tooltip.tsx'

function Subject({ text }: { text: string | undefined }) {
  return (
    <main>
      <Tooltip text={text}>
        {(describedBy) => (
          <button type="button" aria-describedby={describedBy}>
            Trigger
          </button>
        )}
      </Tooltip>
      <button type="button">Elsewhere</button>
    </main>
  )
}

describe('Tooltip', () => {
  it('shows on hover and hides when the pointer leaves', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)

    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    await user.hover(screen.getByRole('button', { name: 'Trigger' }))
    expect(screen.getByRole('tooltip')).toHaveTextContent('Help text')
    await user.unhover(screen.getByRole('button', { name: 'Trigger' }))
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
  })

  it('shows on keyboard focus, which is the whole point, and hides on blur', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)

    await user.tab()
    expect(screen.getByRole('button', { name: 'Trigger' })).toHaveFocus()
    expect(screen.getByRole('tooltip')).toHaveTextContent('Help text')
    await user.tab()
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
  })

  it('describes its trigger whether or not it is drawn', () => {
    render(<Subject text="Help text" />)
    expect(screen.getByRole('button', { name: 'Trigger' })).toHaveAccessibleDescription('Help text')
  })

  it('can be dismissed with Escape without moving focus, and returns on the next focus', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    await user.tab()
    expect(screen.getByRole('tooltip')).toBeInTheDocument()

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Trigger' })).toHaveFocus()

    await user.tab()
    await user.tab({ shift: true })
    expect(screen.getByRole('tooltip')).toBeInTheDocument()
  })

  it('is dismissed with Escape while the pointer rests on the control and focus is elsewhere (WCAG 1.4.13)', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    const elsewhere = screen.getByRole('button', { name: 'Elsewhere' })
    elsewhere.focus()
    await user.hover(screen.getByRole('button', { name: 'Trigger' }))
    expect(screen.getByRole('tooltip')).toBeInTheDocument()

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    // Neither focus nor the pointer had to move.
    expect(elsewhere).toHaveFocus()
  })

  it('is dismissed with Escape while the pointer rests on the text itself', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    screen.getByRole('button', { name: 'Elsewhere' }).focus()
    await user.hover(screen.getByRole('button', { name: 'Trigger' }))
    await user.hover(screen.getByRole('tooltip'))

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
  })

  it('shows again when the pointer leaves and comes back after a dismissal', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    const trigger = screen.getByRole('button', { name: 'Trigger' })
    await user.hover(trigger)
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()

    // Still resting there: it stays away.
    await user.hover(trigger)
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()

    await user.unhover(trigger)
    await user.hover(trigger)
    expect(screen.getByRole('tooltip')).toHaveTextContent('Help text')
  })

  it('shows on focus after a dismissal made while hovering', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    await user.hover(screen.getByRole('button', { name: 'Trigger' }))
    await user.keyboard('{Escape}')
    await user.unhover(screen.getByRole('button', { name: 'Trigger' }))

    await user.tab()
    expect(screen.getByRole('button', { name: 'Trigger' })).toHaveFocus()
    expect(screen.getByRole('tooltip')).toHaveTextContent('Help text')
  })

  it('is put away when the control is used, so it does not cover what is beneath it, and returns on the next visit', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    const trigger = screen.getByRole('button', { name: 'Trigger' })

    await user.click(trigger)
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()

    await user.unhover(trigger)
    await user.hover(trigger)
    expect(screen.getByRole('tooltip')).toBeInTheDocument()

    // A click on the text itself (aimed at whatever it covers) puts it away as well.
    await user.click(screen.getByRole('tooltip'))
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
  })

  it('is put away when the control is activated from the keyboard', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    await user.tab()
    expect(screen.getByRole('tooltip')).toBeInTheDocument()

    await user.keyboard('{Enter}')
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Trigger' })).toHaveFocus()
  })

  it('does not stop or prevent Escape for the rest of the page', async () => {
    const user = userEvent.setup()
    const seen: string[] = []
    const listener = (event: KeyboardEvent) => {
      seen.push(`${event.key}:${String(event.defaultPrevented)}`)
    }
    window.addEventListener('keydown', listener)
    try {
      render(<Subject text="Help text" />)
      await user.hover(screen.getByRole('button', { name: 'Trigger' }))
      await user.keyboard('{Escape}')
      expect(seen).toEqual(['Escape:false'])
      expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    } finally {
      window.removeEventListener('keydown', listener)
    }
  })

  it('does not hold focus: Tab leaves the control as usual while the text shows', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    await user.hover(screen.getByRole('button', { name: 'Trigger' }))
    await user.tab()
    expect(screen.getByRole('button', { name: 'Trigger' })).toHaveFocus()
    await user.tab()
    expect(screen.getByRole('button', { name: 'Elsewhere' })).toHaveFocus()
  })

  it('lets the pointer move onto the tooltip without it vanishing', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    await user.hover(screen.getByRole('button', { name: 'Trigger' }))

    await user.hover(screen.getByRole('tooltip'))
    expect(screen.getByRole('tooltip')).toBeInTheDocument()
  })

  it.each([undefined, '', '   '])('draws nothing, and describes nothing, for %j', (text) => {
    render(<Subject text={text} />)

    expect(screen.queryByRole('tooltip', { hidden: true })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Trigger' })).not.toHaveAttribute('aria-describedby')
  })

  it('has no accessibility violations, open or closed', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    await expectNoAxeViolations()
    await user.tab()
    await expectNoAxeViolations()
  })
})

/**
 * The document-level `keydown` listeners that are registered right now: every `addEventListener('keydown', f)` made since this was
 * called, less every `removeEventListener('keydown', f)` naming the same function. The Tooltip's Escape handling is invisible in the
 * page once it has closed (a stale handler would only set state nothing reads until the next visit resets it), so the registration
 * itself is what is observed. Set up AFTER render, so only what the Tooltip does is counted.
 */
function documentKeydownListeners() {
  const added = vi.spyOn(document, 'addEventListener')
  const removed = vi.spyOn(document, 'removeEventListener')
  added.mockClear()
  removed.mockClear()
  return (): number => {
    const live = added.mock.calls
      .filter(([type]) => type === 'keydown')
      .map(([, listener]) => listener)
    for (const [type, listener] of removed.mock.calls) {
      const at = live.indexOf(listener)
      if (type === 'keydown' && at !== -1) live.splice(at, 1)
    }
    return live.length
  }
}

describe('Tooltip: the Escape listener it registers on the document', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('exists exactly while the text shows: not before, and not after the pointer, focus, Escape or a click closed it', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    const listeners = documentKeydownListeners()
    const trigger = screen.getByRole('button', { name: 'Trigger' })
    expect(listeners()).toBe(0)

    await user.hover(trigger)
    expect(screen.getByRole('tooltip')).toBeInTheDocument()
    expect(listeners()).toBe(1)
    await user.unhover(trigger)
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    expect(listeners()).toBe(0)

    await user.tab()
    expect(listeners()).toBe(1)
    await user.tab()
    expect(listeners()).toBe(0)

    await user.hover(trigger)
    expect(listeners()).toBe(1)
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    expect(listeners()).toBe(0)

    await user.unhover(trigger)
    await user.hover(trigger)
    expect(listeners()).toBe(1)
    await user.click(trigger)
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    expect(listeners()).toBe(0)
  })

  it('is not left behind when the Tooltip is removed while its text shows', async () => {
    const user = userEvent.setup()
    const { unmount } = render(<Subject text="Help text" />)
    const listeners = documentKeydownListeners()
    await user.hover(screen.getByRole('button', { name: 'Trigger' }))
    expect(listeners()).toBe(1)

    unmount()

    expect(listeners()).toBe(0)
  })

  it('is not left behind when the text is taken away while it shows', async () => {
    const user = userEvent.setup()
    const { rerender } = render(<Subject text="Help text" />)
    const listeners = documentKeydownListeners()
    await user.hover(screen.getByRole('button', { name: 'Trigger' }))
    expect(listeners()).toBe(1)

    rerender(<Subject text={undefined} />)

    expect(listeners()).toBe(0)
  })

  it('hears Escape once per showing: closing and showing again does not stack listeners', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    const listeners = documentKeydownListeners()
    const trigger = screen.getByRole('button', { name: 'Trigger' })

    for (let visit = 0; visit < 3; visit++) {
      await user.hover(trigger)
      expect(listeners()).toBe(1)
      await user.unhover(trigger)
      expect(listeners()).toBe(0)
    }
  })

  it('does nothing to a closed Tooltip when Escape is pressed: the next visit shows it as usual', async () => {
    const user = userEvent.setup()
    render(<Subject text="Help text" />)
    const trigger = screen.getByRole('button', { name: 'Trigger' })
    await user.hover(trigger)
    await user.unhover(trigger)
    await user.keyboard('{Escape}') // nothing is listening for it any more
    await user.keyboard('{Escape}')

    await user.tab()
    expect(screen.getByRole('tooltip')).toHaveTextContent('Help text')
    await user.hover(trigger)
    expect(screen.getByRole('tooltip')).toBeInTheDocument()
  })
})

describe('Tooltip: two of them on one page', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  function Pair() {
    return (
      <main>
        <Tooltip text="First help">
          {(describedBy) => (
            <button type="button" aria-describedby={describedBy}>
              First
            </button>
          )}
        </Tooltip>
        <Tooltip text="Second help">
          {(describedBy) => (
            <button type="button" aria-describedby={describedBy}>
              Second
            </button>
          )}
        </Tooltip>
      </main>
    )
  }

  it('each keeps its own listener and own state: putting one away leaves the other showing and still dismissable', async () => {
    const user = userEvent.setup()
    render(<Pair />)
    const listeners = documentKeydownListeners()
    const first = screen.getByRole('button', { name: 'First' })

    await user.tab() // focus passes through the first button...
    await user.tab() // ...and rests on the second, which shows by focus
    expect(screen.getByRole('button', { name: 'Second' })).toHaveFocus()
    await user.hover(first) // and the pointer rests on the first, which shows by the pointer
    expect(screen.getAllByRole('tooltip').map((tip) => tip.textContent)).toEqual([
      'First help',
      'Second help',
    ])
    expect(listeners()).toBe(2)

    // The pointer leaves the first: it puts away only the first, and takes away only its listener.
    await user.unhover(first)
    expect(screen.getAllByRole('tooltip').map((tip) => tip.textContent)).toEqual(['Second help'])
    expect(listeners()).toBe(1)

    // The second's own listener still hears Escape.
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    expect(listeners()).toBe(0)
  })

  it("shows each one's own text for its own control, whichever was visited last", async () => {
    const user = userEvent.setup()
    render(<Pair />)

    await user.hover(screen.getByRole('button', { name: 'Second' }))
    expect(screen.getByRole('tooltip')).toHaveTextContent('Second help')
    await user.unhover(screen.getByRole('button', { name: 'Second' }))
    await user.hover(screen.getByRole('button', { name: 'First' }))
    expect(screen.getByRole('tooltip')).toHaveTextContent('First help')
    expect(screen.getByRole('button', { name: 'First' })).toHaveAccessibleDescription('First help')
    expect(screen.getByRole('button', { name: 'Second' })).toHaveAccessibleDescription(
      'Second help',
    )
  })
})
