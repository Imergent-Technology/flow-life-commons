import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { FakeApi, json } from '../test/fakeApi.ts'
import { beginEnrollment, beginReplacement } from './auth.ts'

const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'
const URI = `otpauth://totp/Flow%20Life:ada@example.org?secret=${SECRET}&issuer=Flow%20Life`
const proof = { currentPassword: 'a password', code: '123456' }

let api: FakeApi

beforeEach(() => {
  api = new FakeApi()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

// The setup the server hands over ONCE. `expires_at` is the server's clock, so the Console keeps it as it was
// given and refuses a reply without it: a setup it cannot say the lifetime of is one it should not show.
describe.each([
  ['replacing the authenticator', 'POST /api/v1/mfa/authenticator', () => beginReplacement(proof)],
  ['enrolling at sign-in', 'POST /api/v1/mfa/enrollment', () => beginEnrollment()],
] as const)('the setup from %s', (_name, route, begin) => {
  it('keeps the secret, the URI and the server’s expiry', async () => {
    api.on(route, json({ secret: SECRET, otpauth_uri: URI, expires_at: '2026-09-20T16:12:00Z' }))
    api.install()

    const result = await begin()

    expect(result).toEqual({
      ok: true,
      value: { secret: SECRET, otpauthUri: URI, expiresAt: '2026-09-20T16:12:00Z' },
    })
  })

  it('is not a setup without an expiry', async () => {
    api.on(route, json({ secret: SECRET, otpauth_uri: URI }))
    api.install()

    const result = await begin()

    expect(result.ok).toBe(false)
  })
})
