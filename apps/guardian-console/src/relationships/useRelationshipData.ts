import { useCallback, useState } from 'react'

import type { Failure, Result } from '../api/http.ts'
import {
  getRelationship,
  getRelationshipManagement,
  staleRevisionCurrent,
  type PersonRef,
  type RelationshipManagement,
  type RelationshipRecord,
  type RelationshipType,
} from '../api/relationships.ts'
import { useAdminAction } from '../admin/useAdminAction.ts'
import { useLoad } from '../ui/useLoad.ts'

/**
 * What a refused mutation left the page with. The page has already re-read the relationship and is showing its current
 * state; this says why the operator's change was not made. A mutation is never sent again on their behalf.
 */
export type Conflict =
  | {
      kind: 'stale'
      /** The instance the operator was working on is gone and another with the same Person and type took its place. */
      recreated: boolean
      /** Who set the status last, and when, if the operator's authority lets them see it (the view read has it). */
      lastStatusBy: PersonRef | null
      lastStatusAt: string | null
      /** For a re-created relationship: who recorded it again and when. */
      recordedBy: PersonRef | null
      recordedAt: string | null
    }
  | { kind: 'gone' }

/** How a mutation came out. `stale` means the conflict flow ran: the data is already current and `conflict` says so. */
export type MutationOutcome<T> =
  | { status: 'done'; value: T }
  | { status: 'verify'; verified: boolean }
  | { status: 'stale' }
  | { status: 'failed'; failure: Failure }

export interface ShownIdentity {
  relationshipId: string
  revision: number
}

export interface RelationshipData {
  type: RelationshipType
  personId: string
  /** `loading` until every read the operator is entitled to has answered. */
  status: 'loading' | 'ready' | 'not-found' | 'failed'
  failure: Failure | null
  /** The view read (history, provenance), only for an operator with the view capability. */
  view: RelationshipRecord | null
  /** The management read (revision, transitions), only for an operator with the manage capability. */
  management: RelationshipManagement | null
  /** The two reads do not describe the same instance and revision: no mutation is offered until they are read again. */
  mismatch: boolean
  conflict: Conflict | null
  dismissConflict: () => void
  /** Reads everything again. */
  reload: () => Promise<void>
  /**
   * Runs a mutation of the relationship the operator is looking at, naming the instance and revision they were SHOWN. A
   * recent-verification refusal opens the shared prompt and does not repeat the mutation. A stale refusal re-reads and
   * explains; it does not repeat it either. A success replaces what is shown with the response.
   */
  mutate: (
    call: (shown: ShownIdentity) => Promise<Result<RelationshipManagement>>,
  ) => Promise<MutationOutcome<RelationshipManagement>>
  /** The same for deletion, whose success has no body. */
  remove: (call: (shown: ShownIdentity) => Promise<Result<null>>) => Promise<MutationOutcome<null>>
}

const NOT_ASKED = Promise.resolve({ ok: true, value: null } as const)

/**
 * The reads and the mutation plumbing of one relationship (ADR 0038, C4). An operator can hold the view capability, the
 * manage capability, or both, and neither implies the other: each read is made only when its capability is held, a record
 * is never assembled from a read the operator may not make, and mutations are built only from the management read, which
 * is the one that carries the revision.
 */
