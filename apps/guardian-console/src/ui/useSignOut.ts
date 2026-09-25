import { useCallback, useRef, useState } from 'react'

import { useAuth } from '../auth/auth-context.ts'
import { describeFailure } from './problem.ts'

/**
 * Ends the session. Success clears the Console's state and the router sends the person to the login page. If the
 * server could not be reached the session may still be alive, so the Console does NOT pretend to be signed out: it
 * reports `error`, which each attempt replaces (its `attempt` counter is a `key`, so a repeated failure is announced
 * again). A second activation while one is in flight is ignored.
 */
export function useSignOut() {
  const { signOut } = useAuth()
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<{ message: string; attempt: number } | null>(null)
  const inFlight = useRef(false)

  const run = useCallback(
    (onFailure?: () => void) => {
      if (inFlight.current) return
      inFlight.current = true
      setPending(true)
      void signOut().then((result) => {
        if (result.ok) return // the caller is about to unmount
        inFlight.current = false
        setPending(false)
        setError((previous) => ({
          message: `You could not be signed out. ${describeFailure(result.failure).message}`,
          attempt: (previous?.attempt ?? 0) + 1,
        }))
        onFailure?.()
      })
    },
    [signOut],
  )

  return { pending, error, run }
}
