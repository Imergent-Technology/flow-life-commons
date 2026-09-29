import { useState } from 'react'

import { Alert } from './Alert.tsx'
import { AuthLayout } from './AuthLayout.tsx'
import { Button } from './Button.tsx'

/**
 * The API could not be reached, so who is signed in is unknown. Not a sign-out: retry. Shown before
 * `/me` has answered, on both the Console and the Member surface (`RequireAuthentication` is common to
 * both), so the wording names Flow Life Commons, never "the Console" specifically.
 */
export function ServiceUnavailable({ onRetry }: { onRetry: () => Promise<void> }) {
  const [retrying, setRetrying] = useState(false)

  return (
    <AuthLayout title="Service unavailable">
      <Alert tone="error">
        Flow Life Commons could not reach the platform, so it cannot tell whether you are signed in.
      </Alert>
      <Button
        variant="primary"
        disabled={retrying}
        onClick={() => {
          setRetrying(true)
          void onRetry().finally(() => {
            setRetrying(false)
          })
        }}
        className="self-start"
      >
        {retrying ? 'Trying again…' : 'Try again'}
      </Button>
    </AuthLayout>
  )
}
