import { Navigate, Outlet, useLocation } from 'react-router'

import { ForbiddenPage } from '../pages/ForbiddenPage.tsx'
import { useCurrentAccount } from './auth-context.ts'
import { CONSOLE_ACCESS, hasCapability } from './capabilities.ts'
import { MEMBER_HOME } from './destination.ts'

/**
 * Console entry needs `console.access`. Someone signed in WITHOUT it is authenticated but not
 * permitted. This decides what to PRESENT; the server refuses the Console's requests on its own
 * account, so nothing here grants access.
 *
 * The Guardian ROOT (`/`) is a landing spot, not a privileged destination: an Account without
 * `console.access` that reaches it is routed on to its own surface (ADR 0032) rather than told it
 * cannot enter — the same way a bookmark to a page that moved would forward, not refuse. Anything ELSE
 * behind this boundary (`/account/security`, `/admin/*`) is a genuinely privileged route and stays
 * refused: reaching the wrong LANDING surface is not the same as attempting a privileged one.
 */
export function RequireConsoleAccess() {
  const current = useCurrentAccount()
  const { pathname } = useLocation()
  if (hasCapability(current, CONSOLE_ACCESS)) return <Outlet />
  return pathname === '/' ? <Navigate to={MEMBER_HOME} replace /> : <ForbiddenPage />
}
