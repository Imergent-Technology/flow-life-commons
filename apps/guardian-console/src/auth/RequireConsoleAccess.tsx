import { Outlet } from 'react-router'

import { ForbiddenPage } from '../pages/ForbiddenPage.tsx'
import { useCurrentAccount } from './auth-context.ts'
import { CONSOLE_ACCESS, hasCapability } from './capabilities.ts'

/**
 * Console entry needs `console.access`. Someone signed in WITHOUT it is authenticated but not
 * permitted: they see an access-denied page (with sign-out), not the login page, so there is no loop.
 * This decides what to PRESENT; the server refuses the Console's requests on its own account.
 */
export function RequireConsoleAccess() {
  const current = useCurrentAccount()
  return hasCapability(current, CONSOLE_ACCESS) ? <Outlet /> : <ForbiddenPage />
}
