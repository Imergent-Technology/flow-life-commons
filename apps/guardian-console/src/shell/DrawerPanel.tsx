import { Button } from '../ui/Button.tsx'
import { cn } from '../ui/cn.ts'
import { CloseIcon, PinIcon } from './icons.tsx'
import { NavItemLink } from './NavItemLink.tsx'
import { DRAWER_ID } from './ids.ts'
import type { Location, NavSection } from './navigation.ts'

/**
 * The secondary navigation for one section: its groups and their pages. Pinned, it is a column of the layout
 * on the translucent navigation surface. As an overlay it floats over the content on a raised surface: non-modal, no
 * scrim, so the page behind stays in the tab order and reachable.
 */
export function DrawerPanel({
  section,
  location,
  variant,
  onNavigate,
  onPinChange,
  onClose,
}: {
  section: NavSection
  location: Location | null
  variant: 'pinned' | 'overlay'
  onNavigate: (to: string) => void
  /** Pin (from the overlay) or unpin (from the pinned drawer): an explicit choice, and the only thing that stores one. */
  onPinChange: () => void
  onClose?: () => void
}) {
  const title = section.drawerTitle ?? section.label
  return (
    <nav
      id={DRAWER_ID}
      aria-label={title}
      data-drawer={variant}
      className={cn(
        'flex flex-col gap-4 overflow-y-auto p-3',
        variant === 'pinned'
          ? 'sticky top-0 h-dvh w-(--shell-drawer) shrink-0 border-r border-nav-border bg-nav'
          : 'fixed inset-y-0 left-(--shell-rail) z-40 w-(--shell-drawer-overlay) border-r border-border bg-surface-raised shadow-pop motion-safe:animate-drawer-in',
      )}
    >
      <div className="flex items-center justify-between gap-2 px-1.5">
        <h2 className="font-display text-dialog-title font-medium text-nav-strong">{title}</h2>
        <div className="flex items-center gap-0.5">
          <Button
            variant="ghost"
            size="sm"
            aria-label={variant === 'pinned' ? 'Unpin navigation panel' : 'Pin navigation panel'}
            title={variant === 'pinned' ? 'Unpin navigation panel' : 'Pin navigation panel'}
            onClick={onPinChange}
            className="w-(--control-sm) px-0"
          >
            <PinIcon />
          </Button>
          {variant === 'overlay' && onClose !== undefined ? (
            <Button
              variant="ghost"
              size="sm"
              aria-label="Close navigation panel"
              title="Close navigation panel"
              onClick={onClose}
              className="w-(--control-sm) px-0"
            >
              <CloseIcon />
            </Button>
          ) : null}
        </div>
      </div>
      <div className="flex flex-col gap-4">
        {section.groups?.map((group) => (
          <div key={group.label} className="flex flex-col gap-1">
            <p className="px-2.5 text-meta font-medium text-nav-muted">{group.label}</p>
            <ul className="flex flex-col gap-0.5">
              {group.items.map((item) => (
                <li key={item.to}>
                  <NavItemLink
                    item={item}
                    current={location?.item === item}
                    onNavigate={onNavigate}
                  />
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </nav>
  )
}
