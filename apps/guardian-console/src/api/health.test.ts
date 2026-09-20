import { afterEach, describe, expect, it, vi } from 'vitest'

import { fetchHealth } from './health.ts'

const okBody = {
  status: 'ok',
  service: 'flowlife-platform',
  api_version: 'v1',
  checks: { database: 'ok' },
}

function stubFetch(body: unknown, status = 200) {
  const fetchMock = vi.fn(() => Promise.resolve(Response.json(body, { status })))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('fetchHealth', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns the health document and calls the versioned endpoint', async () => {
    const fetchMock = stubFetch(okBody)

    await expect(fetchHealth()).resolves.toEqual(okBody)
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/health',
      expect.objectContaining({ headers: { Accept: 'application/json' } }),
    )
  })

  it('treats 503 with a valid body as degraded rather than an error', async () => {
    stubFetch({ ...okBody, status: 'degraded', checks: { database: 'fail' } }, 503)

    await expect(fetchHealth()).resolves.toMatchObject({ status: 'degraded' })
  })

  it('rejects a malformed body', async () => {
    stubFetch({ hello: 'world' })

    await expect(fetchHealth()).rejects.toThrow(/Unexpected health response/)
  })

  it('propagates network failures', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))),
    )

    await expect(fetchHealth()).rejects.toThrow('Failed to fetch')
  })
})
