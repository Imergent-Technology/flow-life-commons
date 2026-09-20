import { useState } from 'react'

import { Alert } from './Alert.tsx'
import { PageHeading } from './PageHeading.tsx'

/** The API could not be reached, so who is signed in is unknown. Not a sign-out: retry. */
export function ServiceUnavailable({ onRetry }: { onRetry: () => Promise<void> }) {
  const [retrying, setRetrying] = useState(false)

  return (
    <main className="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center gap-4 p-6">
      <PageHeading title="Service unavailable" />
      <Alert tone="error">
        The Console could not reach the platform, so it cannot tell whether you are signed in.
      </Alert>
      <button
        type="button"
        disabled={retrying}
        onClick={() => {
          setRetrying(true)
          void onRetry().finally(() => {
            setRetrying(false)
          })
        }}
        className="self-start rounded-md bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:bg-slate-500"
      >
        {retrying ? 'Trying again…' : 'Try again'}
      </button>
    </main>
  )
}
