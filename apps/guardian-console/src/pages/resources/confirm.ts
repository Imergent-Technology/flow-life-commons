import { toConfirmResult } from '../../admin/confirmResult.ts'
import type { ActionOutcome } from '../../admin/useAdminAction.ts'
import { describeResourcesFailure, type ResourceSubject } from '../../admin/resourcesWording.ts'
import type { ConfirmResult } from '../../ui/ConfirmDialog.tsx'

/**
 * What a confirmation dialog does with how a Resources action came out. Done and "verify first" are the Console's shared
 * behaviour (`toConfirmResult`: a recent-verification prompt leaves the dialog open and asks the person to confirm AGAIN, and the
 * action is never repeated for them). A refusal is worded in Resources' own terms, because the shared wording speaks of Accounts.
 */
export function toResourceConfirmResult<T>(
  outcome: ActionOutcome<T>,
  onDone: (value: T) => void,
  subject: ResourceSubject,
): ConfirmResult {
  if (outcome.status === 'failed') {
    return {
      kind: 'stay',
      tone: 'error',
      message: describeResourcesFailure(outcome.failure, subject).message,
    }
  }
  return toConfirmResult(outcome, onDone)
}
