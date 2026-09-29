// Where to send someone after they sign in. The value comes from router state that this application
// wrote, but it is validated as if it were hostile: only an internal Console path is ever followed, so
// it can never be an open redirect (`//evil.example`, `https://evil.example`, `/\evil.example`).

import type { CurrentAccount } from '../api/auth.ts'
import { CONSOLE_ACCESS, hasCapability } from './capabilities.ts'
import { defaultDestination } from './destination.ts'

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

// Guardian-only prefixes (ADR 0032): honoring one of these as a return path for an Account that cannot
// enter the Console would only bounce it through Forbidden. Not a security boundary of its own —
// RequireConsoleAccess and RequireCapability refuse these regardless of what this module decides — only
// which DEFAULT this function chooses when the requested path is not one this Account can actually use.
const CONSOLE_ONLY = ['/account/security', '/admin']

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
 * actually land on it. Anything else — no explicit request, an unsafe one, or a Guardian-only path for
 * an Account without `console.access` — falls back to this Account's own default surface.
 */
export function returnPathFrom(state: unknown, current: CurrentAccount): string {
  const requested =
    typeof state === 'object' && state !== null && 'from' in state
      ? safeReturnPath(state.from)
      : null
  // No explicit request, or one that was rejected outright (safeReturnPath's own fallback is HOME):
  // either way, there is nothing specific to honor, so this Account's own default surface decides.
  if (requested === null || requested === HOME) return defaultDestination(current)

  const consoleOnly = CONSOLE_ONLY.some(
    (prefix) => requested === prefix || requested.startsWith(`${prefix}/`),
  )
  if (consoleOnly && !hasCapability(current, CONSOLE_ACCESS)) return defaultDestination(current)

  return requested
}
