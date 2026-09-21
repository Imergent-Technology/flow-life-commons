import { useCallback } from 'react'

import { useStepUp } from '../auth/step-up-context.ts'
import type { Failure, Result } from '../api/http.ts'

/**
 * How an administrative action came out. `verify` means the server wanted a recent proof: the prompt was shown, and
 * `verified` says whether the person completed it. The action is NOT repeated: whoever called says "confirm again", so it
 * happens only on a deliberate second press.
 */
export type ActionOutcome<T> =
  | { status: 'done'; value: T }
  | { status: 'verify'; verified: boolean }
  | { status: 'failed'; failure: Failure }

export function useAdminAction(): <T>(call: () => Promise<Result<T>>) => Promise<ActionOutcome<T>> {
  const { request } = useStepUp()

  return useCallback(
    async <T>(call: () => Promise<Result<T>>): Promise<ActionOutcome<T>> => {
      const result = await call()
      if (result.ok) return { status: 'done', value: result.value }
      if (result.failure.kind === 'verification-required') {
        return { status: 'verify', verified: await request() }
      }
      return { status: 'failed', failure: result.failure }
    },
    [request],
  )
}
