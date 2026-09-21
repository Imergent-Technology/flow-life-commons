// The Console's one HTTP path to the Platform API, so security-relevant handling is consistent.
//
// - Same origin only (ADR 0016). Paths are relative and typed as `/api/v1/...`; there is no base URL
//   to configure, no CORS, and `credentials` is never `include`. The browser attaches the HttpOnly
//   session cookie by itself: nothing here can read it, and there is no client-held auth token.
// - Request forgery: a browser marks a same-origin request `Sec-Fetch-Site: same-origin`, which the
//   server accepts on its own. For a browser that does not, the JS-readable `XSRF-TOKEN` cookie is
//   echoed in `X-XSRF-TOKEN`, as the OpenAPI contract describes. That is not a credential.
// - Every outcome the Console must tell apart becomes a `Failure`, so screens never inspect raw
//   statuses. Request bodies and secrets are never logged: there is no logging in here at all.

import { readXsrfToken } from './csrf.ts'

export type ApiPath = `/api/v1/${string}`

export type FieldErrors = Record<string, string[]>

export type Failure =
  /** 401: no session, or the one held has ended. */
  | { kind: 'unauthenticated' }
  /** 403: signed in, but not permitted. */
  | { kind: 'forbidden' }
  /**
   * 403 with `verification_required: true`: the account may do this, but its last password-and-second-factor
   * proof is too old. Prove again (`POST /security/verify`), then ask again; nothing is retried automatically.
   */
  | { kind: 'verification-required' }
  /** 404: what was asked about no longer exists. */
  | { kind: 'not-found' }
  /** 409: the request was understood and refused because of the state of things; `code` is the stable reason. */
  | { kind: 'conflict'; code: string }
  /** 419: the request-forgery check refused even after a fresh token was fetched. */
  | { kind: 'csrf' }
  /** 422: the request was refused and nothing changed; `errors` is keyed by request field. */
  | { kind: 'invalid'; message: string; errors: FieldErrors; code?: string }
  /** 429 */
  | { kind: 'rate-limited'; retryAfterSeconds: number | null }
  /** 5xx, including the 503 with which a dependency is reported as temporarily unavailable. */
  | { kind: 'unavailable'; retryAfterSeconds: number | null }
  /** The request never got an answer. */
  | { kind: 'network' }
  /** Anything the contract does not describe, including a malformed body. */
  | { kind: 'unexpected'; status: number }

export type Result<T> = { ok: true; value: T } | { ok: false; failure: Failure }

export interface RequestOptions {
  method: 'GET' | 'POST' | 'DELETE'
  path: ApiPath
  /** JSON body. Never logged, and never placed in a URL. */
  body?: object
  signal?: AbortSignal
  /**
   * True for a request made as a signed-in user. A 401 to one of those means the session the Console
   * believed in has ended, which the Console must act on (`onSessionRejected`), rather than a
   * wrong-credentials answer.
   */
  authenticated?: boolean
}

interface Reply {
  status: number
  body: unknown
  retryAfterSeconds: number | null
}

type SessionRejectedListener = () => void
const sessionRejectedListeners = new Set<SessionRejectedListener>()

/** Subscribes to "an authenticated request was answered 401". Returns the unsubscribe function. */
export function onSessionRejected(listener: SessionRejectedListener): () => void {
  sessionRejectedListeners.add(listener)
  return () => {
    sessionRejectedListeners.delete(listener)
  }
}

function assertSameOriginPath(path: string): void {
  // The type already says so; this keeps a cast or a future edit from ever sending credentials elsewhere.
  if (!path.startsWith('/api/v1/')) {
    throw new Error('API paths must be relative and under /api/v1/.')
  }
}

function parseRetryAfter(header: string | null): number | null {
  if (header === null || !/^\d{1,9}$/.test(header.trim())) return null
  return Number(header.trim())
}

async function attempt(options: RequestOptions): Promise<Reply | null> {
  assertSameOriginPath(options.path)

  const headers: Record<string, string> = { Accept: 'application/json' }
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'
  if (options.method !== 'GET') {
    const token = readXsrfToken()
    if (token !== null) headers['X-XSRF-TOKEN'] = token
  }

  const init: RequestInit = {
    method: options.method,
    headers,
    credentials: 'same-origin',
    cache: 'no-store',
  }
  if (options.body !== undefined) init.body = JSON.stringify(options.body)
  if (options.signal) init.signal = options.signal

  let response: Response
  try {
    response = await fetch(options.path, init)
  } catch {
    return null
  }

  return {
    status: response.status,
    body: await readBody(response),
    retryAfterSeconds: parseRetryAfter(response.headers.get('Retry-After')),
  }
}

