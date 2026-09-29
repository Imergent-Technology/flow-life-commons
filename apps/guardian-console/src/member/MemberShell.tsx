import { Link, matchPath, Outlet, useLocation } from 'react-router'

import { useCurrentAccount } from '../auth/auth-context.ts'
import { AccountMenu } from '../ui/AccountMenu.tsx'
import { Alert } from '../ui/Alert.tsx'
import { Badge, Wordmark } from '../ui/Brand.tsx'
import { cn, focusRing } from '../ui/cn.ts'
import { useSignOut } from '../ui/useSignOut.ts'
import { memberNavigation } from './navigation.ts'

const MAIN_ID = 'main-content'
const gutter = 'px-[clamp(1rem,2.5vw,2rem)]'

/**
 * The Member self-service frame (ADR 0032, Work Package 4): authenticated self-service for ANY signed-in
 * Account, whether or not it is currently a Member — reaching this is not evidence of active membership
 * (docs/architecture/member-access.md). Deliberately simpler than `ConsoleShell`: three flat destinations
 * need no rail, no secondary drawer and no capability-filtered navigation, so this is one header and one
 * content column at every width, wrapping rather than collapsing into a different layout.
 *
 * Shares `Brand` and `AccountMenu` with the Console (the same product, the same primitives), but composes
 * them without ever naming the Guardian surface by name: `Wordmark` here takes no `subtitle`, and the account menu's
 * security link is this surface's own (`/my/security`, not `/account/security`).
 */
export function MemberShell() {
  const current = useCurrentAccount()
  const { pathname } = useLocation()
  const signOut = useSignOut()

  const skipLink = (
    <a
      href={`#${MAIN_ID}`}
      onClick={(event) => {
        event.preventDefault()
        document.getElementById(MAIN_ID)?.focus()
      }}
      className="sr-only rounded-sm bg-surface-raised px-3 py-2 text-label font-medium text-foreground shadow-pop focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50"
    >
      Skip to main content
    </a>
  )

  return (
    <div className="flex min-h-dvh flex-col">
      {skipLink}
      <header
        className={`flex min-h-(--shell-topbar) flex-wrap items-center gap-x-4 gap-y-2 py-2 ${gutter}`}
      >
        <div className="flex items-center gap-3">
          <Badge className="size-(--logo-mobile)" />
          <div className="hidden min-w-0 min-[420px]:block">
            <Wordmark />
          </div>
        </div>
        <nav aria-label="Member" className="flex gap-1">
          {memberNavigation.map((item) => {
            // The router's own matcher (`shell/navigation.ts` uses the same one), not a hand-rolled
            // `pathname === item.to`: its compiled pattern accepts an optional trailing slash, so `/my`
            // still reads current at `/my/` — unlike a bare string comparison, which a trailing slash
            // (however it got typed or landed on) would silently defeat.
            const isCurrent = matchPath({ path: item.to, end: true }, pathname) !== null
            return (
              <Link
                key={item.to}
                to={item.to}
                aria-current={isCurrent ? 'page' : undefined}
                className={cn(
                  'rounded-sm px-2.5 py-1.5 text-label font-medium text-muted-foreground hover:bg-muted hover:text-foreground',
                  isCurrent && 'bg-nav-active text-nav-active-foreground hover:bg-nav-active',
                  focusRing,
                )}
              >
                {item.label}
              </Link>
            )
          })}
        </nav>
        <div className="ml-auto">
          <AccountMenu
            current={current}
            compact={false}
            signOut={signOut}
            securityPath="/my/security"
          />
        </div>
      </header>
      <main
        id={MAIN_ID}
        tabIndex={-1}
        className={`min-w-0 flex-1 pt-2 pb-10 outline-none ${gutter}`}
      >
        {signOut.error ? (
          <div className="mb-4">
            <Alert key={signOut.error.attempt} tone="error" focusOnMount>
              {signOut.error.message}
            </Alert>
          </div>
        ) : null}
        <Outlet />
      </main>
    </div>
  )
}
