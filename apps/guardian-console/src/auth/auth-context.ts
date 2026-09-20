import { createContext, useContext } from 'react'

import type { CurrentAccount } from '../api/auth.ts'
import type { Result } from '../api/http.ts'

/**
 * What the Console knows about authentication, in memory only. The HttpOnly session cookie is the real
 * authority; this is a projection of the last `GET /me`, never persisted, never a capability snapshot
 * that outlives the page.
 */
export type AuthState =
  /** `GET /me` has not answered yet. */
  | { status: 'loading' }
  | { status: 'authenticated'; current: CurrentAccount }
  | {
      status: 'unauthenticated'
      /** Why, only where the Console KNOWS: it signed out, or it held a session the server ended. */
      notice: 'signed-out' | 'session-ended' | null
    }
  /** `GET /me` could not be answered (network, 5xx): who is signed in is unknown, not "nobody". */
  | { status: 'unavailable' }

export interface AuthContextValue {
  state: AuthState
  /** Signs in, then re-reads `GET /me` (the canonical projection) rather than trusting the login reply. */
  signIn: (email: string, password: string) => Promise<Result<CurrentAccount>>
  /** Ends the session on the server first; local state is cleared only once that is known to be true. */
  signOut: () => Promise<Result<null>>
  /** Re-reads `GET /me`. */
  refresh: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext)
  if (value === null) throw new Error('useAuth must be used inside <AuthProvider>.')
  return value
}

/** The signed-in Account. For screens that sit behind the authentication boundary. */
export function useCurrentAccount(): CurrentAccount {
  const { state } = useAuth()
  if (state.status !== 'authenticated') {
    throw new Error('useCurrentAccount is only valid behind the authentication boundary.')
  }
  return state.current
}
