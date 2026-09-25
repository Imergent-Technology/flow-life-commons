import { useId, type ReactNode } from 'react'

import { useModalDialog } from './useModalDialog.ts'

const focusHeading = (dialog: HTMLDialogElement) => dialog.querySelector<HTMLElement>('h2')

/**
 * A modal dialog on the platform's own `<dialog>` (see `useModalDialog`): the browser traps focus inside it, makes
 * the rest of the page inert and closes it on Escape. The heading names it for assistive technology, and it focuses
 * that heading when it opens, so a keyboard or screen-reader user is told where they are.
 *
 * Escape and the backdrop are the same as pressing Cancel: `onClose` decides what that means (a dialog that is busy can
 * ignore it).
 */
export function Modal({
  title,
  onClose,
  children,
}: {
  title: string
  onClose: () => void
  children: ReactNode
}) {
  const ref = useModalDialog(focusHeading)
  const titleId = useId()

  return (
    <dialog
      ref={ref}
      aria-labelledby={titleId}
      onCancel={(event) => {
        event.preventDefault() // the browser would close it; the owner decides
        onClose()
      }}
      className="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-lg border border-border bg-surface-raised p-0 text-foreground shadow-pop backdrop:bg-scrim"
    >
      <div className="flex flex-col gap-4 p-5">
        <h2
          id={titleId}
          tabIndex={-1}
          className="font-display text-dialog-title font-medium text-foreground outline-none"
        >
          {title}
        </h2>
        {children}
      </div>
    </dialog>
  )
}
