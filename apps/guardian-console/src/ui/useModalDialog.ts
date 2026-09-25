import { useEffect, useRef, type RefObject } from 'react'

/**
 * The native `<dialog>` lifecycle both modals share: open it modally on mount, move focus to `initialFocus`,
 * and on the way out close it and hand focus back to whatever opened it. The browser makes the rest of the page
 * inert, traps focus and closes on Escape (the caller's `cancel` handler decides what that means).
 *
 * **Focus is handed back here, not by the browser.** A `<dialog>` does restore focus to whatever opened it — but
 * React unmounts the component in response to `onClose`, and a passive effect's cleanup runs after the element has
 * already been detached, so there is nothing left to restore from. The opener is remembered when the dialog opens
 * and focused again on the way out. jsdom implements no `<dialog>` focus behaviour at all; a browser journey
 * proves it.
 *
 * `initialFocus` must have a stable identity (define it outside the component).
 */
export function useModalDialog(
  initialFocus: (dialog: HTMLDialogElement) => HTMLElement | null,
): RefObject<HTMLDialogElement | null> {
  const ref = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const dialog = ref.current
    if (dialog === null) return
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    if (!dialog.open) dialog.showModal()
    initialFocus(dialog)?.focus()
    return () => {
      if (dialog.open) dialog.close()
      // Only if it is still on the page: a confirmed action often replaces the control that opened
      // the dialog, and focusing a detached node would do nothing useful.
      if (opener?.isConnected === true) opener.focus()
    }
  }, [initialFocus])

  return ref
}
