import type { Failure } from '../api/http.ts'
import type { RelationshipType } from '../api/relationships.ts'
import { describeFailure, type Problem } from '../ui/problem.ts'
import { withArticle } from './definitions.ts'

// The Console's own words for the Relationships API's stable codes. It never shows the server's sentence for a refusal: the
// code says what happened, and this says what it means to an operator, using the type's own labels.

export function describeRelationshipFailure(failure: Failure, type?: RelationshipType): Problem {
  const singular = type?.labels.singular ?? 'relationship'
  const role = type?.defaultRole?.displayName ?? 'that'

  if (failure.kind === 'not-found') {
    return {
      message: `That ${singular} could not be found. It may have been deleted.`,
      fields: {},
    }
  }
  if (failure.kind === 'forbidden' && failure.code === 'role_provisioning_not_permitted') {
    return {
      message: `Granting ${role} access needs permission to assign roles, which you do not have. Nothing was changed.`,
      fields: {},
    }
  }
  if (failure.kind === 'conflict') {
    const message = conflictMessage(failure.code, singular, role)
    if (message !== undefined) return { message, fields: {} }
  }
  if (failure.kind === 'invalid' && failure.code !== undefined) {
    const message = invalidMessage(failure.code, singular, role)
    if (message !== undefined) return { message, fields: failure.errors }
  }
  return describeFailure(failure)
}

function conflictMessage(code: string, singular: string, role: string): string | undefined {
  switch (code) {
    case 'stale_revision':
      return `This ${singular} changed while you were working. Reload to see its current state; nothing was changed.`
    case 'stale_person_basics':
      return 'These details changed while you were working. Nothing was changed.'
    case 'relationship_exists':
      return `This person is already recorded as ${withArticle(singular)}.`
    case 'relationship_in_use':
      return `This ${singular} is still in use by other records, so it was not deleted.`
    case 'relationship_not_active':
      return `${role} access can only be granted while the ${singular} is active. Nothing was changed.`
    case 'possible_duplicate':
      return 'This person may already be known.'
    default:
      return undefined
  }
}

function invalidMessage(code: string, singular: string, role: string): string | undefined {
  switch (code) {
    case 'transition_not_allowed':
      return `That is not a change this ${singular} can make from its current status. Reload to see what is allowed.`
    case 'confirmation_required':
      return 'Confirm that you mean to record this existing person, then try again.'
    case 'default_role_decision_required':
      return `A decision about ${role} access is needed, and none was sent. Nothing was changed.`
    case 'default_role_not_applicable':
      return `${role} access does not apply to that status. Nothing was changed.`
    case 'unknown_person':
      return 'That person could not be found. Search again.'
    case 'unknown_relationship_status':
      return 'That status is not one this kind of relationship has.'
    case 'search_too_broad':
      return 'That search matches too many people to show. Add more to narrow it.'
    default:
      return undefined
  }
}
