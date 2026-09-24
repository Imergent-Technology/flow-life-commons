import { describe, expect, it } from 'vitest'

import { normalizeTotpCode, TOTP_DIGITS } from './totpCode.ts'

describe('normalizeTotpCode', () => {
  it('is six digits, as the platform fixes it', () => {
    expect(TOTP_DIGITS).toBe(6)
  })

  it.each([
    ['123456', '123456'],
    ['123 456', '123456'], // the way an authenticator app shows it
    [' 123456 ', '123456'],
    ['123-456', '123456'],
    ['123 456', '123456'], // a non-breaking space from a copied web page
    ['12a3b4c56', '123456'],
    ['1234567', '123456'], // capped, not refused
    ['123456789012', '123456'],
    ['12 34', '1234'], // a partial code is kept so it can be finished
    ['abc', ''],
    ['', ''],
  ])('%j becomes %j', (raw, expected) => {
    expect(normalizeTotpCode(raw)).toBe(expected)
  })
})
