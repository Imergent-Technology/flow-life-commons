import { useId, useState, type KeyboardEvent, type ReactNode } from 'react'

/**
 * Supporting text for a control, shown on hover AND on keyboard focus, never on hover alone. The control stays the trigger: it is
 * handed the id to put in `aria-describedby`, so a screen reader gets the text as the control's description whether or not it is
 * drawn. The text is plain and holds nothing to operate (a tooltip with a link in it is a popover, and this is not one).
 *
 * It meets the three rules for content that appears on hover or focus: it can be dismissed without moving the pointer or focus
 * (Escape), the pointer can move onto it without it vanishing, and it stays until one of those happens. Nothing the person needs in
 * order to use the control is only here.
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

  if (text === undefined || text.trim() === '') return <>{children(undefined)}</>

  const open = (hovered || focused) && !dismissed

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
      onKeyDown={(event: KeyboardEvent) => {
        if (event.key === 'Escape' && open) setDismissed(true)
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
