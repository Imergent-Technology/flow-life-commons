// The one place the Console reads a cookie. `XSRF-TOKEN` is JS-readable by design (ADR 0016) so it can
// be echoed in `X-XSRF-TOKEN`; it is a request-forgery token, not a credential. The session cookie is
// HttpOnly and cannot be read here at all. Nothing in the Console ever writes a cookie.

const XSRF_COOKIE = 'XSRF-TOKEN'

export function readXsrfToken(): string | null {
  for (const part of document.cookie.split(';')) {
    const separator = part.indexOf('=')
    if (separator === -1) continue
    if (part.slice(0, separator).trim() !== XSRF_COOKIE) continue
    try {
      return decodeURIComponent(part.slice(separator + 1).trim())
    } catch {
      return null
    }
  }
  return null
}
