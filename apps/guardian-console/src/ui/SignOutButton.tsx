import { Alert } from './Alert.tsx'
import { Button } from './Button.tsx'
import { useSignOut } from './useSignOut.ts'

/** A sign-out button for the screens that have no account menu (an unavailable service, a missing capability). */
export function SignOutButton({ className }: { className?: string }) {
  const { pending, error, run } = useSignOut()

  return (
    <>
      <Button
        disabled={pending}
        onClick={() => {
          run()
        }}
        className={className}
      >
        {pending ? 'Signing out…' : 'Sign out'}
      </Button>
      {error ? (
        <Alert key={error.attempt} tone="error" focusOnMount>
          {error.message}
        </Alert>
      ) : null}
    </>
  )
}
