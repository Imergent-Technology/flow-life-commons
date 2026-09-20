// The Identity endpoints the Console uses (openapi/openapi.yaml is the contract). Field names are the
// wire's snake_case; the Console's own types are camelCase. Credentials and tokens pass through here to
// the intended endpoint and nowhere else.

import { requestJson, requestVoid, type Result } from './http.ts'

export interface CurrentAccount {
  account: { id: string; email: string }
  person: { id: string; display_name: string }
  /** Capability identifiers, for presenting the Console only. The server decides on every request. */
  capabilities: string[]
  session: { authenticated_at: string; absolute_expires_at: string }
}

function isString(value: unknown): value is string {
  return typeof value === 'string'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function isCurrentAccount(value: unknown): value is CurrentAccount {
  if (!isRecord(value)) return false
  const { account, person, capabilities, session } = value
  return (
    isRecord(account) &&
    isString(account.id) &&
    isString(account.email) &&
    isRecord(person) &&
    isString(person.id) &&
    isString(person.display_name) &&
    Array.isArray(capabilities) &&
    capabilities.every(isString) &&
    isRecord(session) &&
    isString(session.authenticated_at) &&
    isString(session.absolute_expires_at)
  )
}

/** GET /me: the one canonical answer to "who is signed in, and what may they do right now". */
export function fetchCurrentAccount(signal?: AbortSignal): Promise<Result<CurrentAccount>> {
  return requestJson(
    { method: 'GET', path: '/api/v1/me', ...(signal && { signal }) },
    isCurrentAccount,
  )
}

/** POST /login. Success is the status alone: the Console then asks GET /me rather than trust the body. */
export function login(input: { email: string; password: string }): Promise<Result<null>> {
  return requestVoid({ method: 'POST', path: '/api/v1/login', body: input })
}

/** POST /logout. Idempotent on the server. */
export function logout(): Promise<Result<null>> {
  return requestVoid({ method: 'POST', path: '/api/v1/logout' })
}

export function acceptInvitation(input: {
  token: string
  password: string
  passwordConfirmation: string
}): Promise<Result<null>> {
  return requestVoid({
    method: 'POST',
    path: '/api/v1/invitations/accept',
    body: {
      token: input.token,
      password: input.password,
      password_confirmation: input.passwordConfirmation,
    },
  })
}

export function requestPasswordReset(input: { email: string }): Promise<Result<null>> {
  return requestVoid({ method: 'POST', path: '/api/v1/password/forgot', body: input })
}

export function resetPassword(input: {
  email: string
  token: string
  password: string
  passwordConfirmation: string
}): Promise<Result<null>> {
  return requestVoid({
    method: 'POST',
    path: '/api/v1/password/reset',
    body: {
      email: input.email,
      token: input.token,
      password: input.password,
      password_confirmation: input.passwordConfirmation,
    },
  })
}

/** Signed in: a 401 here means the session ended (see `onSessionRejected`). */
export function changePassword(input: {
  currentPassword: string
  password: string
  passwordConfirmation: string
}): Promise<Result<null>> {
  return requestVoid({
    method: 'POST',
    path: '/api/v1/password/change',
    authenticated: true,
    body: {
      current_password: input.currentPassword,
      password: input.password,
      password_confirmation: input.passwordConfirmation,
    },
  })
}
