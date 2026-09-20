import { useState } from 'react'

import { useAuth } from '../auth/auth-context.ts'
import { Alert } from './Alert.tsx'
import { describeFailure } from './problem.ts'

/**
 * Ends the session. Success clears the Console's state and the router sends the person to the login
 * page. If the server could not be reached the session may still be alive, so the Console does NOT pretend
 * to be signed out: it says so.
 */
export function SignOutButton({ className = '' }: { className?: string }) {
  const { signOut } = useAuth()
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<{ message: string; attempt: number } | null>(null)

  return (
    <>
      <button
        type="button"
        disabled={pending}
        onClick={() => {
          setPending(true)
          void signOut().then((result) => {
            if (result.ok) return // this component is about to unmount
            setPending(false)
            setError((previous) => ({
              message: `You could not be signed out. ${describeFailure(result.failure).message}`,
              attempt: (previous?.attempt ?? 0) + 1,
            }))
          })
        }}
        className={`rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:text-slate-500 ${className}`}
      >
        {pending ? 'Signing out…' : 'Sign out'}
      </button>
      {error ? (
        <Alert key={error.attempt} tone="error" focusOnMount>
          {error.message}
        </Alert>
      ) : null}
    </>
  )
}
