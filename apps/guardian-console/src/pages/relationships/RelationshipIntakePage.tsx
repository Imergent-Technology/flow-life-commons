import { useLocation } from 'react-router'

import type { PersonRef } from '../../api/relationships.ts'
import { RelationshipIntake } from '../../relationships/RelationshipIntake.tsx'
import { RelationshipRoute } from './RelationshipRoute.tsx'

/**
 * The person a link came with (the Person record's "Record as …"), if it brought one. The Console wrote it into the
 * navigation state itself; it is checked, used only to open the existing-person confirmation, and never trusted further.
 */
function arrivedWith(state: unknown): PersonRef | null {
  if (typeof state !== 'object' || state === null || !('person' in state)) return null
  const person = state.person
  if (typeof person !== 'object' || person === null) return null
  if (!('id' in person) || typeof person.id !== 'string') return null
  const name =
    'displayName' in person && typeof person.displayName === 'string' ? person.displayName : null
  return { id: person.id, displayName: name }
}

/** `/relationships/:slug/new`: recording a person, for an operator who may manage the type. */
export function RelationshipIntakePage() {
  const arrived = arrivedWith(useLocation().state)
  return (
    <RelationshipRoute needs="manage">
      {(type) => <RelationshipIntake key={type.slug} type={type} preselected={arrived} />}
    </RelationshipRoute>
  )
}
