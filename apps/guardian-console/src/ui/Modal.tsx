import { useEffect, useId, useRef, type ReactNode } from 'react'

/**
 * A modal dialog on the platform's own `<dialog>`: the browser traps focus inside it, makes the rest of the page inert,
 * closes it on Escape and hands focus back to whatever opened it. The heading names it for assistive technology, and it
 * focuses that heading when it opens, so a keyboard or screen-reader user is told where they are.
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
  const ref = useRef<HTMLDialogElement>(null)
  const heading = useRef<HTMLHeadingElement>(null)
  const titleId = useId()

  useEffect(() => {
    const dialog = ref.current
    if (dialog === null) return
    if (!dialog.open) dialog.showModal()
    heading.current?.focus()
    return () => {
      if (dialog.open) dialog.close() // unmounted while open: give focus back to what opened it
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
