import type { ConfirmResult } from '../ui/ConfirmDialog.tsx'
import type { ActionOutcome } from './useAdminAction.ts'
import { describeAdminFailure } from './wording.ts'

/**
 * What a confirmation dialog does with how the action came out. `done` runs `onDone` and closes; a recent-verification
 * prompt leaves the dialog open and asks the person to confirm AGAIN (the action is never repeated for them); anything
 * else is said in the dialog, in the Console's own words.
 */
export function toConfirmResult<T>(
  outcome: ActionOutcome<T>,
  onDone: (value: T) => void,
): ConfirmResult {
  if (outcome.status === 'done') {
    onDone(outcome.value)
    return { kind: 'done' }
  }
  if (outcome.status === 'verify') {
    return {
      kind: 'stay',
      tone: 'info',
      message: outcome.verified
        ? 'You are verified. Nothing has been changed yet: confirm again to continue.'
        : 'Verification was cancelled. Nothing has been changed.',
    }
  }
  return { kind: 'stay', tone: 'error', message: describeAdminFailure(outcome.failure).message }
}
