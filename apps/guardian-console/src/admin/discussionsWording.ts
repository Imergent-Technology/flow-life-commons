import type { Failure } from '../api/http.ts'
import { describeFailure, type Problem } from '../ui/problem.ts'

// The Console's own wording for the Discussions API's stable codes. It never shows the server's sentence for a refusal: the
// code says what happened, and this says what it means to a Guardian.

const conflicts: Record<string, string> = {
  discussion_resolved:
    'This discussion was resolved, so the reply was not added. Reopen it to carry on.',
  message_removed: 'That message has been removed, so it can no longer be changed.',
}

/** What a 404 was asked about. A message is only ever asked about inside a discussion. */
export type DiscussionSubject = 'discussion' | 'message'

const notFound: Record<DiscussionSubject, string> = {
  discussion: 'That discussion could not be found.',
  message: 'That message could not be found.',
}

/** What to tell a Guardian about a failed Discussions request, and which fields it concerns. */
export function describeDiscussionsFailure(
  failure: Failure,
  subject: DiscussionSubject = 'discussion',
): Problem {
  if (failure.kind === 'not-found') return { message: notFound[subject], fields: {} }
  if (failure.kind === 'conflict') {
    return {
      message:
        conflicts[failure.code] ?? 'That could not be done in the current state. Reload and check.',
      fields: {},
    }
  }
  // Having the capability is not the same as owning the words: say which one was missing.
  if (failure.kind === 'forbidden' && failure.code === 'not_author') {
    return { message: 'Only the person who wrote that can change it.', fields: {} }
  }
  return describeFailure(failure)
}

/** A person's name as a discussion shows it. Identity no longer holding them is "Unknown person", never an id. */
export function personName(person: { displayName: string | null } | null): string {
  return person?.displayName ?? 'Unknown person'
}

const stateLabels: Record<string, string> = { open: 'Open', resolved: 'Resolved' }

/** A discussion's state, in words. An unknown state (a future one) is shown as-is. */
export function stateLabel(state: string): string {
  return stateLabels[state] ?? state
}

/** Whether a failure means the message itself changed or went (so the thread should be re-read), not that the text was wrong. */
export function isStale(failure: Failure): boolean {
  return (
    failure.kind === 'not-found' ||
    (failure.kind === 'conflict' && failure.code === 'message_removed')
  )
}
