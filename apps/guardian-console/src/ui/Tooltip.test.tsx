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
