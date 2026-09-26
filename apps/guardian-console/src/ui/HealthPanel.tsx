import { useEffect, useState } from 'react'

import { fetchHealth, type HealthResponse } from '../api/health.ts'
import { Badge } from './Badge.tsx'
import { Panel } from './Panel.tsx'
import { Skeleton, SkeletonRegion } from './Skeleton.tsx'

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
    <Panel title="Platform API">
      <ApiHealth state={state} />
    </Panel>
  )
}

function ApiHealth({ state }: { state: HealthState }) {
  if (state.kind === 'loading') {
    return (
      <SkeletonRegion label="Checking…" visibleLabel>
        <Skeleton className="w-24" />
      </SkeletonRegion>
    )
  }
  if (state.kind === 'unreachable') {
    return (
      <div role="status">
        <Badge variant="danger">API unreachable</Badge>
      </div>
    )
  }

  const { health } = state
  return (
    <div role="status" className="flex flex-col gap-2">
      <div>
        <Badge variant={health.status === 'ok' ? 'success' : 'warning'}>API {health.status}</Badge>
      </div>
      <ul className="text-body text-muted-foreground">
        {Object.entries(health.checks).map(([name, result]) => (
          <li key={name}>
            {name}: {result}
          </li>
        ))}
      </ul>
    </div>
  )
}
