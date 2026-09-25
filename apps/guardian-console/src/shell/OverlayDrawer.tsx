import { useEffect, useRef } from 'react'

import { DrawerPanel } from './DrawerPanel.tsx'
import type { Location, NavSection } from './navigation.ts'
import { railTriggerId } from './ids.ts'

/**
 * The overlay drawer's behaviour, around the same panel the pinned drawer uses. It is deliberately not a
 * `<dialog>`: it is non-modal, and the page behind it must stay reachable.
 *
 * - Opening moves focus to the current page in it, or the first.
 * - Escape closes it and returns focus to the rail control that opened it.
 * - A pointer press outside it (other than on a rail control, which toggles it itself) closes it, leaving focus
 *   where the person put it.
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

  useEffect(() => {
    const panel = wrapper.current
    const target =
      panel?.querySelector<HTMLElement>('[aria-current="page"]') ??
      panel?.querySelector<HTMLElement>('a[href]')
    target?.focus()
  }, [section.id])

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      onClose()
      document.getElementById(railTriggerId(section.id))?.focus()
    }
    const onPointerDown = (event: PointerEvent) => {
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
    <div ref={wrapper}>
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
