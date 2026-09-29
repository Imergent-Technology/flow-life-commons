import { useEffect, useState } from 'react'

import type { Failure, Result } from '../api/http.ts'

export type Load<T> =
  { status: 'loading' } | { status: 'loaded'; value: T } | { status: 'failed'; failure: Failure }

/**
 * Loads something when the page appears and again whenever `load` changes (so pass a `useCallback`). A request that the
 * page has moved on from is aborted, and its answer is never shown. `replace` puts a newer value in place, for when an
 * action returns the fresh version of what is on screen.
 */
export function useLoad<T>(
  load: (signal: AbortSignal) => Promise<Result<T>>,
): [Load<T>, (value: T) => void] {
  const [state, setState] = useState<{ source: typeof load; load: Load<T> }>({
    source: load,
    load: { status: 'loading' },
  })

  useEffect(() => {
    const controller = new AbortController()
    void load(controller.signal).then((result) => {
      if (controller.signal.aborted) return
      setState({
        source: load,
        load: result.ok
          ? { status: 'loaded', value: result.value }
          : { status: 'failed', failure: result.failure },
      })
    })
    return () => {
      controller.abort()
    }
  }, [load])

  // What is on screen is only ever the answer to the CURRENT request.
  const current: Load<T> = state.source === load ? state.load : { status: 'loading' }
  return [
    current,
    (value) => {
      setState({ source: load, load: { status: 'loaded', value } })
    },
  ]
}
