// The Membership administration endpoints the Console uses (openapi/openapi.yaml is the contract, ADR 0028). The wire is
// snake_case; the Console's own types are camelCase. `source` is read back as plain `string`, never the input union: a
// future source the server accepts but this Console does not yet know about must be shown, not crash the page.

import { requestJson, requestVoid, type FieldErrors, type Result } from './http.ts'

/** Sources this Console's forms may choose. The server may recognise others; reading one back never fails on that account. */
export type MembershipSource = 'operator' | 'luma_legacy'

export interface MembershipGrant {
  id: string
  personId: string
  startsAt: string
  /** Null is an explicit, approved open-ended grant, never "no end date entered yet". */
  endsAt: string | null
  source: string
  sourceReference: string | null
  /** Null unless this grant was revoked. One-way: never becomes null again. */
  revokedAt: string | null
}

export interface Member {
  person: { id: string; displayName: string }
  /** Derived at the instant the server answered; never stored. */
  active: boolean
  currentAccessEndsAt: string | null
  openEnded: boolean
  /** Complete history, oldest first: current, future, expired and revoked grants alike. */
  grants: MembershipGrant[]
}

export interface MemberPage {
  members: Member[]
  page: number
  perPage: number
  total: number
  lastPage: number
}

/** The standalone shape `POST .../grants` returns: a grant is self-contained, so it names its own Person. */
interface WireMembershipGrant {
  id: string
  person_id: string
  starts_at: string
  ends_at: string | null
  source: string
  source_reference: string | null
  revoked_at: string | null
}

/**
 * The shape a grant takes INSIDE a `Member`'s history: no `person_id`, because the record it is nested in already names
 * one Person. A single `WireMembershipGrant` (with `person_id`) would silently fail to parse every membership record's
 * history — this is not a hypothetical, an integration test against the real API caught exactly that.
 */
type WireMemberGrantEntry = Omit<WireMembershipGrant, 'person_id'>

interface WireMember {
  person: { id: string; display_name: string }
  active: boolean
  current_access_ends_at: string | null
  open_ended: boolean
  grants: WireMemberGrantEntry[]
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isNullableString = (value: unknown): value is string | null =>
  value === null || isString(value)
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null

function isWireMemberGrantEntry(value: unknown): value is WireMemberGrantEntry {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.starts_at) &&
    isNullableString(value.ends_at) &&
    isString(value.source) &&
    isNullableString(value.source_reference) &&
    isNullableString(value.revoked_at)
  )
}

function isWireMembershipGrant(value: unknown): value is WireMembershipGrant {
  return isRecord(value) && isString(value.person_id) && isWireMemberGrantEntry(value)
}

function isWireMember(value: unknown): value is WireMember {
  if (!isRecord(value)) return false
  const { person, grants } = value
  return (
    isRecord(person) &&
    isString(person.id) &&
    isString(person.display_name) &&
    typeof value.active === 'boolean' &&
    isNullableString(value.current_access_ends_at) &&
    typeof value.open_ended === 'boolean' &&
    Array.isArray(grants) &&
    grants.every(isWireMemberGrantEntry)
  )
}

function grantFrom(wire: WireMembershipGrant): MembershipGrant {
  return {
    id: wire.id,
    personId: wire.person_id,
    startsAt: wire.starts_at,
    endsAt: wire.ends_at,
    source: wire.source,
    sourceReference: wire.source_reference,
    revokedAt: wire.revoked_at,
  }
}

/** A history entry, given the Person id of the record it came from (it carries none of its own on the wire). */
function grantEntryFrom(wire: WireMemberGrantEntry, personId: string): MembershipGrant {
  return {
    id: wire.id,
    personId,
    startsAt: wire.starts_at,
    endsAt: wire.ends_at,
    source: wire.source,
    sourceReference: wire.source_reference,
    revokedAt: wire.revoked_at,
  }
}

function memberFrom(wire: WireMember): Member {
  return {
    person: { id: wire.person.id, displayName: wire.person.display_name },
    active: wire.active,
    currentAccessEndsAt: wire.current_access_ends_at,
    openEnded: wire.open_ended,
    grants: wire.grants.map((grant) => grantEntryFrom(grant, wire.person.id)),
  }
}

