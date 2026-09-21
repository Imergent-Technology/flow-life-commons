import { useEffect, useId, useRef, type ReactNode } from 'react'

/**
 * A modal dialog on the platform's own `<dialog>`: the browser traps focus inside it, makes the rest of the page inert
 * and closes it on Escape. The heading names it for assistive technology, and it focuses that heading when it opens, so
 * a keyboard or screen-reader user is told where they are.
 *
 * Escape and the backdrop are the same as pressing Cancel: `onClose` decides what that means (a dialog that is busy can
 * ignore it).
 *
 * **Focus is handed back here, not by the browser.** A `<dialog>` does restore focus to whatever opened it when it is
 * closed — but React unmounts this component in response to `onClose`, and a passive effect's cleanup runs after the
 * element has already been detached, so by the time `close()` is called there is nothing to restore from. The opener is
 * therefore remembered when the dialog opens and focused again on the way out. Without this a person who cancels is
 * silently returned to the top of the document, which jsdom cannot show (it implements no `<dialog>` focus behaviour at
 * all); a browser journey does, and does.
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
  const ref = useRef<HTMLDialogElement>(null)
  const heading = useRef<HTMLHeadingElement>(null)
  const titleId = useId()

  useEffect(() => {
    const dialog = ref.current
    if (dialog === null) return
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    if (!dialog.open) dialog.showModal()
    heading.current?.focus()
    return () => {
      if (dialog.open) dialog.close()
      // Only if it is still on the page: a confirmed action often replaces the control that opened
      // the dialog, and focusing a detached node would do nothing useful.
      if (opener?.isConnected === true) opener.focus()
    }
  }, [])

  return (
    <dialog
      ref={ref}
      aria-labelledby={titleId}
      onCancel={(event) => {
        event.preventDefault() // the browser would close it; the owner decides
        onClose()
      }}
      className="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-lg border border-slate-300 bg-white p-0 text-slate-900 shadow-xl backdrop:bg-slate-900/50"
    >
      <div className="flex flex-col gap-4 p-5">
        <h2
          id={titleId}
          ref={heading}
          tabIndex={-1}
          className="text-lg font-semibold tracking-tight outline-none"
        >
          {title}
        </h2>
        {children}
      </div>
    </dialog>
  )
}