/** The JSON body, `null` when there is none, and `undefined` when there is one that is not JSON. */
async function readBody(response: Response): Promise<unknown> {
  try {
    const text = await response.text()
    return text === '' ? null : (JSON.parse(text) as unknown)
  } catch {
    return undefined // only acceptable where the body is ignored (requestVoid)
  }
}

async function send(options: RequestOptions): Promise<Reply | null> {
  const reply = await attempt(options)
  if (reply?.status === 419 && options.method !== 'GET') {
    // A stale token (for example after the session lapsed). GET /me issues a fresh one; retry once.
    await attempt({
      method: 'GET',
      path: '/api/v1/me',
      ...(options.signal && { signal: options.signal }),
    })
    return attempt(options)
  }
  return reply
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function isFieldErrors(value: unknown): value is FieldErrors {
  return (
    typeof value === 'object' &&
    value !== null &&
    Object.values(value).every(
      (messages: unknown) =>
        Array.isArray(messages) && messages.every((m: unknown) => typeof m === 'string'),
    )
  )
}

function failureFor(reply: Reply | null): Failure {
  if (reply === null) return { kind: 'network' }

  switch (reply.status) {
    case 401:
      return { kind: 'unauthenticated' }
    case 403:
      return isRecord(reply.body) && reply.body.verification_required === true
        ? { kind: 'verification-required' }
        : { kind: 'forbidden' }
    case 404:
      return { kind: 'not-found' }
    case 409:
      return {
        kind: 'conflict',
        code: isRecord(reply.body) && typeof reply.body.code === 'string' ? reply.body.code : '',
      }
    case 419:
      return { kind: 'csrf' }
    case 422: {
      const b = reply.body
      if (typeof b === 'object' && b !== null) {
        const { message, errors, code } = b as {
          message?: unknown
          errors?: unknown
          code?: unknown
        }
        if (typeof message === 'string' && isFieldErrors(errors)) {
          return { kind: 'invalid', message, errors, ...(typeof code === 'string' && { code }) }
        }
      }
      return { kind: 'unexpected', status: reply.status }
    }
    case 429:
      return { kind: 'rate-limited', retryAfterSeconds: reply.retryAfterSeconds }
    default:
      if (reply.status >= 500)
        return { kind: 'unavailable', retryAfterSeconds: reply.retryAfterSeconds }
      return { kind: 'unexpected', status: reply.status }
  }
}

function settle(reply: Reply | null, options: RequestOptions): Failure | null {
  if (reply !== null && reply.status >= 200 && reply.status < 300) return null
  const failure = failureFor(reply)
  if (failure.kind === 'unauthenticated' && options.authenticated === true) {
    for (const listener of sessionRejectedListeners) listener()
  }
  return failure
}

/** A request whose success is the status alone: the body is deliberately ignored. */
export async function requestVoid(options: RequestOptions): Promise<Result<null>> {
  const reply = await send(options)
  const failure = settle(reply, options)
  return failure === null ? { ok: true, value: null } : { ok: false, failure }
}

/**
 * A request whose success may be one of several documented statuses, each with its own body (login answers
 * 200 when signed in and 202 when a second factor is due). Both are returned; the caller decides what each
 * means, and must check the body before trusting it.
 */
export async function requestReply(
  options: RequestOptions,
): Promise<Result<{ status: number; body: unknown }>> {
  const reply = await send(options)
  const failure = settle(reply, options)
  if (failure !== null) return { ok: false, failure }
  return { ok: true, value: { status: reply?.status ?? 0, body: reply?.body } }
}

/** A request whose success carries a body the Console relies on, checked before it is trusted. */
export async function requestJson<T>(
  options: RequestOptions,
  isValid: (body: unknown) => body is T,
): Promise<Result<T>> {
  const reply = await send(options)
  const failure = settle(reply, options)
  if (failure !== null) return { ok: false, failure }
  if (reply !== null && isValid(reply.body)) return { ok: true, value: reply.body }
  return { ok: false, failure: { kind: 'unexpected', status: reply?.status ?? 0 } }
}