async function member(request: Promise<Result<WireMember>>): Promise<Result<Member>> {
  const result = await request
  return result.ok ? { ok: true, value: memberFrom(result.value) } : result
}

const idPath = (id: string) => encodeURIComponent(id)

/** GET /admin/members: one record per Person who has ever held a grant, a bounded page at a time. No search yet. */
export async function listMembers(input: {
  page: number
  perPage?: number
  signal?: AbortSignal
}): Promise<Result<MemberPage>> {
  const params = new URLSearchParams({ page: String(input.page) })
  if (input.perPage !== undefined) params.set('per_page', String(input.perPage))

  const result = await requestJson(
    {
      method: 'GET',
      path: `/api/v1/admin/members?${params.toString()}`,
      authenticated: true,
      ...(input.signal && { signal: input.signal }),
    },
    (
      body,
    ): body is {
      data: WireMember[]
      meta: { page: number; per_page: number; total: number; last_page: number }
    } =>
      isRecord(body) &&
      Array.isArray(body.data) &&
      body.data.every(isWireMember) &&
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
      members: data.map(memberFrom),
      page: meta.page,
      perPage: meta.per_page,
      total: meta.total,
      lastPage: meta.last_page,
    },
  }
}

/** GET /admin/members/{person}. 404 both for an unknown Person and for one who has never held a grant. */
export function getMember(personId: string, signal?: AbortSignal): Promise<Result<Member>> {
  return member(
    requestJson(
      {
        method: 'GET',
        path: `/api/v1/admin/members/${idPath(personId)}`,
        authenticated: true,
        ...(signal && { signal }),
      },
      isWireMember,
    ),
  )
}

/**
 * When a grant runs, with open-ended access DECLARED rather than inferred (ADR 0028). The two intents have exactly one
 * representation each, and the type makes the invalid mixes unrepresentable: open-ended access has no end date, and a
 * bounded term must have one. A blank end date is therefore never a way to ask for open-ended access.
 */
export type MembershipTerm =
  | { startsAt: string; openEnded: true; endsAt: null }
  | { startsAt: string; openEnded: false; endsAt: string }

export interface MembershipTermInput {
  /** `startsAt` an ISO instant, as produced by the Console's own datetime conversion. */
  term: MembershipTerm
  source: MembershipSource
  sourceReference: string | null
}

/** The term as the API states it: `open_ended` is always sent, and `ends_at` is `null` exactly when it is true. */
function termBody(term: MembershipTerm) {
  return { starts_at: term.startsAt, open_ended: term.openEnded, ends_at: term.endsAt }
}

/**
 * POST /admin/members: creates a Person with no Account (ADR 0015) and their first membership grant, atomically. Returns
 * the new membership record.
 */
export function registerMember(
  input: { displayName: string } & MembershipTermInput,
): Promise<Result<Member>> {
  return member(
    requestJson(
      {
        method: 'POST',
        path: '/api/v1/admin/members',
        authenticated: true,
        body: {
          display_name: input.displayName,
          ...termBody(input.term),
          source: input.source,
          source_reference: input.sourceReference,
        },
      },
      isWireMember,
    ),
  )
}

/**
 * POST /admin/members/{person}/grants: an additional term for an EXISTING Person. Overlapping and touching terms are
 * valid; nothing here refuses them. Returns the grant just created, not the Person's whole history.
 */
export function grantMembership(
  personId: string,
  input: MembershipTermInput,
): Promise<Result<MembershipGrant>> {
  return (async () => {
    const result = await requestJson(
      {
        method: 'POST',
        path: `/api/v1/admin/members/${idPath(personId)}/grants`,
        authenticated: true,
        body: {
          ...termBody(input.term),
          source: input.source,
          source_reference: input.sourceReference,
        },
      },
      isWireMembershipGrant,
    )
    return result.ok ? { ok: true, value: grantFrom(result.value) } : result
  })()
}

/** POST /membership-grants/{grant}/revoke: one-way. Refused, not a no-op, if it was already revoked. */
export function revokeMembershipGrant(grantId: string): Promise<Result<null>> {
  return requestVoid({
    method: 'POST',
    path: `/api/v1/admin/membership-grants/${idPath(grantId)}/revoke`,
    authenticated: true,
  })
}

export type { FieldErrors }
