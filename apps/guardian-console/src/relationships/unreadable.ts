import { withArticle } from './definitions.ts'
import type { RelationshipData } from './useRelationshipData.ts'
import { describeRelationshipFailure } from './wording.ts'

/** Why a relationship could not be shown at all. */
export function describeUnreadable(data: RelationshipData): string {
  if (data.status === 'not-found') {
    return `This person is not recorded as ${withArticle(data.type.labels.singular)}.`
  }
  return data.failure === null
    ? 'Something unexpected happened. Try again.'
    : describeRelationshipFailure(data.failure, data.type).message
}
