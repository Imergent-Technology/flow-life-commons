import { Navigate, Outlet, useLocation } from 'react-router'

import { ServiceUnavailable } from '../ui/ServiceUnavailable.tsx'
import { StatusScreen } from '../ui/StatusScreen.tsx'
import { useAuth } from './auth-context.ts'

/**
 * The authentication boundary. Everything under it needs a signed-in Account; nothing under it renders
 * (and so nothing under it requests data) until `GET /me` has answered.
 *
 * - Unauthenticated: sent to /login, remembering an INTERNAL path to come back to (never after an
 *   explicit sign-out, so the next person at a shared machine is not sent to the last page).
 * - Unknown (the API could not be reached): a retry, NOT the login page. It is not "signed out".
 */
export function RequireAuthentication() {
  const { state, refresh } = useAuth()
  const location = useLocation()

  switch (state.status) {
    case 'loading':
      return <StatusScreen>Checking your session…</StatusScreen>
    case 'unavailable':
      return <ServiceUnavailable onRetry={refresh} />
    case 'unauthenticated':
      return (
        <Navigate
          to="/login"
          replace
          state={
            state.notice === 'signed-out'
              ? null
              : { from: `${location.pathname}${location.search}` }
          }
        />
      )
    case 'second-factor':
      // The password was proved but the sign-in is not finished: not authenticated. The login page holds the next step.
      return (
        <Navigate to="/login" replace state={{ from: `${location.pathname}${location.search}` }} />
      )
    case 'authenticated':
      return <Outlet />
  }
}
