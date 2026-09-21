// The operator-administration endpoints the Console uses (openapi/openapi.yaml is the contract). The wire is snake_case; the
// Console's own types are camelCase. What an Account HOLDS arrives as `assignments`, with the catalog's words: data to
// display. The Console holds no list of system roles and decides nothing from one.

import { requestJson, type FieldErrors, type Result } from './http.ts'

export type AccountStatus = 'invited' | 'active' | 'disabled'

/** What an Account holds, as the server describes it. Display only. */
export interface Assignment {
  key: string
  name: string
  description: string
  grantedAt: string
}

export interface ManagedAccount {
  id: string
  personId: string
  displayName: string
  email: string
  emailVerifiedAt: string | null
  status: AccountStatus
  createdAt: string
  lastLoginAt: string | null
  disabledAt: string | null
  mfa: { enrolled: boolean; recoveryCodesRemaining: number }
  invitation: { expiresAt: string; expired: boolean; delivery: 'email' | 'operator' } | null
  assignments: Assignment[]
}

/** A system role as the server offers it for assignment. The Console renders it and defines none of its own. */
export interface RoleDescriptor {
  key: string
  name: string
  description: string
  capabilities: string[]
}

export interface AccountPage {
  accounts: ManagedAccount[]
  page: number
  perPage: number
  total: number
  lastPage: number
}

export type Delivery = 'sent' | 'failed'

interface WireAssignment {
  key: string
  name: string
  description: string
  granted_at: string
}

