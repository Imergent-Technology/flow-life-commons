import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { empty, FakeApi, json } from '../test/fakeApi.ts'
import { fetchCurrentAccount, login, logout, changePassword } from './auth.ts'
import { onSessionRejected, requestJson, requestVoid, type ApiPath } from './http.ts'

let api: FakeApi

function setXsrfCookie(value: string | null) {
  document.cookie = `XSRF-TOKEN=${value ?? ''}; path=/${value === null ? '; max-age=0' : ''}`
}

beforeEach(() => {
  api = new FakeApi()
})

afterEach(() => {
  setXsrfCookie(null)
  vi.unstubAllGlobals()
})

describe('same-origin requests', () => {
  it('uses a relative path, same-origin credentials, and carries no auth token of its own', async () => {
    api.signedOut().install()
    await fetchCurrentAccount()

    const [call] = api.calls
    expect(call?.path).toBe('/api/v1/me')
    expect(call?.init.credentials).toBe('same-origin')
    expect(call?.init.cache).toBe('no-store')
    expect(Object.keys(call?.headers ?? {}).map((h) => h.toLowerCase())).not.toContain(
      'authorization',
    )
  })

  it('refuses a path outside /api/v1 rather than send credentials anywhere else', async () => {
    api.install()
    for (const path of ['https://evil.example/api/v1/me', '//evil.example/api/v1/me', '/other']) {
      await expect(requestVoid({ method: 'GET', path: path as ApiPath })).rejects.toThrow(
        /relative and under \/api\/v1/,
      )
    }
    expect(api.calls).toHaveLength(0)
  })

  it('puts credentials in the body, never in the URL', async () => {
    api.on('POST /api/v1/login', empty(200)).install()
    await login({ email: 'a@example.org', password: 'a secret passphrase' })

    const [call] = api.calls
    expect(call?.path).toBe('/api/v1/login')
    expect(call?.path).not.toContain('secret')
    expect(call?.body).toEqual({ email: 'a@example.org', password: 'a secret passphrase' })
  })
})

