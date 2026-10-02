import type { Failure } from '../api/http.ts'
import { describeFailure, type Problem } from '../ui/problem.ts'

// The Console's own wording for the People (CRM) API's stable codes. It never shows the server's sentence for a conflict:
// the code says what happened, and this says what it means to a Guardian.
const conflicts: Record<string, string> = {
  duplicate_contact_method: 'That person already has that contact method.',
}

/** What to tell a Guardian about a failed People request, and which fields it concerns. */
export function describePeopleFailure(failure: Failure): Problem {
  if (failure.kind === 'not-found') {
    // The People API's 404 addresses a Person or one of their contact methods, never an Account.
    return { message: 'That person or contact method could not be found.', fields: {} }
  }
  if (failure.kind === 'conflict') {
    return {
      message:
        conflicts[failure.code] ?? 'That could not be done in the current state. Reload and check.',
      fields: {},
    }
  }
  return describeFailure(failure)
}

const matchLabels: Record<string, string> = { email: 'same email', display_name: 'same name' }

/** What a duplicate candidate matched on, in words. An unknown reason (a future one) is shown as-is. */
export function matchedOnLabel(matchedOn: readonly string[]): string {
  return matchedOn.map((reason) => matchLabels[reason] ?? reason).join(', ')
}

const kindLabels: Record<string, string> = { email: 'Email', phone: 'Phone' }

/** A contact method's kind, in words. An unknown kind is shown as-is. */
export function contactKindLabel(kind: string): string {
  return kindLabels[kind] ?? kind
}
