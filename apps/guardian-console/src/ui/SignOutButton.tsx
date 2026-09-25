import { Alert } from './Alert.tsx'
import { useSignOut } from './useSignOut.ts'

/** A sign-out button for the screens that have no account menu (an unavailable service, a missing capability). */
export function SignOutButton({ className = '' }: { className?: string }) {
  const { pending, error, run } = useSignOut()

  return (
    <>
      <button
        type="button"
        disabled={pending}
        onClick={() => {
          run()
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