describe('request forgery token', () => {
  it('echoes the XSRF-TOKEN cookie (decoded) on a state-changing request, not on a read', async () => {
    setXsrfCookie('tok%3Den%2F1')
    api.signedOut().on('POST /api/v1/logout', empty()).install()

    await fetchCurrentAccount()
    await logout()

    expect(api.callsTo('GET /api/v1/me')[0]?.headers['X-XSRF-TOKEN']).toBeUndefined()
    expect(api.callsTo('POST /api/v1/logout')[0]?.headers['X-XSRF-TOKEN']).toBe('tok=en/1')
  })

  it('sends no token header when there is no cookie (the browser marks the request same-origin)', async () => {
    api.on('POST /api/v1/logout', empty()).install()
    await logout()

    expect(api.calls[0]?.headers['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('on 419 fetches a fresh token from /me and retries exactly once', async () => {
    let posts = 0
    api
      .signedOut()
      .on('POST /api/v1/logout', () =>
        ++posts === 1 ? json({ message: 'CSRF token mismatch.' }, 419) : empty(),
      )
      .install()

    const result = await logout()

    expect(result.ok).toBe(true)
    expect(api.calls.map((c) => `${c.method} ${c.path}`)).toEqual([
      'POST /api/v1/logout',
      'GET /api/v1/me',
      'POST /api/v1/logout',
    ])
  })

  it('reports a persistent 419 instead of looping', async () => {
    api
      .signedOut()
      .on('POST /api/v1/logout', json({ message: 'CSRF token mismatch.' }, 419))
      .install()

    await expect(logout()).resolves.toEqual({ ok: false, failure: { kind: 'csrf' } })
    expect(api.callsTo('POST /api/v1/logout')).toHaveLength(2)
  })
})

describe('failures', () => {
  const cases: [number, unknown, Record<string, string>, unknown][] = [
    [401, { message: 'x' }, {}, { kind: 'unauthenticated' }],
    [403, { message: 'x' }, {}, { kind: 'forbidden' }],
    [
      429,
      { message: 'x' },
      { 'Retry-After': '120' },
      { kind: 'rate-limited', retryAfterSeconds: 120 },
    ],
    [429, { message: 'x' }, {}, { kind: 'rate-limited', retryAfterSeconds: null }],
    [
      503,
      { message: 'x' },
      { 'Retry-After': '30' },
      { kind: 'unavailable', retryAfterSeconds: 30 },
    ],
    [500, 'oops', {}, { kind: 'unavailable', retryAfterSeconds: null }],
    [404, { message: 'x' }, {}, { kind: 'unexpected', status: 404 }],
  ]

  it.each(cases)('classifies HTTP %i', async (status, body, headers, expected) => {
    api.on('POST /api/v1/login', json(body, status, headers)).install()

    await expect(login({ email: 'a@example.org', password: 'x' })).resolves.toEqual({
      ok: false,
      failure: expected,
    })
  })

  it('ignores a Retry-After that is not a plain number of seconds', async () => {
    api
      .on(
        'POST /api/v1/login',
        json({ message: 'x' }, 429, { 'Retry-After': 'Wed, 21 Oct 2026 07:28:00 GMT' }),
      )
      .install()

    const result = await login({ email: 'a@example.org', password: 'x' })
    expect(result).toEqual({
      ok: false,
      failure: { kind: 'rate-limited', retryAfterSeconds: null },
    })
  })

  it('carries validation errors from a 422', async () => {
    api
      .on(
        'POST /api/v1/login',
        json({ message: 'Bad.', errors: { email: ['Enter a valid email address.'] } }, 422),
      )
      .install()

    await expect(login({ email: 'nope', password: 'x' })).resolves.toEqual({
      ok: false,
      failure: {
        kind: 'invalid',
        message: 'Bad.',
        errors: { email: ['Enter a valid email address.'] },
      },
    })
  })

  it('treats a 422 that does not match the contract as unexpected', async () => {
    api.on('POST /api/v1/login', json({ errors: 'no' }, 422)).install()

    await expect(login({ email: 'a', password: 'x' })).resolves.toEqual({
      ok: false,
      failure: { kind: 'unexpected', status: 422 },
    })
  })

  it('reports a request that never got an answer', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new TypeError('Failed to fetch')))

    await expect(fetchCurrentAccount()).resolves.toEqual({
      ok: false,
      failure: { kind: 'network' },
    })
  })

  it('does not trust a success that is not the documented shape', async () => {
    api.on('GET /api/v1/me', json({ account: { id: 'x' } })).install()

    await expect(fetchCurrentAccount()).resolves.toEqual({
      ok: false,
      failure: { kind: 'unexpected', status: 200 },
    })
  })

  it('accepts a body-less or non-JSON success where only the status matters', async () => {
    api.on('POST /api/v1/login', new Response('<html>', { status: 200 })).install()

    await expect(login({ email: 'a@example.org', password: 'x' })).resolves.toMatchObject({
      ok: true,
    })
  })

  it('validates the body of a JSON request with the supplied check', async () => {
    api.on('GET /api/v1/me', json({ n: 1 })).install()
    const isN = (b: unknown): b is { n: number } =>
      typeof b === 'object' && b !== null && typeof (b as { n?: unknown }).n === 'number'

    await expect(requestJson({ method: 'GET', path: '/api/v1/me' }, isN)).resolves.toEqual({
      ok: true,
      value: { n: 1 },
    })
  })
})

describe('an ended session', () => {
  it('is announced when a request made as a signed-in user is answered 401', async () => {
    api.on('POST /api/v1/password/change', json({ message: 'Unauthenticated.' }, 401)).install()
    const heard = vi.fn()
    const stop = onSessionRejected(heard)

    await changePassword({ currentPassword: 'a', password: 'b', passwordConfirmation: 'b' })
    expect(heard).toHaveBeenCalledTimes(1)

    stop()
    await changePassword({ currentPassword: 'a', password: 'b', passwordConfirmation: 'b' })
    expect(heard).toHaveBeenCalledTimes(1)
  })

  it('is NOT announced for a wrong-credentials 401 at login, or for the /me probe', async () => {
    api
      .on('POST /api/v1/login', json({ message: 'The provided credentials are incorrect.' }, 401))
      .signedOut()
      .install()
    const heard = vi.fn()
    const stop = onSessionRejected(heard)

    await login({ email: 'a@example.org', password: 'wrong' })
    await fetchCurrentAccount()

    expect(heard).not.toHaveBeenCalled()
    stop()
  })
})
