import { useState, type ReactNode } from 'react'

import { Alert } from './Alert.tsx'
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
      <div className="flex flex-col gap-3 text-sm text-slate-700">{children}</div>
      {message ? (
        <Alert key={message.attempt} tone={message.tone} focusOnMount>
          {message.text}
        </Alert>
      ) : null}
      <div className="flex flex-wrap justify-end gap-3">
        <button
          type="button"
          disabled={pending}
          onClick={onCancel}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:opacity-50"
        >
          Cancel
        </button>
        <button
          type="button"
          disabled={pending}
          onClick={() => {
            void confirm()
          }}
          className={`rounded-md px-3 py-1.5 text-sm font-medium text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:cursor-not-allowed disabled:opacity-60 ${destructive ? 'bg-red-700 hover:bg-red-800' : 'bg-slate-900 hover:bg-slate-700'}`}
        >
          {pending ? 'Working…' : confirmLabel}
        </button>
      </div>
    </Modal>
  )
}
