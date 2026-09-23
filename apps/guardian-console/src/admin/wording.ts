import { shown } from './time.ts'
import type { Failure } from '../api/http.ts'
import type { Member } from '../api/membership.ts'
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

// Membership records (ADR 0028). A 404 here is never "that account no longer exists" (describeFailure's generic wording):
// it addresses a Person or a grant, and the Console says so instead of borrowing Accounts' words.
const membershipConflicts: Record<string, string> = {
  grant_already_revoked:
    'This grant was already revoked, most likely from another tab or by someone else. The history below now reflects the current state.',
}

/** What to tell an operator about a failed Membership request. */
export function describeMembershipFailure(failure: Failure): Problem {
  if (failure.kind === 'not-found') {
    return { message: 'That membership record could not be found.', fields: {} }
  }
  if (failure.kind === 'conflict') {
    return {
      message:
        membershipConflicts[failure.code] ??
        'That could not be done in the current state. Reload and check.',
      fields: {},
    }
  }
  return describeFailure(failure)
}

const sourceLabels: Record<string, string> = { operator: 'Operator', luma_legacy: 'Luma legacy' }

/** A grant's `source`, in words. An unrecognised value (a future source this Console does not know yet) is shown as-is. */
export function membershipSourceLabel(source: string): string {
  return sourceLabels[source] ?? source
}

/**
 * What the current access-through says, as text: open-ended, a finite date, or nothing while inactive. ADR 0028 has no
 * "member since": this never shows one.
 */
export function accessThroughLabel(
  member: Pick<Member, 'active' | 'openEnded' | 'currentAccessEndsAt'>,
): string {
  if (!member.active) return '—'
  if (member.openEnded) return 'Open-ended'
  return `Access through ${shown(member.currentAccessEndsAt)}`
}
