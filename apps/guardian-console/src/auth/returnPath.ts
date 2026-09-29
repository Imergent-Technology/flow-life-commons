// Where to send someone after they sign in. The value comes from router state that this application
// wrote, but it is validated as if it were hostile: only an internal Console path is ever followed, so
// it can never be an open redirect (`//evil.example`, `https://evil.example`, `/\evil.example`).

import type { CurrentAccount } from '../api/auth.ts'
import { CONSOLE_ACCESS, hasCapability } from './capabilities.ts'
import { defaultDestination, MEMBER_HOME } from './destination.ts'

const HOME = '/'

// Paths that must never be a return target: the gateway routes these to Laravel, not the Console; and
// the credential pages are where a secret-bearing link lands, not somewhere to be sent back to.
const EXCLUDED = [
  '/api',
  '/up',
  '/login',
  '/forgot-password',
  '/reset-password',
  '/accept-invitation',
]

export function safeReturnPath(candidate: unknown): string {
  if (typeof candidate !== 'string' || candidate.length > 2048) return HOME
  // One leading slash exactly, no backslash tricks, no control characters or whitespace.
  if (!candidate.startsWith('/') || candidate.startsWith('//') || candidate.includes('\\'))
    return HOME
  // eslint-disable-next-line no-control-regex -- deliberately rejecting control characters
  if (/[\u0000- \u007f]/.test(candidate)) return HOME

  let parsed: URL
  try {
    parsed = new URL(candidate, 'http://console.invalid')
  } catch {
    return HOME
  }
  if (parsed.origin !== 'http://console.invalid') return HOME

  const path = parsed.pathname
  if (EXCLUDED.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))) return HOME

  // Path and query only. A fragment is never carried.
  return `${path}${parsed.search}`
}

/**
 * The return path carried in router state (`{ from }`), validated, honored only when this Account can
 * actually land on it. Anything else — no explicit request, an unsafe one, or (for an Account without
 * `console.access`) a path outside its own `/my` surface — falls back to this Account's own default
 * surface (ADR 0032).
 *
 * `console.access` honors any safe internal path unrestricted, exactly as before this Account-aware
 * check existed: the Console's own route inventory keeps growing, and this module has no business
 * naming each new one. An Account WITHOUT it is trusted with `/my` and `/my/...` alone — an allow-list
 * of the one surface it actually has, rather than a deny-list of Guardian routes that would otherwise
 * need a new entry every time a Console-only route is added.
 */
export function returnPathFrom(state: unknown, current: CurrentAccount): string {
  const requested =
    typeof state === 'object' && state !== null && 'from' in state
      ? safeReturnPath(state.from)
      : null
  // No explicit request, or one that was rejected outright (safeReturnPath's own fallback is HOME):
  // either way, there is nothing specific to honor, so this Account's own default surface decides.
  if (requested === null || requested === HOME) return defaultDestination(current)

  if (hasCapability(current, CONSOLE_ACCESS)) return requested

  const isMemberPath = requested === MEMBER_HOME || requested.startsWith(`${MEMBER_HOME}/`)
  return isMemberPath ? requested : defaultDestination(current)
}
