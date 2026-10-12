import { createContext, useContext } from 'react'

import type { Failure } from '../api/http.ts'
import type { RelationshipType } from '../api/relationships.ts'

/**
 * The relationship types this operator may view or manage, as `GET /admin/relationship-types` serves them. Everything the
 * Console knows about a type (its labels, states, fields, features and what this operator may attempt) comes from here and
 * nowhere else: no screen holds a type's name, slug or rules. The `actions` are hints for what to PRESENT; the server decides
 * every request.
 */
export type RelationshipTypesState =
  | { status: 'loading' }
  | { status: 'loaded'; types: RelationshipType[] }
  | { status: 'failed'; failure: Failure }

export interface RelationshipTypesValue {
  state: RelationshipTypesState
  /** Asks again (after a failure). */
  reload: () => void
}

export const RelationshipTypesContext = createContext<RelationshipTypesValue | null>(null)

export function useRelationshipTypes(): RelationshipTypesValue {
  const value = useContext(RelationshipTypesContext)
  if (value === null) {
    throw new Error('useRelationshipTypes must be used inside <RelationshipTypesProvider>.')
  }
  return value
}

const NONE: RelationshipType[] = []

/** The types the operator may use, or none while they are not known. Navigation uses this: it never waits on it. */
export function useKnownRelationshipTypes(): RelationshipType[] {
  const { state } = useRelationshipTypes()
  return state.status === 'loaded' ? state.types : NONE
}
