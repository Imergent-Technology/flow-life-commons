import type { RelationshipType } from '../api/relationships.ts'
import type { ConfirmResult } from '../ui/ConfirmDialog.tsx'
import type { MutationOutcome } from './useRelationshipData.ts'
import { describeRelationshipFailure } from './wording.ts'

/**
 * What a confirmation dialog does with how a relationship mutation came out. A success runs `onDone` and closes. A request
 * for recent verification leaves the dialog open and asks for a SECOND, deliberate press: the action is never repeated for
 * the operator. A stale refusal has already re-read the relationship and put its explanation on the page, so the dialog
 * simply closes: whatever it was about is no longer the question. Anything else is said in the dialog.
 */
export function confirmResultFor<T>(
  outcome: MutationOutcome<T>,
  {
    type,
    onDone,
    onStale,
  }: { type: RelationshipType; onDone: (value: T) => void; onStale: () => void },
): ConfirmResult {
  switch (outcome.status) {
    case 'done':
      onDone(outcome.value)
      return { kind: 'done' }
    case 'stale':
      onStale()
      return { kind: 'done' }
    case 'verify':
      return {
        kind: 'stay',
        tone: 'info',
        message: outcome.verified
          ? 'You are verified. Nothing has been changed yet: confirm again to continue.'
          : 'Verification was cancelled. Nothing has been changed.',
      }
    case 'failed':
      return {
        kind: 'stay',
        tone: 'error',
        message: describeRelationshipFailure(outcome.failure, type).message,
      }
  }
}
