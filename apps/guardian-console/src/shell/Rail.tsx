import { Link } from 'react-router'

import { cn } from '../ui/cn.ts'
import { Badge } from '../ui/Brand.tsx'
import { SectionIcon } from './icons.tsx'
import { DRAWER_ID, railTriggerId } from './ids.ts'
import { firstDestination, type NavSection } from './navigation.ts'

function Label({ section, current }: { section: NavSection; current: boolean }) {
  return (
    <>
      <span
        className={cn(
          'flex h-7.5 w-11 items-center justify-center rounded-pill',
          current
            ? 'bg-nav-active text-nav-active-icon'
            : 'text-nav-foreground group-hover:bg-nav-active/60',
        )}
      >
        <SectionIcon name={section.icon} />
      </span>
      <span
        className={cn(
          'text-meta leading-tight',
          current ? 'font-semibold text-nav-strong' : 'text-nav-foreground',
        )}
      >
        {section.label}
      </span>
    </>
  )
}

const control = 'group flex h-12 w-15 flex-col items-center justify-center gap-0.5 rounded-sm'

/**
 * The attached rail: the badge, then one control per section, each with an icon AND its label. A section with a
 * drawer is a toggle for the overlay drawer, or (when the drawer is pinned) a link to the section's first page. The
 * section that contains the current page is `aria-current`.
 */
export function Rail({
  sections,
  currentSectionId,
  drawer,
  openSectionId,
  onToggle,
}: {
  sections: readonly NavSection[]
  currentSectionId: string | undefined
  drawer: 'pinned' | 'overlay'
  openSectionId: string | null
  onToggle: (sectionId: string) => void
}) {
  return (
    <nav
      aria-label="Console"
      className="sticky top-0 flex h-dvh w-(--shell-rail) shrink-0 flex-col items-center gap-4 border-r border-nav-border bg-nav-rail py-3"
    >
      <Badge className="size-(--logo-rail)" label="Flow Life Commons" />
      <ul className="flex flex-col gap-1">
        {sections.map((section) => {
          const current = section.id === currentSectionId
          const destination = firstDestination(section)
          const asToggle = section.groups !== undefined && drawer === 'overlay'
          return (
            <li key={section.id}>
              {asToggle ? (
                <button
                  type="button"
                  id={railTriggerId(section.id)}
                  data-nav-trigger=""
                  aria-expanded={openSectionId === section.id}
                  aria-controls={DRAWER_ID}
                  aria-current={current ? 'true' : undefined}
                  onClick={() => {
                    onToggle(section.id)
                  }}
                  className={control}
                >
                  <Label section={section} current={current} />
                </button>
              ) : (
                <Link
                  to={destination ?? '/'}
                  id={railTriggerId(section.id)}
                  aria-current={current ? (section.to === undefined ? 'true' : 'page') : undefined}
                  className={control}
                >
                  <Label section={section} current={current} />
                </Link>
              )}
            </li>
          )
        })}
      </ul>
    </nav>
  )
}
