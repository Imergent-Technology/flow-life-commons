import { useEffect, useId, useState, type ReactNode } from 'react'

/**
 * Supporting text for a control, shown on hover AND on keyboard focus, never on hover alone. The control stays the trigger: it is
 * handed the id to put in `aria-describedby`, so a screen reader gets the text as the control's description whether or not it is
 * drawn. The text is plain and holds nothing to operate (a tooltip with a link in it is a popover, and this is not one).
 *
 * It meets the three rules for content that appears on hover or focus (WCAG 1.4.13): it can be dismissed without moving the pointer
 * or focus (Escape, wherever focus is: a tooltip opened by a resting pointer while focus is elsewhere is dismissed too), the pointer
 * can move onto it without it vanishing, and it stays until one of those happens. Nothing the person needs in order to use the
 * control is only here.
 *
 * A click puts the text away too: on the control (or Enter on a link) the person has acted on it, and on the text itself they are
 * aiming at what it covers. Otherwise it would stay over whatever is drawn beneath it (the next item of a list) for as long as the
 * pointer rests there. Leaving and coming back, by pointer or by focus, shows it again.
 *
 * With no text, nothing is drawn and the control gets no `aria-describedby`.
 */
export function Tooltip({
  text,
  children,
}: {
  text: string | undefined
  children: (describedBy: string | undefined) => ReactNode
}) {
  const id = useId()
  const [hovered, setHovered] = useState(false)
  const [focused, setFocused] = useState(false)
  const [dismissed, setDismissed] = useState(false)

  const drawn = text !== undefined && text.trim() !== ''
  const open = drawn && (hovered || focused) && !dismissed

  // Escape is listened for on the document while the text is showing, not on the trigger: a person whose pointer rests on the
  // control may have focus anywhere. It neither stops nor prevents the key, so whatever else Escape means on the page still happens.
  useEffect(() => {
    if (!open) return
    const onKeyDown = (event: globalThis.KeyboardEvent) => {
      if (event.key === 'Escape') setDismissed(true)
    }
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  if (!drawn) return <>{children(undefined)}</>

  return (
    <span
      className="relative block"
      onMouseEnter={() => {
        setHovered(true)
        setDismissed(false)
      }}
      onMouseLeave={() => {
        setHovered(false)
      }}
      onFocus={() => {
        setFocused(true)
        setDismissed(false)
      }}
      onBlur={() => {
        setFocused(false)
      }}
      onClick={() => {
        setDismissed(true)
      }}
    >
      {children(id)}
      <span
        id={id}
        role="tooltip"
        hidden={!open}
        className="absolute top-full left-0 z-20 w-max max-w-full pt-1"
      >
        <span className="block rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-meta wrap-anywhere text-foreground shadow-panel">
          {text}
        </span>
      </span>
    </span>
  )
}
