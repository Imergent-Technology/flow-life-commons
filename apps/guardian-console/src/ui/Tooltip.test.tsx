import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'

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
