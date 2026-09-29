// GET /api/v1/my/membership (openapi/openapi.yaml, ADR 0032, Work Package 2): the signed-in Account's OWN
// membership state and grant history. Deliberately its own client, not a reuse of `api/membership.ts`'s
// richer admin `Member` shape: the self-service `CurrentMembership` DTO is narrower on purpose (no grant
// id, no source, no granting/revoking Account), and this type must stay exactly what the endpoint returns,
// not what the admin one happens to offer.

import { requestJson, type Result } from './http.ts'

export interface CurrentMembershipGrant {
  startsAt: string
  /** Null is an explicit, approved open-ended grant, never "no end date entered yet". */
  endsAt: string | null
  revoked: boolean
}

export interface CurrentMembership {
  /** Derived at the instant the server answered; never stored, and never recomputed here. */
  active: boolean
  /** Null when access is not currently active, or when the current access run is open-ended. */
  currentAccessEndsAt: string | null
  openEnded: boolean
  /** The complete history, oldest first: current, future, expired and revoked grants alike. */
  grants: CurrentMembershipGrant[]
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isNullableString = (value: unknown): value is string | null =>
  value === null || isString(value)
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null

interface WireCurrentMembershipGrant {
  starts_at: string
  ends_at: string | null
  revoked: boolean
}

interface WireCurrentMembership {
  active: boolean
  current_access_ends_at: string | null
  open_ended: boolean
  grants: WireCurrentMembershipGrant[]
}

function isWireGrant(value: unknown): value is WireCurrentMembershipGrant {
  return (
    isRecord(value) &&
    isString(value.starts_at) &&
    isNullableString(value.ends_at) &&
    typeof value.revoked === 'boolean'
  )
}

function isWireCurrentMembership(value: unknown): value is WireCurrentMembership {
  if (!isRecord(value)) return false
  const { grants } = value
  return (
    typeof value.active === 'boolean' &&
    isNullableString(value.current_access_ends_at) &&
    typeof value.open_ended === 'boolean' &&
    Array.isArray(grants) &&
    grants.every(isWireGrant)
  )
}

function fromWire(wire: WireCurrentMembership): CurrentMembership {
  return {
    active: wire.active,
    currentAccessEndsAt: wire.current_access_ends_at,
    openEnded: wire.open_ended,
    grants: wire.grants.map((grant) => ({
      startsAt: grant.starts_at,
      endsAt: grant.ends_at,
      revoked: grant.revoked,
    })),
  }
}

/** GET /my/membership: authenticated self-service, never evidence of active membership by itself. */
export async function getCurrentMembership(
  signal?: AbortSignal,
): Promise<Result<CurrentMembership>> {
  const result = await requestJson(
    {
      method: 'GET',
      path: '/api/v1/my/membership',
      authenticated: true,
      ...(signal && { signal }),
    },
    isWireCurrentMembership,
  )
  return result.ok ? { ok: true, value: fromWire(result.value) } : result
}
