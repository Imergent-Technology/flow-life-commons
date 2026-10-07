import type { Failure } from '../../api/http.ts'
import { describeFailure, type Problem } from '../../ui/problem.ts'

// The Console's own wording for the Resource Library's refusals (ADR 0037). The library answers a missing, Draft, unpublished,
// not-for-this-viewer or empty-for-this-viewer Pack with ONE refusal, and the Console says exactly one thing for it: it must not
// say which, because that would tell a reader what exists.

export const NOT_FOUND_MESSAGE = 'That Resource could not be found.'

/** What to tell a Guardian about a failed library request. */
export function describeLibraryFailure(failure: Failure): Problem {
  if (failure.kind === 'not-found') return { message: NOT_FOUND_MESSAGE, fields: {} }
  return describeFailure(failure)
}
