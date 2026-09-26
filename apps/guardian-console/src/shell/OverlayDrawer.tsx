import { useEffect, useRef } from 'react'

import { DrawerPanel } from './DrawerPanel.tsx'
import type { Location, NavSection } from './navigation.ts'
import { railTriggerId } from './ids.ts'

/**
 * The overlay drawer's behaviour, around the same panel the pinned drawer uses. It is deliberately not a
 * `<dialog>`: it is non-modal, and the page behind it must stay reachable.
 *
 * It sits in the document right after the rail, so the tab order is rail, drawer, top bar, page: the order it is
 * seen in, and the one the pinned drawer has.
 *
 * - Opening moves focus to the current page in it, or the first.
 * - Escape closes it and returns focus to the rail control that opened it.
 * - Focus leaving it by the keyboard (Tab past its last control, or Shift+Tab back to the rail) closes it, and the
 *   element that took focus keeps it: nothing is trapped and nothing is bounced back.
 * - A pointer press outside it (other than on a rail control, which toggles it itself) closes it, leaving focus
 *   where the person put it. A press decides for itself, so it is not also treated as focus leaving.
 */
export function OverlayDrawer({
  section,
  location,
  onNavigate,
  onPinChange,
  onClose,
}: {
  section: NavSection
  location: Location | null
  onNavigate: (to: string) => void
  onPinChange: () => void
  onClose: () => void
}) {
  const wrapper = useRef<HTMLDivElement>(null)
  /** True from a pointer press until the next key: the press, not the keyboard, moved focus. */
  const pressed = useRef(false)

  useEffect(() => {
    const panel = wrapper.current
    const target =
      panel?.querySelector<HTMLElement>('[aria-current="page"], [aria-current="true"]') ??
      panel?.querySelector<HTMLElement>('a[href]')
    target?.focus()
  }, [section.id])

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      pressed.current = false
      if (event.key !== 'Escape') return
      onClose()
      document.getElementById(railTriggerId(section.id))?.focus()
    }
    const onPointerDown = (event: PointerEvent) => {
      pressed.current = true
      const target = event.target
      if (!(target instanceof Element)) return
      if (wrapper.current?.contains(target) === true) return
      if (target.closest('[data-nav-trigger]') !== null) return
      onClose()
    }
    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
    }
  }, [onClose, section.id])

  return (
    <div
      ref={wrapper}
      onBlur={(event) => {
        // Only focus that went to another element: a window losing focus, or an element going away, has no
        // `relatedTarget`, and is not the person leaving.
        const next = event.relatedTarget
        if (pressed.current || !(next instanceof Node) || wrapper.current?.contains(next) === true)
          return
        onClose()
      }}
    >
      <DrawerPanel
        section={section}
        location={location}
        variant="overlay"
        onNavigate={onNavigate}
        onPinChange={onPinChange}
        onClose={onClose}
      />
    </div>
  )
}
