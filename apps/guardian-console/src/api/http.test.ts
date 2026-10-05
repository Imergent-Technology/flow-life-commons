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

  it('echoes it on PATCH as on any other change (the People edits are PATCH requests)', async () => {
    setXsrfCookie('tok%3Den%2F1')
    api.on('PATCH /api/v1/admin/people/x', empty()).install()

    await requestVoid({
      method: 'PATCH',
      path: '/api/v1/admin/people/x',
      body: { affiliation: null },
    })

    const [call] = api.callsTo('PATCH /api/v1/admin/people/x')
    expect(call?.headers['X-XSRF-TOKEN']).toBe('tok=en/1')
    expect(call?.body).toEqual({ affiliation: null })
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
    [404, { message: 'x' }, {}, { kind: 'not-found' }],
    // Administration (ADR 0024): a stale proof is told apart from a plain refusal, and a conflict carries its stable code.
    [403, { message: 'x', verification_required: true }, {}, { kind: 'verification-required' }],
    [403, { message: 'x', verification_required: false }, {}, { kind: 'forbidden' }],
    // Discussions (ADR 0035): holding the capability is not owning the words, and the 403 says which one was missing.
    [403, { message: 'x', code: 'not_author' }, {}, { kind: 'forbidden', code: 'not_author' }],
    [403, { message: 'x', code: 7 }, {}, { kind: 'forbidden' }],
    [
      409,
      { message: 'x', code: 'last_administrator_required' },
      {},
      { kind: 'conflict', code: 'last_administrator_required' },
    ],
    [409, { message: 'x' }, {}, { kind: 'conflict', code: '' }],
    // People (ADR 0034): a possible-duplicate 409 carries its candidates; a body without any adds nothing to the failure.
    [
      409,
      { message: 'x', code: 'possible_duplicate', candidates: [{ id: 'a' }] },
      {},
      { kind: 'conflict', code: 'possible_duplicate', candidates: [{ id: 'a' }] },
    ],
    [
      409,
      { message: 'x', code: 'possible_duplicate', candidates: 'no' },
      {},
      { kind: 'conflict', code: 'possible_duplicate' },
    ],
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

describe('multipart uploads and the refusals Resources adds', () => {
  const isAny = (body: unknown): body is Record<string, unknown> =>
    typeof body === 'object' && body !== null

  it('sends a form as the body, adds no Content-Type of its own (the browser adds the boundary), and still sends the CSRF token', async () => {
    setXsrfCookie('token-123')
    api.on('POST /api/v1/upload', empty(204)).install()
    const form = new FormData()
    form.set('file', new File(['x'], 'a.pdf', { type: 'application/pdf' }))

    await requestVoid({ method: 'POST', path: '/api/v1/upload', form, authenticated: true })

    const [call] = api.calls
    expect(call?.init.body).toBe(form)
    expect(call?.form?.get('file')).toBeInstanceOf(File)
    expect(call?.body).toBeNull()
    expect(Object.keys(call?.headers ?? {}).map((h) => h.toLowerCase())).not.toContain(
      'content-type',
    )
    expect(call?.headers['X-XSRF-TOKEN']).toBe('token-123')
    expect(call?.init.credentials).toBe('same-origin')
  })

  it('sends a JSON body as JSON, as before', async () => {
    api.on('POST /api/v1/thing', empty(204)).install()
    await requestVoid({
      method: 'POST',
      path: '/api/v1/thing',
      body: { a: 1 },
      authenticated: true,
    })

    const [call] = api.calls
    expect(call?.headers['Content-Type']).toBe('application/json')
    expect(call?.body).toEqual({ a: 1 })
    expect(call?.form).toBeNull()
  })

  it('sends the same form again after a stale CSRF token is refreshed', async () => {
    let attempts = 0
    api
      .on('GET /api/v1/me', json({ message: 'ok' }))
      .on('POST /api/v1/upload', () =>
        attempts++ === 0 ? json({ message: 'CSRF token mismatch.' }, 419) : empty(204),
      )
      .install()
    const form = new FormData()
    form.set('file', new File(['x'], 'a.pdf'))

    const result = await requestVoid({
      method: 'POST',
      path: '/api/v1/upload',
      form,
      authenticated: true,
    })

    expect(result.ok).toBe(true)
    const uploads = api.callsTo('POST /api/v1/upload')
    expect(uploads).toHaveLength(2)
    expect(uploads[1]?.form).toBe(form)
  })

  it('reads any 413 as too-large, with or without a body the Console can read', async () => {
    api
      .on('POST /api/v1/json', json({ message: 'x', code: 'file_too_large', max_bytes: 1 }, 413))
      .on(
        'POST /api/v1/html',
        () => new Response('<html>Request Entity Too Large</html>', { status: 413 }),
      )
      .on('POST /api/v1/empty', () => new Response(null, { status: 413 }))
      .install()

    for (const path of ['/api/v1/json', '/api/v1/html', '/api/v1/empty'] as const) {
      const result = await requestJson({ method: 'POST', path, authenticated: true }, isAny)
      expect(result, path).toEqual({ ok: false, failure: { kind: 'too-large' } })
    }
  })

  it('carries the code of a 404, only when the server gave one', async () => {
    api
      .on('GET /api/v1/coded', json({ message: 'x', code: 'card_not_found' }, 404))
      .on('GET /api/v1/bare', json({ message: 'Not found.' }, 404))
      .on('GET /api/v1/odd', json({ message: 'x', code: 7 }, 404))
      .install()

    expect(await requestJson({ method: 'GET', path: '/api/v1/coded' }, isAny)).toEqual({
      ok: false,
      failure: { kind: 'not-found', code: 'card_not_found' },
    })
    expect(await requestJson({ method: 'GET', path: '/api/v1/bare' }, isAny)).toEqual({
      ok: false,
      failure: { kind: 'not-found' },
    })
    expect(await requestJson({ method: 'GET', path: '/api/v1/odd' }, isAny)).toEqual({
      ok: false,
      failure: { kind: 'not-found' },
    })
  })

  it('carries a 409’s explanatory fields, each only in its documented shape', async () => {
    api
      .on(
        'POST /api/v1/a',
        json({ message: 'x', code: 'pack_not_publishable', unmet: ['category', 'audience'] }, 409),
      )
      .on(
        'POST /api/v1/b',
        json({ message: 'x', code: 'published_pack_requirement', requirement: 'category' }, 409),
      )
      .on(
        'POST /api/v1/c',
        json({ message: 'x', code: 'card_audience_conflict', cards: ['c1'] }, 409),
      )
      .on(
        'POST /api/v1/d',
        json({ message: 'x', code: 'weird', unmet: 'nope', cards: [1], requirement: 9 }, 409),
      )
      .on('POST /api/v1/e', json({ message: 'x', code: 'duplicate_category' }, 409))
      .install()

    const failureOf = async (path: `/api/v1/${string}`) => {
      const result = await requestJson({ method: 'POST', path }, isAny)
      return result.ok ? null : result.failure
    }
    expect(await failureOf('/api/v1/a')).toEqual({
      kind: 'conflict',
      code: 'pack_not_publishable',
      detail: { unmet: ['category', 'audience'] },
    })
    expect(await failureOf('/api/v1/b')).toEqual({
      kind: 'conflict',
      code: 'published_pack_requirement',
      detail: { requirement: 'category' },
    })
    expect(await failureOf('/api/v1/c')).toEqual({
      kind: 'conflict',
      code: 'card_audience_conflict',
      detail: { cards: ['c1'] },
    })
    // Wrong shapes are dropped, not trusted; and a refusal with none carries no `detail` key at all.
    expect(await failureOf('/api/v1/d')).toEqual({ kind: 'conflict', code: 'weird' })
    expect(await failureOf('/api/v1/e')).toEqual({ kind: 'conflict', code: 'duplicate_category' })
  })
})
