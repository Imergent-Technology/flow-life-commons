import { useEffect, useState } from 'react'

import { fetchHealth, type HealthResponse } from '../api/health.ts'

type HealthState =
  { kind: 'loading' } | { kind: 'loaded'; health: HealthResponse } | { kind: 'unreachable' }

/** The platform health check (public, stateless: it neither needs nor refreshes a session). */
export function HealthPanel() {
  const [state, setState] = useState<HealthState>({ kind: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    fetchHealth(controller.signal)
      .then((health) => {
        setState({ kind: 'loaded', health })
      })
      .catch(() => {
        if (!controller.signal.aborted) setState({ kind: 'unreachable' })
      })
    return () => {
      controller.abort()
    }
  }, [])

  return (
    <section aria-labelledby="api-health" className="rounded-lg border border-slate-200 p-4">
      <h2 id="api-health" className="text-sm font-medium text-slate-500">
        Platform API
      </h2>
      <ApiHealth state={state} />
    </section>
  )
}

function ApiHealth({ state }: { state: HealthState }) {
  if (state.kind === 'loading') {
    return <p className="mt-2 text-slate-500">Checking…</p>
  }
  if (state.kind === 'unreachable') {
    return (
      <p role="status" className="mt-2 font-medium text-red-700">
        API unreachable
      </p>
    )
  }

  const { health } = state
  const ok = health.status === 'ok'
  return (
    <div role="status" className="mt-2">
      <p className={ok ? 'font-medium text-emerald-700' : 'font-medium text-amber-700'}>
        API {health.status}
      </p>
      <ul className="mt-1 text-sm text-slate-600">
        {Object.entries(health.checks).map(([name, result]) => (
          <li key={name}>
            {name}: {result}
          </li>
        ))}
      </ul>
    </div>
  )
}
