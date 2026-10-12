import { useCallback, useMemo, useState } from 'react'
import { Outlet } from 'react-router'

import { listRelationshipTypes } from '../api/relationships.ts'
import { useCurrentAccount } from '../auth/auth-context.ts'
import { useLoad } from '../ui/useLoad.ts'
import {
  RelationshipTypesContext,
  type RelationshipTypesState,
  type RelationshipTypesValue,
} from './relationship-types-context.ts'

/**
 * Loads the served relationship definitions once for the signed-in Console, and again whenever the operator's capabilities
 * change (a role change takes effect on the next request, and the types the server describes follow it). It is a layout
 * route: the shell and every page beneath read the same answer. Types come in the definitions' own presentation order.
 */
export function RelationshipTypesProvider() {
  const current = useCurrentAccount()
  const [attempt, setAttempt] = useState(0)
  // The capability set is the only thing the answer depends on: a new `/me` with the same capabilities asks nothing more.
  const capabilityKey = current.capabilities.join(' ')

  const load = useCallback(
    (signal: AbortSignal) => listRelationshipTypes(signal),
    // The key and the attempt are not read: a new load function is how a change of capabilities, or "Try again", asks again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [capabilityKey, attempt],
  )
  const [loaded] = useLoad(load)

  const state = useMemo<RelationshipTypesState>(() => {
    if (loaded.status !== 'loaded') return loaded
    const types = [...loaded.value].sort(
      (a, b) => a.position - b.position || a.labels.plural.localeCompare(b.labels.plural),
    )
    return { status: 'loaded', types }
  }, [loaded])

  const reload = useCallback(() => {
    setAttempt((n) => n + 1)
  }, [])
  const value = useMemo<RelationshipTypesValue>(() => ({ state, reload }), [state, reload])

  return (
    <RelationshipTypesContext value={value}>
      <Outlet />
    </RelationshipTypesContext>
  )
}
