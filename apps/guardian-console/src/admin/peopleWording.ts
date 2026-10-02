import type { Failure } from '../api/http.ts'
import { describeFailure, type Problem } from '../ui/problem.ts'

// The Console's own wording for the People (CRM) API's stable codes. It never shows the server's sentence for a conflict:
// the code says what happened, and this says what it means to a Guardian.
const conflicts: Record<string, string> = {
  duplicate_contact_method: 'That person already has that contact method.',
  duplicate_tag: 'A tag with that name already exists.',
  tag_in_use:
    'That tag is on at least one person, so it was not deleted. Remove it from them first.',
}

/** What a 404 was asked about. The People API's 404 addresses one of these, never an Account. */
export type PeopleSubject = 'person' | 'interaction' | 'tag'

const notFound: Record<PeopleSubject, string> = {
  person: 'That person or contact method could not be found.',
  interaction: 'That note could not be found. It may already have been removed.',
  tag: 'That tag could not be found. It may already have been deleted.',
}

/** What to tell a Guardian about a failed People request, and which fields it concerns. */
export function describePeopleFailure(
  failure: Failure,
  subject: PeopleSubject = 'person',
): Problem {
  if (failure.kind === 'not-found') return { message: notFound[subject], fields: {} }
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

const interactionKindLabels: Record<string, string> = {
  note: 'Note',
  call: 'Call',
  email: 'Email',
  meeting: 'Meeting',
}

/** An interaction's kind, in words. An unknown kind (a future one) is shown as-is. */
export function interactionKindLabel(kind: string): string {
  return interactionKindLabels[kind] ?? kind
}
