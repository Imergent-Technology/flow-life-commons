import { createContext, useContext } from 'react'

import type { CurrentAccount, LoginOutcome, SecondFactorProof } from '../api/auth.ts'
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
      /** Why, only where the Console KNOWS: it signed out, it held a session the server ended, or a pending sign-in ended. */
      notice: 'signed-out' | 'session-ended' | 'sign-in-expired' | null
    }
  /**
   * The password was proved and a second factor must follow. NOT authenticated: the server holds only a
   * short-lived pending sign-in, `/me` still answers 401, and nothing here grants access. In memory only:
   * reloading the page forgets it, and the person signs in again.
   */
  | { status: 'second-factor'; step: 'challenge' | 'enrollment'; expiresAt: string }
  /** `GET /me` could not be answered (network, 5xx): who is signed in is unknown, not "nobody". */
  | { status: 'unavailable' }

export interface AuthContextValue {
  state: AuthState
  /**
   * Signs in. A correct password either signs in (then `GET /me`, the canonical projection, is re-read
   * rather than trusting the login reply) or starts a second-factor step, and state changes to say which.
   */
  signIn: (email: string, password: string) => Promise<Result<LoginOutcome>>
  /** Finishes a pending sign-in with a code or recovery code, then re-reads `GET /me`. A 401 ends the pending sign-in. */
  completeSecondFactor: (proof: SecondFactorProof) => Promise<Result<null>>
  /** The pending sign-in cannot continue (expired, ended, or abandoned): back to the password. */
  endPendingSignIn: (reason: 'sign-in-expired' | null) => void
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
