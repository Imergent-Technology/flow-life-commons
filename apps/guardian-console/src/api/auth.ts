// The Identity endpoints the Console uses (openapi/openapi.yaml is the contract). Field names are the
// wire's snake_case; the Console's own types are camelCase. Credentials and tokens pass through here to
// the intended endpoint and nowhere else.

import { requestJson, requestReply, requestVoid, type Result } from './http.ts'

export interface CurrentAccount {
  account: { id: string; email: string }
  person: { id: string; display_name: string }
  /** Capability identifiers, for presenting the Console only. The server decides on every request. */
  capabilities: string[]
  session: { authenticated_at: string; absolute_expires_at: string }
  /** Presentation only: whether there is a second factor and how many recovery codes remain. Nothing about the factor itself. */
  mfa: {
    enrolled: boolean
    recovery_codes_remaining: number
    security_verified_until: string | null
  }
}

function isString(value: unknown): value is string {
  return typeof value === 'string'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function isCurrentAccount(value: unknown): value is CurrentAccount {
  if (!isRecord(value)) return false
  const { account, person, capabilities, session, mfa } = value
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
    isString(session.absolute_expires_at) &&
    isRecord(mfa) &&
    typeof mfa.enrolled === 'boolean' &&
    typeof mfa.recovery_codes_remaining === 'number' &&
    (mfa.security_verified_until === null || isString(mfa.security_verified_until))
  )
}

/** GET /me: the one canonical answer to "who is signed in, and what may they do right now". */
export function fetchCurrentAccount(signal?: AbortSignal): Promise<Result<CurrentAccount>> {
  return requestJson(
    { method: 'GET', path: '/api/v1/me', ...(signal && { signal }) },
    isCurrentAccount,
  )
}

/**
 * What a correct password led to. `signed-in` (200): a session exists, and the Console asks `/me` rather than
 * trust the reply. `second-factor` (202): NO session exists; a pending sign-in does, and `next` says whether the
 * account must present a code (`challenge`) or first enrol an authenticator (`enrollment`).
 */
export type LoginOutcome =
  | { kind: 'signed-in' }
  | { kind: 'second-factor'; next: 'challenge' | 'enrollment'; expiresAt: string }

function isPendingSignIn(
  body: unknown,
): body is { next: 'challenge' | 'enrollment'; expires_at: string } {
  return (
    isRecord(body) &&
    (body.next === 'challenge' || body.next === 'enrollment') &&
    isString(body.expires_at)
  )
}

/** POST /login. */
export async function login(input: {
  email: string
  password: string
}): Promise<Result<LoginOutcome>> {
  const result = await requestReply({ method: 'POST', path: '/api/v1/login', body: input })
  if (!result.ok) return result
  if (result.value.status === 202) {
    const body = result.value.body
    return isPendingSignIn(body)
      ? { ok: true, value: { kind: 'second-factor', next: body.next, expiresAt: body.expires_at } }
      : { ok: false, failure: { kind: 'unexpected', status: 202 } }
  }
  return { ok: true, value: { kind: 'signed-in' } }
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

/**
 * What a person presents as their second factor: an authenticator code, or one recovery code. Held only for
 * the request; never stored, logged or shown again.
 */
export type SecondFactorProof = { code: string } | { recoveryCode: string }

function proofBody(proof: SecondFactorProof): { code: string } | { recovery_code: string } {
  return 'code' in proof ? { code: proof.code } : { recovery_code: proof.recoveryCode }
}

/** POST /mfa/challenge: finishes a sign-in whose password was proved. A 401 means the pending sign-in ended. */
export function completeChallenge(proof: SecondFactorProof): Promise<Result<null>> {
  return requestVoid({ method: 'POST', path: '/api/v1/mfa/challenge', body: proofBody(proof) })
}

/**
 * A new authenticator secret, shown ONCE. `secret` is the manual key; `otpauthUri` contains it and is drawn as a
 * QR code IN THE BROWSER, never sent anywhere. Held in component memory only.
 */
export interface AuthenticatorSetup {
  secret: string
  otpauthUri: string
}

function isSetup(body: unknown): body is { secret: string; otpauth_uri: string } {
  return isRecord(body) && isString(body.secret) && isString(body.otpauth_uri)
}

async function setupOf(
  request: Promise<Result<{ secret: string; otpauth_uri: string }>>,
): Promise<Result<AuthenticatorSetup>> {
  const result = await request
  return result.ok
    ? { ok: true, value: { secret: result.value.secret, otpauthUri: result.value.otpauth_uri } }
    : result
}

/** POST /mfa/enrollment (a pending sign-in that must enrol). Nothing is enrolled by asking. */
export function beginEnrollment(): Promise<Result<AuthenticatorSetup>> {
  return setupOf(requestJson({ method: 'POST', path: '/api/v1/mfa/enrollment' }, isSetup))
}

function isRecoveryCodes(body: unknown): body is { recovery_codes: string[] } {
  return isRecord(body) && Array.isArray(body.recovery_codes) && body.recovery_codes.every(isString)
}

/** POST /mfa/enrollment/confirm: proves the secret, enrols it, signs in, and returns the recovery codes ONCE. */
export async function confirmEnrollment(input: { code: string }): Promise<Result<string[]>> {
  const result = await requestJson(
    { method: 'POST', path: '/api/v1/mfa/enrollment/confirm', body: input },
    isRecoveryCodes,
  )
  return result.ok ? { ok: true, value: result.value.recovery_codes } : result
}

/** Signed in. Fresh proof: the current password and a second factor, in the body. */
export type FreshProof = { currentPassword: string } & SecondFactorProof

function freshBody(proof: FreshProof): object {
  return { current_password: proof.currentPassword, ...proofBody(proof) }
}

/** POST /mfa/recovery-codes: replaces the recovery codes and returns the new ones ONCE. */
export async function regenerateRecoveryCodes(proof: FreshProof): Promise<Result<string[]>> {
  const result = await requestJson(
    {
      method: 'POST',
      path: '/api/v1/mfa/recovery-codes',
      body: freshBody(proof),
      authenticated: true,
    },
    isRecoveryCodes,
  )
  return result.ok ? { ok: true, value: result.value.recovery_codes } : result
}

/** POST /mfa/authenticator: starts replacing the authenticator; the one in use keeps working until the new one is proved. */
export function beginReplacement(proof: FreshProof): Promise<Result<AuthenticatorSetup>> {
  return setupOf(
    requestJson(
      {
        method: 'POST',
        path: '/api/v1/mfa/authenticator',
        body: freshBody(proof),
        authenticated: true,
      },
      isSetup,
    ),
  )
}

/** POST /mfa/authenticator/confirm: proves the NEW authenticator; only now does it replace the old one. */
export function confirmReplacement(input: { code: string }): Promise<Result<null>> {
  return requestVoid({
    method: 'POST',
    path: '/api/v1/mfa/authenticator/confirm',
    body: input,
    authenticated: true,
  })
}
