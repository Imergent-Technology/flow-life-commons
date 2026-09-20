// Where to send someone after they sign in. The value comes from router state that this application
// wrote, but it is validated as if it were hostile: only an internal Console path is ever followed, so
// it can never be an open redirect (`//evil.example`, `https://evil.example`, `/\evil.example`).

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

/** The return path carried in router state (`{ from }`), validated. Anything else means "go home". */
export function returnPathFrom(state: unknown): string {
  if (typeof state !== 'object' || state === null || !('from' in state)) return HOME
  return safeReturnPath(state.from)
}
