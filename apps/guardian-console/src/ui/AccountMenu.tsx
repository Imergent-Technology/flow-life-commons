import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { Link, useLocation } from 'react-router'

import type { CurrentAccount } from '../api/auth.ts'
import { cn, focusRing } from './cn.ts'
import { initials } from './initials.ts'
import type { ThemePreference } from './preferences.ts'
import { useTheme } from './theme-context.ts'
import type { useSignOut } from './useSignOut.ts'

const themes: readonly { value: ThemePreference; label: string }[] = [
  { value: 'system', label: 'System' },
  { value: 'light', label: 'Light' },
  { value: 'dark', label: 'Dark' },
]

/** Decorative: the button it sits in also carries the word "menu". */
function ChevronDownIcon() {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      className="size-4 shrink-0"
    >
      <path d="m6 9 6 6 6-6" />
    </svg>
  )
}

function menuItems(root: HTMLElement | null): HTMLElement[] {
  return Array.from(root?.querySelectorAll<HTMLElement>('[role^="menuitem"]') ?? [])
}

const itemClass =
  'flex w-full items-center rounded-sm px-2.5 py-2 text-left text-body text-foreground hover:bg-muted aria-disabled:cursor-not-allowed aria-disabled:opacity-60 ' +
  focusRing

/**
 * The account menu: who is signed in, Account security, the theme, and Sign out. Shared by every signed-in
 * surface (the Console and the Member self-service area alike): `securityPath` is the one thing
 * that differs between them, since each has its own security destination.
 *
 * A disclosure menu built on plain elements with a small roving-focus handler, not a menu framework and not
 * the `popover` attribute: it needs no positioning script, no anchor-positioning support and no second
 * dismissal path to keep in step with the rest of the shell, and it renders under every test environment.
 *
 * Keys: Enter, Space or ↓ open on the first item, ↑ on the last; ↑ ↓ Home End move; ← → move within the
 * Theme choices; Escape closes and returns focus to the button; Tab closes and carries on from the button.
 * A press outside closes without moving focus. Choosing a theme applies it at once and keeps the menu open.
 */
