import type { Failure, FieldErrors } from '../api/http.ts'

/** What to tell the user about a failed request, and which fields it concerns. */
export interface Problem {
  message: string
  fields: FieldErrors
}

function waitPhrase(seconds: number | null): string {
  if (seconds === null) return 'later'
  if (seconds <= 60) return 'in about a minute'
  return `in about ${String(Math.ceil(seconds / 60))} minutes`
}

/**
 * One plain-language description per kind of failure. Deliberately independent of the response body
 * for everything but a validation refusal (whose messages are written for people), so what the
 * server says about WHY an attempt failed can never leak through to the screen.
 */
export function describeFailure(failure: Failure): Problem {
  switch (failure.kind) {
    case 'invalid':
      return { message: failure.message, fields: failure.errors }
    case 'rate-limited':
      return {
        message: `Too many attempts. Try again ${waitPhrase(failure.retryAfterSeconds)}.`,
        fields: {},
      }
    case 'unavailable':
    case 'network':
      return {
        message: 'The service is temporarily unavailable. Try again in a moment.',
        fields: {},
      }
    case 'csrf':
      return {
        message: 'Your browser session could not be verified. Reload the page and try again.',
        fields: {},
      }
    case 'forbidden':
      return { message: 'You are not permitted to do that.', fields: {} }
    case 'unauthenticated':
      return { message: 'You are not signed in.', fields: {} }
    case 'unexpected':
      return { message: 'Something unexpected happened. Try again.', fields: {} }
  }
}
