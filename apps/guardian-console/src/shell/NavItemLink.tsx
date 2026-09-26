import { Link } from 'react-router'

import { cn } from '../ui/cn.ts'
import type { NavItem } from './navigation.ts'

/**
 * One page in the drawer or sheet. The current item says so to assistive technology and shows it in more than
 * colour: `page` on its own page, `true` where the current page is one beneath it (see `currentMarker`).
 */
export function NavItemLink({
  item,
  current,
  onNavigate,
}: {
  item: NavItem
  current: 'page' | 'true' | undefined
  onNavigate: (to: string) => void
}) {
  return (
    <Link
      to={item.to}
      aria-current={current}
      onClick={(event) => {
        if (
          event.metaKey ||
          event.ctrlKey ||
          event.shiftKey ||
          event.altKey ||
          event.button !== 0
        ) {
          return
        }
        onNavigate(item.to)
      }}
      className={cn(
        'block rounded-sm border-l-2 px-2.5 py-1.5 text-body text-nav-foreground hover:bg-nav-active/60',
        current !== undefined
          ? 'border-nav-active-icon bg-nav-active font-semibold text-nav-active-foreground'
          : 'border-transparent',
      )}
    >
      {item.label}
    </Link>
  )
}