export function AccountMenu({
  current,
  compact,
  signOut,
  securityPath,
}: {
  current: CurrentAccount
  /** Below the rail breakpoint: the avatar alone. */
  compact: boolean
  signOut: ReturnType<typeof useSignOut>
  /** Where this surface's own account-security page lives (`/account/security`, or `/my/security`). */
  securityPath: string
}) {
  const { pathname } = useLocation()
  const { preference, setTheme } = useTheme()
  const [open, setOpen] = useState(false)
  const container = useRef<HTMLDivElement>(null)
  const trigger = useRef<HTMLButtonElement>(null)
  const startAt = useRef<'first' | 'last'>('first')
  const id = useId()
  const menuId = `${id}-menu`
  const themeLabelId = `${id}-theme`
  const name = current.person.display_name

  useEffect(() => {
    if (!open) return
    const all = menuItems(container.current)
    ;(startAt.current === 'last' ? all[all.length - 1] : all[0])?.focus()
  }, [open])

  useEffect(() => {
    if (!open) return
    const onPointerDown = (event: PointerEvent) => {
      if (event.target instanceof Node && container.current?.contains(event.target) === false) {
        setOpen(false)
      }
    }
    document.addEventListener('pointerdown', onPointerDown)
    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
    }
  }, [open])

  function move(to: (all: HTMLElement[], index: number) => number) {
    const all = menuItems(container.current)
    const index = all.indexOf(document.activeElement as HTMLElement)
    all[to(all, index)]?.focus()
  }

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    const fromTrigger = event.target === trigger.current
    if (event.key === 'Escape' && open) {
      event.preventDefault()
      setOpen(false)
      trigger.current?.focus()
      return
    }
    if (fromTrigger) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault()
        startAt.current = event.key === 'ArrowDown' ? 'first' : 'last'
        setOpen(true)
      }
      return
    }
    if (!open) return
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault()
        move((all, index) => (index + 1) % all.length)
        break
      case 'ArrowUp':
        event.preventDefault()
        move((all, index) => (index <= 0 ? all.length - 1 : index - 1))
        break
      case 'Home':
        event.preventDefault()
        move(() => 0)
        break
      case 'End':
        event.preventDefault()
        move((all) => all.length - 1)
        break
      case 'ArrowLeft':
      case 'ArrowRight': {
        const active = document.activeElement
        if (active?.getAttribute('role') !== 'menuitemradio') break
        event.preventDefault()
        const radios = menuItems(container.current).filter(
          (el) => el.getAttribute('role') === 'menuitemradio',
        )
        const at = radios.indexOf(active as HTMLElement)
        const step = event.key === 'ArrowRight' ? 1 : -1
        radios[(at + step + radios.length) % radios.length]?.focus()
        break
      }
      case ' ':
        if (event.target instanceof HTMLAnchorElement) {
          event.preventDefault()
          event.target.click()
        }
        break
      case 'Tab':
        // Carry on from the button, so Tab goes to whatever follows it (and Shift+Tab to what precedes it).
        trigger.current?.focus()
        setOpen(false)
        break
    }
  }

  return (
    <div ref={container} onKeyDown={onKeyDown} className="relative">
      <button
        ref={trigger}
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={menuId}
        // The visible name plus what the button is: whitespace in a hidden suffix is trimmed away by name computation.
        aria-label={compact ? 'Account menu' : `${name} account menu`}
        onClick={() => {
          startAt.current = 'first'
          setOpen((was) => !was)
        }}
        className={cn(
          'flex items-center gap-2 rounded-pill border border-border bg-surface/70 text-foreground hover:bg-surface',
          compact ? 'size-10 justify-center p-0' : 'h-9 py-1 pr-2.5 pl-1',
          focusRing,
        )}
      >
        <span
          aria-hidden="true"
          className="flex size-7 shrink-0 items-center justify-center rounded-pill bg-accent text-meta font-semibold text-accent-foreground"
        >
          {initials(name)}
        </span>
        {compact ? null : (
          <>
            <span className="max-w-44 truncate text-label font-medium">{name}</span>
            <ChevronDownIcon />
          </>
        )}
      </button>

      <div
        id={menuId}
        hidden={!open}
        className="absolute top-full right-0 z-40 mt-1.5 w-72 rounded-md border border-border bg-surface-raised shadow-pop"
      >
        {open ? (
          <>
            <div className="flex min-w-0 flex-col border-b border-border px-3.5 py-3">
              <span className="truncate text-body font-semibold text-foreground">{name}</span>
              <span className="truncate text-meta text-muted-foreground">
                {current.account.email}
              </span>
            </div>
            <div role="menu" aria-label="Account" className="flex flex-col gap-0.5 p-1.5">
              <Link
                role="menuitem"
                tabIndex={-1}
                to={securityPath}
                aria-current={pathname === securityPath ? 'page' : undefined}
                onClick={() => {
                  setOpen(false)
                  // Nothing else will move focus if this is the page already showing.
                  if (pathname === securityPath) trigger.current?.focus()
                }}
                className={itemClass}
              >
                Account security
              </Link>

              <div role="group" aria-labelledby={themeLabelId} className="px-2.5 py-2">
                <span id={themeLabelId} className="text-meta text-muted-foreground">
                  Theme
                </span>
                <div className="mt-1.5 flex gap-1 rounded-sm bg-muted p-1">
                  {themes.map((theme) => (
                    <button
                      key={theme.value}
                      type="button"
                      role="menuitemradio"
                      tabIndex={-1}
                      aria-checked={preference === theme.value}
                      onClick={() => {
                        setTheme(theme.value)
                      }}
                      className={cn(
                        'flex-1 rounded-sm px-2 py-1 text-label text-muted-foreground hover:text-foreground',
                        preference === theme.value &&
                          'bg-surface font-semibold text-foreground shadow-panel',
                        focusRing,
                      )}
                    >
                      {theme.label}
                    </button>
                  ))}
                </div>
              </div>

              <button
                type="button"
                role="menuitem"
                tabIndex={-1}
                aria-disabled={signOut.pending ? true : undefined}
                onClick={() => {
                  if (signOut.pending) return
                  signOut.run(() => {
                    setOpen(false)
                  })
                }}
                className={itemClass}
              >
                {signOut.pending ? 'Signing out…' : 'Sign out'}
              </button>
            </div>
          </>
        ) : null}
      </div>
    </div>
  )
}
