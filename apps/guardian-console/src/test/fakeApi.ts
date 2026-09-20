import { vi } from 'vitest'

import type { CurrentAccount } from '../api/auth.ts'

export interface RecordedCall {
  method: string
  path: string
  body: unknown
  headers: Record<string, string>
  init: RequestInit
}

type Handler = (call: RecordedCall) => Response | Promise<Response>
type Route = `${'GET' | 'POST'} /api/v1/${string}`

export function json(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return Response.json(body, { status, headers })
}

export const empty = (status = 204): Response => new Response(null, { status })

export function accountFor(overrides: Partial<CurrentAccount> = {}): CurrentAccount {
  return {
    account: { id: '01J0000000000000000000ACCT', email: 'guardian@example.org' },
    person: { id: '01J0000000000000000000PRSN', display_name: 'Gwen Guardian' },
    capabilities: ['console.access'],
    session: {
      authenticated_at: '2026-09-20T09:00:00Z',
      absolute_expires_at: '2026-09-20T21:00:00Z',
    },
    mfa: {
      enrolled: true,
      recovery_codes_remaining: 10,
      security_verified_until: '2026-09-20T09:15:00Z',
    },
    ...overrides,
  }
}

/**
 * The Platform API as the browser sees it: `fetch` is replaced at the network boundary, so the real
 * client, provider, router and pages all run. An unexpected request FAILS the test rather than
 * quietly returning something, and every call is recorded so a test can prove what was (and was not)
 * sent, and where.
 */
export class FakeApi {
  readonly calls: RecordedCall[] = []
  private readonly handlers = new Map<string, Handler>()

  on(route: Route, handler: Handler | Response): this {
    this.handlers.set(route, typeof handler === 'function' ? handler : () => handler.clone())
    return this
  }

  /** Whether the fake server currently holds a session (see `withSession`). */
  signedIn = false

  /**
   * A small server-side session: `/login` with these credentials starts it, `/me` reports it,
   * `/logout` ends it. Also answers the health probe the signed-in landing page makes.
   */
  withSession(account: CurrentAccount, credentials: { email: string; password: string }): this {
    this.on('GET /api/v1/me', () =>
      this.signedIn ? json(account) : json({ message: 'Unauthenticated.' }, 401),
    )
    this.on('POST /api/v1/login', (call) => {
      const body = call.body as { email?: string; password?: string } | null
      if (body?.email === credentials.email && body.password === credentials.password) {
        this.signedIn = true
        return json(account)
      }
      return json({ message: 'The provided credentials are incorrect.' }, 401)
    })
    this.on('POST /api/v1/logout', () => {
      this.signedIn = false
      return empty()
    })
    return this.on(
      'GET /api/v1/health',
      json({
        status: 'ok',
        service: 'flowlife-platform',
        api_version: 'v1',
        checks: { database: 'ok' },
      }),
    )
  }

  /** Signed in as `account` until told otherwise: GET /me answers with it. */
  signedInAs(account: CurrentAccount): this {
    return this.on('GET /api/v1/me', () => json(account))
  }

  signedOut(): this {
    return this.on('GET /api/v1/me', () => json({ message: 'Unauthenticated.' }, 401))
  }

  callsTo(route: Route): RecordedCall[] {
    const [method, path] = route.split(' ')
    return this.calls.filter((c) => c.method === method && c.path === path)
  }

  install(): void {
    vi.stubGlobal(
      'fetch',
      (input: string | URL | Request, init: RequestInit = {}): Promise<Response> => {
        const path =
          typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
        const method = (init.method ?? 'GET').toUpperCase()
        const rawBody = init.body
        const call: RecordedCall = {
          method,
          path,
          body: typeof rawBody === 'string' ? (JSON.parse(rawBody) as unknown) : null,
          headers: Object.fromEntries(
            Object.entries((init.headers ?? {}) as Record<string, string>),
          ),
          init,
        }
        this.calls.push(call)
        const handler = this.handlers.get(`${method} ${path}`)
        if (handler === undefined) {
          return Promise.reject(new Error(`Unexpected request in test: ${method} ${path}`))
        }
        return Promise.resolve(handler(call))
      },
    )
  }
}
