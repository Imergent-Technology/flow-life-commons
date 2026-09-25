import { Link } from 'react-router'

import { cn } from '../ui/cn.ts'
import type { NavItem } from './navigation.ts'

/** One page in the drawer or sheet. The current page says so to assistive technology and shows it in more than colour. */
export function NavItemLink({
  item,
  current,
  onNavigate,
}: {
  item: NavItem
  current: boolean
  onNavigate: (to: string) => void
}) {
  return (
    <Link
      to={item.to}
      aria-current={current ? 'page' : undefined}
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
        current
          ? 'border-nav-active-icon bg-nav-active font-semibold text-nav-active-foreground'
          : 'border-transparent',
      )}
    >
      {item.label}
    </Link>
  )
}
