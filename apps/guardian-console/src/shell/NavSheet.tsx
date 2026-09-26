import { Button } from '../ui/Button.tsx'
import { useModalDialog } from '../ui/useModalDialog.ts'
import { Badge, Wordmark } from './Brand.tsx'
import { CloseIcon } from './icons.tsx'
import { NavItemLink } from './NavItemLink.tsx'
import { currentMarker, type Location, type NavSection } from './navigation.ts'
import { Link } from 'react-router'
import { cn } from '../ui/cn.ts'

/** Deterministic: the page you are on, otherwise the first link. */
const focusCurrentOrFirst = (dialog: HTMLDialogElement) =>
  dialog.querySelector<HTMLElement>('nav [aria-current="page"], nav [aria-current="true"]') ??
  dialog.querySelector<HTMLElement>('nav a[href]')

/**
 * The modal navigation sheet below the rail breakpoint: the rail and the drawer merged into one readable list,
 * with full labels. It is a native `<dialog>` from the left, so the browser traps focus, makes the page inert and
 * closes it on Escape; the scrim is the dialog's own backdrop.
 *
 * Closing hands focus back to the menu button (see `useModalDialog`). Choosing a page leaves it to the new
 * page's heading, whose focus effect runs after this dialog's cleanup, so the button never takes it back; if the
 * page chosen is the one already showing, nothing else moves focus and it does land on the button.
 */
export function NavSheet({
  sections,
  location,
  onClose,
}: {
  sections: readonly NavSection[]
  location: Location | null
  onClose: () => void
}) {
  const ref = useModalDialog(focusCurrentOrFirst)

  return (
    <dialog
      ref={ref}
      aria-label="Navigation"
      onCancel={(event) => {
        event.preventDefault()
        onClose()
      }}
      onClick={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
      className="m-0 h-dvh max-h-none w-(--shell-sheet) max-w-[85vw] border-r border-border bg-surface-raised p-0 text-foreground shadow-pop backdrop:bg-scrim motion-safe:animate-drawer-in"
    >
      <div className="flex h-full flex-col gap-5 overflow-y-auto p-4">
        <div className="flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <Badge className="size-(--logo-wordmark)" />
            <Wordmark />
          </div>
          <Button
            variant="ghost"
            size="sm"
            aria-label="Close navigation"
            onClick={onClose}
            className="w-(--control-sm) px-0"
          >
            <CloseIcon />
          </Button>
        </div>
        <nav aria-label="Console" className="flex flex-col gap-5">
          {sections.map((section) =>
            section.groups === undefined ? (
              <Link
                key={section.id}
                to={section.to ?? '/'}
                aria-current={
                  location?.section === section && location.item === undefined ? 'page' : undefined
                }
                onClick={(event) => {
                  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
                  onClose()
                }}
                className={cn(
                  'block rounded-sm border-l-2 px-2.5 py-1.5 text-body font-medium text-foreground hover:bg-muted',
                  location?.section === section
                    ? 'border-primary bg-accent font-semibold text-accent-foreground'
                    : 'border-transparent',
                )}
              >
                {section.label}
              </Link>
            ) : (
              <div key={section.id} className="flex flex-col gap-3">
                <h2 className="px-2.5 text-section font-semibold text-foreground">
                  {section.drawerTitle ?? section.label}
                </h2>
                {section.groups.map((group) => (
                  <div key={group.label} className="flex flex-col gap-1">
                    <h3 className="px-2.5 text-meta font-medium text-muted-foreground">
                      {group.label}
                    </h3>
                    <ul className="flex flex-col gap-0.5">
                      {group.items.map((item) => (
                        <li key={item.to}>
                          <NavItemLink
                            item={item}
                            current={currentMarker(location, item)}
                            onNavigate={onClose}
                          />
                        </li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            ),
          )}
        </nav>
      </div>
    </dialog>
  )
}