export function useRelationshipData(type: RelationshipType, personId: string): RelationshipData {
  const run = useAdminAction()
  const { slug } = type
  const mayView = type.actions.view
  const mayManage = type.actions.manage

  const loadView = useCallback(
    (signal: AbortSignal): Promise<Result<RelationshipRecord | null>> =>
      mayView ? getRelationship(slug, personId, signal) : NOT_ASKED,
    [slug, personId, mayView],
  )
  const loadManagement = useCallback(
    (signal: AbortSignal): Promise<Result<RelationshipManagement | null>> =>
      mayManage ? getRelationshipManagement(slug, personId, signal) : NOT_ASKED,
    [slug, personId, mayManage],
  )
  const [viewLoad, replaceView] = useLoad(loadView)
  const [managementLoad, replaceManagement] = useLoad(loadManagement)
  const [conflict, setConflict] = useState<Conflict | null>(null)
  // Another tab (or person) deleted it: what is on screen is no longer anything.
  const [gone, setGone] = useState(false)

  const view = viewLoad.status === 'loaded' ? viewLoad.value : null
  const management = managementLoad.status === 'loaded' ? managementLoad.value : null

  const refreshed = useCallback(
    async (
      previousId: string | null,
      carried: RelationshipManagement | null = null,
    ): Promise<boolean> => {
      const [freshManagement, freshView] = await Promise.all([
        mayManage ? getRelationshipManagement(slug, personId) : NOT_ASKED,
        mayView ? getRelationship(slug, personId) : NOT_ASKED,
      ])
      const missing = [freshManagement, freshView].some(
        (result) => !result.ok && result.failure.kind === 'not-found',
      )
      if (missing) {
        setGone(true)
        setConflict({ kind: 'gone' })
        return false
      }
      if (freshManagement.ok) replaceManagement(freshManagement.value)
      if (freshView.ok) replaceView(freshView.value)
      const currentId =
        (freshManagement.ok ? freshManagement.value?.relationshipId : undefined) ??
        (freshView.ok ? freshView.value?.relationshipId : undefined) ??
        carried?.relationshipId ??
        null
      if (previousId !== null) {
        const recreated = currentId !== null && currentId !== previousId
        const record = freshView.ok ? freshView.value : null
        setConflict({
          kind: 'stale',
          recreated,
          lastStatusBy: record?.status.by ?? null,
          lastStatusAt: record?.status.since ?? null,
          recordedBy: recreated ? (record?.createdBy ?? null) : null,
          recordedAt: recreated ? (record?.createdAt ?? null) : null,
        })
      }
      return true
    },
    [mayManage, mayView, slug, personId, replaceManagement, replaceView],
  )

  const reload = useCallback(async () => {
    setGone(false)
    setConflict(null)
    await refreshed(null)
  }, [refreshed])

  const execute = useCallback(
    async <T>(
      call: (shown: ShownIdentity) => Promise<Result<T>>,
      apply: (value: T) => Promise<void>,
    ): Promise<MutationOutcome<T>> => {
      if (management === null)
        return { status: 'failed', failure: { kind: 'unexpected', status: 0 } }
      const shown = { relationshipId: management.relationshipId, revision: management.revision }
      const outcome = await run(() => call(shown))
      if (outcome.status === 'done') {
        setConflict(null)
        await apply(outcome.value)
        return { status: 'done', value: outcome.value }
      }
      if (outcome.status === 'verify') return outcome
      const stale = outcome.failure.kind === 'conflict' && outcome.failure.code === 'stale_revision'
      if (stale) {
        // The refusal carries the current state, but the page re-reads rather than trust it, so that what it shows is exactly
        // what this operator's own capabilities let them read. The carried instance is only a fallback for the comparison.
        await refreshed(shown.relationshipId, staleRevisionCurrent(outcome.failure))
        return { status: 'stale' }
      }
      return outcome
    },
    [management, run, refreshed],
  )

  const mutate = useCallback<RelationshipData['mutate']>(
    (call) =>
      execute(call, async (next) => {
        // The view read is fetched first and both are replaced together, so the page never shows two revisions at once.
        const fresh = mayView ? await getRelationship(slug, personId) : null
        if (fresh?.ok) replaceView(fresh.value)
        replaceManagement(next)
      }),
    [execute, mayView, slug, personId, replaceView, replaceManagement],
  )

  const remove = useCallback<RelationshipData['remove']>(
    (call) =>
      execute(call, () => {
        setGone(true)
        return Promise.resolve()
      }),
    [execute],
  )

  const failed = [viewLoad, managementLoad].find((load) => load.status === 'failed')
  const loading = viewLoad.status === 'loading' || managementLoad.status === 'loading'

  let status: RelationshipData['status'] = 'ready'
  let failure: Failure | null = null
  if (gone) {
    status = 'not-found'
  } else if (failed?.status === 'failed') {
    failure = failed.failure
    status = failure.kind === 'not-found' ? 'not-found' : 'failed'
  } else if (loading) {
    status = 'loading'
  }

  const mismatch =
    view !== null &&
    management !== null &&
    (view.relationshipId !== management.relationshipId || view.revision !== management.revision)

  return {
    type,
    personId,
    status,
    failure,
    view,
    management,
    mismatch,
    conflict,
    dismissConflict: () => {
      setConflict(null)
    },
    reload,
    mutate,
    remove,
  }
}
