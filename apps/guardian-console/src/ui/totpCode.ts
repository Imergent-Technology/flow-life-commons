/** An authenticator code is six digits (RFC 6238, as the platform fixes it). */
export const TOTP_DIGITS = 6

/**
 * What a person typed or pasted into an authenticator-code field, reduced to what could be a code: digits only,
 * at most six. Authenticator apps show a code as "123 456" and people paste it that way, so spaces (and any
 * other separator) are dropped rather than refused. Deliberately NOT an `maxLength` on the input: a browser
 * truncates a paste to that length BEFORE this sees it, which would cut "123 456" down to "123 45".
 *
 * Guidance for the person only: the server still judges the code, and nothing here submits it.
 */
export function normalizeTotpCode(raw: string): string {
  return raw.replace(/\D/g, '').slice(0, TOTP_DIGITS)
}
