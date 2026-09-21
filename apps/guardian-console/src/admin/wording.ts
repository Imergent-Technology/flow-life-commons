import type { Failure } from '../api/http.ts'
import { describeFailure, type Problem } from '../ui/problem.ts'

// The Console's own wording for the reasons the server gives a stable `code` to. It never shows the server's sentence for
// these: the code says what happened, and this says what it means to an operator.
const conflicts: Record<string, string> = {
  last_administrator_required:
    'That would leave the platform without an active administrator, so it was not done. Make someone else an administrator first.',
  account_not_disabled:
    'That account is not disabled, so there is nothing to re-enable. Reload to see its current state.',
  email_already_in_use: 'An account already uses that email address.',
  invitation_not_issuable:
    'A new invitation can only be sent while the account is still invited. Reload to see its current state.',
  mfa_not_enrolled: 'That account has no two-step verification to reset.',
}

const refusals: Record<string, string> = {
  self_mfa_reset_prohibited:
    'You cannot reset your own two-step verification here. Ask another administrator.',
  unknown_role: 'That is not an access role the platform offers. Reload the page and choose again.',
}

/** What to tell an operator about a failed administrative request, and which fields it concerns. */
export function describeAdminFailure(failure: Failure): Problem {
  if (failure.kind === 'conflict') {
    return {
      message:
        conflicts[failure.code] ?? 'That could not be done in the current state. Reload and check.',
      fields: {},
    }
  }
  if (failure.kind === 'invalid' && failure.code !== undefined && failure.code in refusals) {
    return { message: refusals[failure.code] ?? failure.message, fields: {} }
  }
  return describeFailure(failure)
}