interface WireAccount {
  id: string
  person_id: string
  display_name: string
  email: string
  email_verified_at: string | null
  status: AccountStatus
  created_at: string
  last_login_at: string | null
  disabled_at: string | null
  mfa: { enrolled: boolean; recovery_codes_remaining: number }
  invitation: { expires_at: string; expired: boolean; delivery: 'email' | 'operator' } | null
  assignments: WireAssignment[]
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isNullableString = (value: unknown): value is string | null =>
  value === null || isString(value)
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null

function isWireAssignment(value: unknown): value is WireAssignment {
  return (
    isRecord(value) &&
    isString(value.key) &&
    isString(value.name) &&
    isString(value.description) &&
    isString(value.granted_at)
  )
}

function isWireAccount(value: unknown): value is WireAccount {
  if (!isRecord(value)) return false
  const { mfa, invitation, assignments, status } = value
  return (
    isString(value.id) &&
    isString(value.person_id) &&
    isString(value.display_name) &&
    isString(value.email) &&
    isNullableString(value.email_verified_at) &&
    (status === 'invited' || status === 'active' || status === 'disabled') &&
    isString(value.created_at) &&
    isNullableString(value.last_login_at) &&
    isNullableString(value.disabled_at) &&
    isRecord(mfa) &&
    typeof mfa.enrolled === 'boolean' &&
    typeof mfa.recovery_codes_remaining === 'number' &&
    (invitation === null ||
      (isRecord(invitation) &&
        isString(invitation.expires_at) &&
        typeof invitation.expired === 'boolean' &&
        (invitation.delivery === 'email' || invitation.delivery === 'operator'))) &&
    Array.isArray(assignments) &&
    assignments.every(isWireAssignment)
  )
}

function accountFrom(wire: WireAccount): ManagedAccount {
  return {
    id: wire.id,
    personId: wire.person_id,
    displayName: wire.display_name,
    email: wire.email,
    emailVerifiedAt: wire.email_verified_at,
    status: wire.status,
    createdAt: wire.created_at,
    lastLoginAt: wire.last_login_at,
    disabledAt: wire.disabled_at,
    mfa: { enrolled: wire.mfa.enrolled, recoveryCodesRemaining: wire.mfa.recovery_codes_remaining },
    invitation:
      wire.invitation === null
        ? null
        : {
            expiresAt: wire.invitation.expires_at,
            expired: wire.invitation.expired,
            delivery: wire.invitation.delivery,
          },
    assignments: wire.assignments.map((a) => ({
      key: a.key,
      name: a.name,
      description: a.description,
      grantedAt: a.granted_at,
    })),
  }
}

async function account(request: Promise<Result<WireAccount>>): Promise<Result<ManagedAccount>> {
  const result = await request
  return result.ok ? { ok: true, value: accountFrom(result.value) } : result
}

const idPath = (id: string) => encodeURIComponent(id)

/** GET /admin/accounts: a bounded page, optionally narrowed by a plain fragment and a status. */
export async function listAccounts(input: {
  page: number
  perPage?: number
  query?: string
  status?: AccountStatus | ''
  signal?: AbortSignal
}): Promise<Result<AccountPage>> {
  const params = new URLSearchParams({ page: String(input.page) })
  if (input.perPage !== undefined) params.set('per_page', String(input.perPage))
  if (input.query !== undefined && input.query.trim() !== '') params.set('q', input.query.trim())
  if (input.status !== undefined && input.status !== '') params.set('status', input.status)

  const result = await requestJson(
    {
      method: 'GET',
      path: `/api/v1/admin/accounts?${params.toString()}`,
      authenticated: true,
      ...(input.signal && { signal: input.signal }),
    },
    (
      body,
    ): body is {
      data: WireAccount[]
      meta: { page: number; per_page: number; total: number; last_page: number }
    } =>
      isRecord(body) &&
      Array.isArray(body.data) &&
      body.data.every(isWireAccount) &&
      isRecord(body.meta) &&
      typeof body.meta.page === 'number' &&
      typeof body.meta.per_page === 'number' &&
      typeof body.meta.total === 'number' &&
      typeof body.meta.last_page === 'number',
  )
  if (!result.ok) return result
  const { data, meta } = result.value
  return {
    ok: true,
    value: {
      accounts: data.map(accountFrom),
      page: meta.page,
      perPage: meta.per_page,
      total: meta.total,
      lastPage: meta.last_page,
    },
  }
}

/** GET /admin/accounts/{id}. */
export function getAccount(id: string, signal?: AbortSignal): Promise<Result<ManagedAccount>> {
  return account(
    requestJson(
      {
        method: 'GET',
        path: `/api/v1/admin/accounts/${idPath(id)}`,
        authenticated: true,
        ...(signal && { signal }),
      },
      isWireAccount,
    ),
  )
}

/** The system roles the server offers for assignment (GET /admin/roles), as data. */
export async function listRoleCatalog(signal?: AbortSignal): Promise<Result<RoleDescriptor[]>> {
  const result = await requestJson(
    {
      method: 'GET',
      path: '/api/v1/admin/roles',
      authenticated: true,
      ...(signal && { signal }),
    },
    (body): body is { data: RoleDescriptor[] } =>
      isRecord(body) &&
      Array.isArray(body.data) &&
      body.data.every(
        (r) =>
          isRecord(r) &&
          isString(r.key) &&
          isString(r.name) &&
          isString(r.description) &&
          Array.isArray(r.capabilities) &&
          r.capabilities.every(isString),
      ),
  )
  return result.ok ? { ok: true, value: result.value.data } : result
}

export interface InvitationOutcome {
  account: ManagedAccount
  delivery: Delivery
}

function isInvitationResult(
  body: unknown,
): body is { account: WireAccount; delivery: { status: Delivery } } {
  return (
    isRecord(body) &&
    isWireAccount(body.account) &&
    isRecord(body.delivery) &&
    (body.delivery.status === 'sent' || body.delivery.status === 'failed')
  )
}

async function invitation(
  request: Promise<Result<{ account: WireAccount; delivery: { status: Delivery } }>>,
): Promise<Result<InvitationOutcome>> {
  const result = await request
  return result.ok
    ? {
        ok: true,
        value: {
          account: accountFrom(result.value.account),
          delivery: result.value.delivery.status,
        },
      }
    : result
}

/**
 * POST /admin/invitations. The invitation reaches the person by email only: the response says whether it was sent and
 * never carries the secret. `initialAssignments` are keys the server offered.
 */
export function inviteOperator(input: {
  email: string
  displayName: string
  initialAssignments: string[]
}): Promise<Result<InvitationOutcome>> {
  return invitation(
    requestJson(
      {
        method: 'POST',
        path: '/api/v1/admin/invitations',
        authenticated: true,
        body: {
          email: input.email,
          display_name: input.displayName,
          initial_assignments: input.initialAssignments,
        },
      },
      isInvitationResult,
    ),
  )
}

/** POST /admin/accounts/{id}/invitation: a fresh invitation for an Account that is still invited. */
export function reissueInvitation(id: string): Promise<Result<InvitationOutcome>> {
  return invitation(
    requestJson(
      {
        method: 'POST',
        path: `/api/v1/admin/accounts/${idPath(id)}/invitation`,
        authenticated: true,
      },
      isInvitationResult,
    ),
  )
}

function mutate(
  method: 'POST' | 'DELETE',
  path: `/api/v1/${string}`,
  body?: object,
): Promise<Result<ManagedAccount>> {
  return account(
    requestJson(
      { method, path, authenticated: true, ...(body !== undefined && { body }) },
      isWireAccount,
    ),
  )
}

export const disableAccount = (id: string) =>
  mutate('POST', `/api/v1/admin/accounts/${idPath(id)}/disable`)
export const enableAccount = (id: string) =>
  mutate('POST', `/api/v1/admin/accounts/${idPath(id)}/enable`)
/** Another account's second factor, never the caller's own: the server refuses that. */
export const resetAccountMfa = (id: string) =>
  mutate('POST', `/api/v1/admin/accounts/${idPath(id)}/mfa/reset`)
/** Names a KEY from the catalog; a capability can never be named here. */
export const grantRole = (id: string, key: string) =>
  mutate('POST', `/api/v1/admin/accounts/${idPath(id)}/assignments`, { key })
export const revokeRole = (id: string, key: string) =>
  mutate('DELETE', `/api/v1/admin/accounts/${idPath(id)}/assignments/${idPath(key)}`)

export type { FieldErrors }
