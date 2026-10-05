import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../test/a11y.ts'
import { deferred } from '../../test/deferred.ts'
import { ReorderList, type ReorderOutcome } from './ReorderList.tsx'

const NAMES = ['Alpha', 'Bravo', 'Charlie']

/** A parent that, like the pages, replaces the list with the order the "server" accepted. */
function Harness({ onReorder }: { onReorder: (ids: string[]) => Promise<ReorderOutcome> }) {
  const [ids, setIds] = useState(NAMES.map((name) => name.toLowerCase()))
  return (
    <ReorderList
      label="Things"
      items={ids.map((id) => ({
        id,
        name: `${id.charAt(0).toUpperCase()}${id.slice(1)}`,
        children: <span>{id}</span>,
      }))}
      onReorder={async (next) => {
        const outcome = await onReorder(next)
        if (outcome.ok) setIds(next)
        return outcome
      }}
    />
  )
}

const order = () =>
  within(screen.getByRole('list', { name: 'Things' }))
    .getAllByRole('listitem')
    .map((li) => li.querySelector('span')?.textContent)

describe('ReorderList', () => {
  it('is operable entirely from the keyboard: the controls are buttons in the tab order, and Enter and Space work', async () => {
    const user = userEvent.setup()
    const onReorder = vi.fn(() => Promise.resolve<ReorderOutcome>({ ok: true }))
    render(<Harness onReorder={onReorder} />)

    await user.tab()
    // The first control reached is Alpha's "Move down" (its "Move up" is disabled at the top).
    expect(screen.getByRole('button', { name: 'Move Alpha down' })).toHaveFocus()
    await user.keyboard('{Enter}')
    await waitFor(() => {
      expect(order()).toEqual(['bravo', 'alpha', 'charlie'])
    })
    expect(onReorder).toHaveBeenLastCalledWith(['bravo', 'alpha', 'charlie'])

    // Focus stays on the control that was used, on the item that moved.
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Move Alpha down' })).toHaveFocus()
    })
    await user.keyboard(' ')
    await waitFor(() => {
      expect(order()).toEqual(['bravo', 'charlie', 'alpha'])
    })
    expect(onReorder).toHaveBeenLastCalledWith(['bravo', 'charlie', 'alpha'])
    // Alpha is now last, so "Move down" is gone as a choice: focus moves to the opposite control rather than being lost.
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Move Alpha up' })).toHaveFocus()
    })
  })

  it('offers no drag handle: reordering is by Move up and Move down only', () => {
    const { container } = render(<Harness onReorder={() => Promise.resolve({ ok: true })} />)
    expect(container.querySelector('[draggable]')).toBeNull()
    expect(screen.getAllByRole('button').map((b) => b.getAttribute('aria-label'))).toEqual([
      'Move Alpha up',
      'Move Alpha down',
      'Move Bravo up',
      'Move Bravo down',
      'Move Charlie up',
      'Move Charlie down',
    ])
  })

  it('cannot move the first item up or the last item down', () => {
    render(<Harness onReorder={() => Promise.resolve({ ok: true })} />)
    expect(screen.getByRole('button', { name: 'Move Alpha up' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Move Charlie down' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Move Bravo up' })).toBeEnabled()
  })

  it('sends the complete new order, and shows nothing moved, with every control waiting, until the server answers', async () => {
    const user = userEvent.setup()
    const held = deferred<ReorderOutcome>()
    const onReorder = vi.fn(() => held.promise)
    render(<Harness onReorder={onReorder} />)

    await user.click(screen.getByRole('button', { name: 'Move Charlie up' }))

    expect(onReorder).toHaveBeenCalledWith(['alpha', 'charlie', 'bravo'])
    expect(order()).toEqual(['alpha', 'bravo', 'charlie'])
    for (const button of screen.getAllByRole('button')) expect(button).toBeDisabled()

    held.resolve({ ok: true })
    await waitFor(() => {
      expect(order()).toEqual(['alpha', 'charlie', 'bravo'])
    })
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Move Charlie up' })).toHaveFocus()
    })
  })

  it('announces a move in words, politely, for assistive technology', async () => {
    const user = userEvent.setup()
    render(<Harness onReorder={() => Promise.resolve({ ok: true })} />)

    await user.click(screen.getByRole('button', { name: 'Move Bravo down' }))

    await waitFor(() => {
      expect(screen.getByText('Moved Bravo to position 3 of 3.')).toBeInTheDocument()
    })
    expect(screen.getByText('Moved Bravo to position 3 of 3.')).toHaveAttribute('role', 'status')
  })

  it('keeps the old order, says why in an alert that takes focus, and gives the controls back when the server refuses', async () => {
    const user = userEvent.setup()
    const onReorder = vi.fn(() =>
      Promise.resolve<ReorderOutcome>({
        ok: false,
        message: 'The list changed while you were reordering it.',
      }),
    )
    render(<Harness onReorder={onReorder} />)

    await user.click(screen.getByRole('button', { name: 'Move Alpha down' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('The list changed while you were reordering it.')
    expect(alert).toHaveFocus()
    expect(order()).toEqual(['alpha', 'bravo', 'charlie'])
    expect(screen.getByRole('button', { name: 'Move Alpha down' })).toBeEnabled()
    expect(screen.queryByText(/Moved/)).not.toBeInTheDocument()
  })

  it('has no accessibility violations', async () => {
    render(
      <main>
        <Harness onReorder={() => Promise.resolve({ ok: true })} />
      </main>,
    )
    await expectNoAxeViolations()
  })
})
