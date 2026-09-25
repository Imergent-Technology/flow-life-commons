import { useState, type ReactNode } from 'react'

import { Alert } from './Alert.tsx'
import { Button } from './Button.tsx'
import { Modal } from './Modal.tsx'

/** What the confirmed action came to. `done` closes the dialog; `stay` keeps it open with a message. */
export type ConfirmResult =
  { kind: 'done' } | { kind: 'stay'; tone: 'error' | 'info'; message: string }

/**
 * A deliberate confirmation for an action with consequences: it says what will happen, and nothing happens until the
 * person presses the confirm button. Cancel is the first thing in the tab order and Escape means Cancel, so the safe
 * choice is the easy one. While the request is running the dialog cannot be dismissed.
 */
export function ConfirmDialog({
  title,
  confirmLabel,
  destructive = false,
  onConfirm,
  onCancel,
  children,
}: {
  title: string
  confirmLabel: string
  destructive?: boolean
  onConfirm: () => Promise<ConfirmResult>
  onCancel: () => void
  children: ReactNode
}) {
  const [pending, setPending] = useState(false)
  const [message, setMessage] = useState<{
    tone: 'error' | 'info'
    text: string
    attempt: number
  } | null>(null)

  async function confirm() {
    setPending(true)
    const result = await onConfirm()
    setPending(false)
    if (result.kind === 'stay') {
      setMessage((previous) => ({
        tone: result.tone,
        text: result.message,
        attempt: (previous?.attempt ?? 0) + 1,
      }))
    }
  }

  return (
    <Modal
      title={title}
      onClose={() => {
        if (!pending) onCancel()
      }}
    >
      <div className="flex flex-col gap-3 text-body text-foreground">{children}</div>
      {message ? (
        <Alert key={message.attempt} tone={message.tone} focusOnMount>
          {message.text}
        </Alert>
      ) : null}
      <div className="flex flex-wrap justify-end gap-3">
        <Button variant="secondary" disabled={pending} onClick={onCancel}>
          Cancel
        </Button>
        <Button
          variant={destructive ? 'danger-solid' : 'primary'}
          pending={pending}
          pendingLabel="Working…"
          onClick={() => {
            void confirm()
          }}
        >
          {confirmLabel}
        </Button>
      </div>
    </Modal>
  )
}
