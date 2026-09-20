import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'

import { fetchCurrentAccount, login, logout, type CurrentAccount } from '../api/auth.ts'
import { onSessionRejected, type Result } from '../api/http.ts'
import { AuthContext, type AuthContextValue, type AuthState } from './auth-context.ts'

async function resolve(signal?: AbortSignal): Promise<AuthState> {
  const result = await fetchCurrentAccount(signal)
  if (result.ok) return { status: 'authenticated', current: result.value }
  if (result.failure.kind === 'unauthenticated') return { status: 'unauthenticated', notice: null }
  return { status: 'unavailable' }
}

/**
 * Holds the authentication state and is the only thing that changes it. On start it asks `GET /me`.
 * It never polls: the documented 30 minutes are of request INACTIVITY (ADR 0016), so background
 * traffic would make that meaningless. A session that has ended is noticed by the next request the
 * user makes, which answers 401.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: 'loading' })

  // If we HELD a session and the server now says there is none, that is an ended session, not a
  // first visit: say so. Everything else is taken as reported.
  const adopt = useCallback((next: AuthState) => {
    setState((previous) =>
      next.status === 'unauthenticated' && previous.status === 'authenticated'
        ? { status: 'unauthenticated', notice: 'session-ended' }
        : next,
    )
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    void resolve(controller.signal).then((next) => {
      if (!controller.signal.aborted) adopt(next)
    })
    return () => {
      controller.abort()
    }
  }, [adopt])

  // Any authenticated request answered 401 means the session ended: drop everything held about it.
  useEffect(
    () =>
      onSessionRejected(() => {
        setState((previous) =>
          previous.status === 'authenticated'
            ? { status: 'unauthenticated', notice: 'session-ended' }
            : previous,
        )
      }),
    [],
  )

  const refresh = useCallback(async () => {
    adopt(await resolve())
  }, [adopt])

  const signIn = useCallback(
    async (email: string, password: string): Promise<Result<CurrentAccount>> => {
      const attempt = await login({ email, password })
      if (!attempt.ok) return attempt

      // The login reply is not the durable state: /me is.
      const next = await resolve()
      setState(next)
      if (next.status === 'authenticated') return { ok: true, value: next.current }
      return next.status === 'unavailable'
        ? { ok: false, failure: { kind: 'unavailable', retryAfterSeconds: null } }
        : { ok: false, failure: { kind: 'unexpected', status: 200 } }
    },
    [],
  )

  const signOut = useCallback(async (): Promise<Result<null>> => {
    const ended = await logout()
    if (ended.ok) {
      setState({ status: 'unauthenticated', notice: 'signed-out' })
      return ended
    }
    if (ended.failure.kind === 'csrf' || ended.failure.kind === 'unauthenticated') {
      // The session is already gone (or its token stale). Ask the server rather than assume.
      const next = await resolve()
      if (next.status === 'unauthenticated') {
        setState({ status: 'unauthenticated', notice: 'signed-out' })
        return { ok: true, value: null }
      }
      setState(next)
    }
    // Could not confirm the session ended (network, 5xx): stay as we are and say so. Showing
    // "signed out" while a session may still live would be worse than an error.
    return ended
  }, [])

  const value = useMemo<AuthContextValue>(
    () => ({ state, signIn, signOut, refresh }),
    [state, signIn, signOut, refresh],
  )

  return <AuthContext value={value}>{children}</AuthContext>
}
