import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'

import { ConfirmDialog, type ConfirmResult } from './ConfirmDialog.tsx'
import { Modal } from './Modal.tsx'

function Harness({
  onConfirm,
  destructive = false,
}: {
  onConfirm: () => Promise<ConfirmResult>
  destructive?: boolean
}) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button
        onClick={() => {
          setOpen(true)
        }}
      >
        Disable account
      </button>
      {open ? (
        <ConfirmDialog
          title="Disable this account?"
          confirmLabel="Disable"
          destructive={destructive}
          onConfirm={onConfirm}
          onCancel={() => {
            setOpen(false)
          }}
        >
          They will be signed out.
        </ConfirmDialog>
      ) : null}
    </>
  )
}

/** jsdom fires no `cancel` for Escape (it has no <dialog> behaviour), so the event a browser would send is sent by hand. */
function pressEscape(dialog: HTMLElement) {
  fireEvent(dialog, new Event('cancel', { cancelable: true }))
}

describe('Modal', () => {
  it('is a native modal <dialog> named by its heading, styled from the semantic tokens', () => {
    render(
      <Modal title="Details" onClose={vi.fn()}>
        Content
      </Modal>,
    )
    const dialog = screen.getByRole('dialog', { name: 'Details' })
    expect(dialog.tagName).toBe('DIALOG')
    expect(dialog).toHaveAttribute('open')
    expect(dialog).toHaveClass('bg-surface-raised', 'shadow-pop', 'backdrop:bg-scrim')
    expect(screen.getByRole('heading', { level: 2, name: 'Details' })).toHaveClass('font-display')
  })

  it('focuses its heading when it opens', () => {
    render(
      <Modal title="Details" onClose={vi.fn()}>
        Content
      </Modal>,
    )
    expect(screen.getByRole('heading', { name: 'Details' })).toHaveFocus()
  })

  it('hands Escape to its owner rather than closing itself', () => {
    const onClose = vi.fn()
    render(
      <Modal title="Details" onClose={onClose}>
        Content
      </Modal>,
    )
    const dialog = screen.getByRole('dialog')
    const event = new Event('cancel', { cancelable: true })
    fireEvent(dialog, event)
    expect(event.defaultPrevented).toBe(true)
    expect(onClose).toHaveBeenCalledTimes(1)
    expect(dialog).toHaveAttribute('open')
  })
})

describe('ConfirmDialog', () => {
  it('puts Cancel before the confirming action, and first in the tab order', async () => {
    const user = userEvent.setup()
    render(<Harness onConfirm={() => Promise.resolve({ kind: 'done' })} destructive />)
    await user.click(screen.getByRole('button', { name: 'Disable account' }))

    const dialog = screen.getByRole('dialog', { name: 'Disable this account?' })
    const buttons = Array.from(dialog.querySelectorAll('button')).map((b) => b.textContent)
    expect(buttons).toEqual(['Cancel', 'Disable'])

    // Focus starts on the heading (as it always has), so one Tab reaches Cancel, never the confirm.
    expect(screen.getByRole('heading', { name: 'Disable this account?' })).toHaveFocus()
    await user.tab()
    expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus()
  })

  it('uses the solid danger button only for a destructive confirmation', async () => {
    const user = userEvent.setup()
    const { unmount } = render(
      <Harness onConfirm={() => Promise.resolve({ kind: 'done' })} destructive />,
    )
    await user.click(screen.getByRole('button', { name: 'Disable account' }))
    expect(screen.getByRole('button', { name: 'Disable' })).toHaveClass('bg-danger')
    expect(screen.getByRole('button', { name: 'Cancel' })).not.toHaveClass('bg-danger')
    unmount()

    render(<Harness onConfirm={() => Promise.resolve({ kind: 'done' })} />)
    await user.click(screen.getByRole('button', { name: 'Disable account' }))
    expect(screen.getByRole('button', { name: 'Disable' })).toHaveClass('bg-primary')
    expect(screen.getByRole('button', { name: 'Disable' })).not.toHaveClass('bg-danger')
  })

  it('returns focus to what opened it when cancelled with the button or with Escape', async () => {
    const user = userEvent.setup()
    render(<Harness onConfirm={() => Promise.resolve({ kind: 'done' })} />)
    const opener = screen.getByRole('button', { name: 'Disable account' })

    await user.click(opener)
    await user.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(opener).toHaveFocus()

    await user.click(opener)
    pressEscape(screen.getByRole('dialog'))
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(opener).toHaveFocus()
  })

  it('cannot be dismissed or re-confirmed while the request runs', async () => {
    const user = userEvent.setup()
    const onConfirm = vi.fn(() => new Promise<ConfirmResult>(() => undefined))
    render(<Harness onConfirm={onConfirm} destructive />)
    await user.click(screen.getByRole('button', { name: 'Disable account' }))
    await user.click(screen.getByRole('button', { name: 'Disable' }))

    expect(screen.getByRole('button', { name: 'Working…' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Working…' }))
    pressEscape(screen.getByRole('dialog'))
    expect(onConfirm).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('dialog')).toBeVisible()
  })

  it('stays open with an announced message when the action asks it to', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        onConfirm={() => Promise.resolve({ kind: 'stay', tone: 'error', message: 'Not allowed.' })}
      />,
    )
    await user.click(screen.getByRole('button', { name: 'Disable account' }))
    await user.click(screen.getByRole('button', { name: 'Disable' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Not allowed.')
    expect(alert).toHaveFocus()
    expect(screen.getByRole('dialog')).toBeVisible()
  })
})
