// The People (CRM) endpoints the Console uses (openapi/openapi.yaml is the contract, ADR 0034). The wire is snake_case; the
// Console's own types are camelCase. Only what CRM owns plus the Person's id and name is ever here: the contract carries no
// Account, role, Membership or security data, and nothing in this file asks for any. Interactions have their own
// endpoints (api/interactions.ts) and tags theirs (api/tags.ts). A contact method's `kind` is read back as plain `string`,
// never the input union: a future kind the server holds but this Console does not yet know must be shown, not crash the page.

import { requestJson, requestVoid, type Failure, type Result } from './http.ts'
import { isTagRef, type TagRef } from './tags.ts'

/** Kinds this Console's forms may choose. */
export type ContactMethodKind = 'email' | 'phone'

export interface PersonRef {
  id: string
  displayName: string
}

export interface PersonProfile {
  howWeKnow: string | null
  affiliation: string | null
}

export interface ContactMethod {
  id: string
  kind: string
  /** As the human entered it. The matching form is the server's own business and never reaches the Console. */
  value: string
  label: string | null
  isPrimary: boolean
}

export interface PersonRecord {
  person: PersonRef
  profile: PersonProfile
  contactMethods: ContactMethod[]
  /** Labels only: a tag carries no authority, Membership or Volunteer meaning. */
  tags: TagRef[]
}

export interface PersonListing {
  id: string
  displayName: string
  primaryEmail: string | null
  primaryPhone: string | null
}

export interface PeoplePage {
  people: PersonListing[]
  page: number
  perPage: number
  total: number
  lastPage: number
}

/** One advisory candidate from a `409 possible_duplicate`: directory information and what matched. Never a merge. */
export interface DuplicateCandidate {
  id: string
  displayName: string
  matchedOn: string[]
}

interface WireContactMethod {
  id: string
  kind: string
  value: string
  label: string | null
  is_primary: boolean
}

interface WirePersonRecord {
  person: { id: string; display_name: string }
  profile: { how_we_know: string | null; affiliation: string | null }
  contact_methods: WireContactMethod[]
  tags: TagRef[]
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isNullableString = (value: unknown): value is string | null =>
  value === null || isString(value)
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null
const isPersonRef = (value: unknown): value is { id: string; display_name: string } =>
  isRecord(value) && isString(value.id) && isString(value.display_name)

function isWireContactMethod(value: unknown): value is WireContactMethod {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.kind) &&
    isString(value.value) &&
    isNullableString(value.label) &&
    typeof value.is_primary === 'boolean'
  )
}

const isWireProfile = (value: unknown): value is WirePersonRecord['profile'] =>
  isRecord(value) && isNullableString(value.how_we_know) && isNullableString(value.affiliation)

function isWirePersonRecord(value: unknown): value is WirePersonRecord {
  return (
    isRecord(value) &&
    isPersonRef(value.person) &&
    isWireProfile(value.profile) &&
    Array.isArray(value.contact_methods) &&
    value.contact_methods.every(isWireContactMethod) &&
    Array.isArray(value.tags) &&
    value.tags.every(isTagRef)
  )
}

function methodFrom(wire: WireContactMethod): ContactMethod {
  return {
    id: wire.id,
    kind: wire.kind,
    value: wire.value,
    label: wire.label,
    isPrimary: wire.is_primary,
  }
}

function recordFrom(wire: WirePersonRecord): PersonRecord {
  return {
    person: { id: wire.person.id, displayName: wire.person.display_name },
    profile: { howWeKnow: wire.profile.how_we_know, affiliation: wire.profile.affiliation },
    contactMethods: wire.contact_methods.map(methodFrom),
    tags: wire.tags.map((tag) => ({ id: tag.id, name: tag.name })),
  }
}

async function record(request: Promise<Result<WirePersonRecord>>): Promise<Result<PersonRecord>> {
  const result = await request
  return result.ok ? { ok: true, value: recordFrom(result.value) } : result
}

const idPath = (id: string) => encodeURIComponent(id)

/** GET /admin/people: EVERY Person, name order, a bounded page at a time. `query` is the server's own text search. */
export async function listPeople(input: {
  page: number
  perPage?: number
  query?: string
  signal?: AbortSignal
}): Promise<Result<PeoplePage>> {
  const params = new URLSearchParams({ page: String(input.page) })
  if (input.perPage !== undefined) params.set('per_page', String(input.perPage))
  const query = input.query?.trim() ?? ''
  if (query !== '') params.set('q', query)

  const result = await requestJson(
    {
      method: 'GET',
      path: `/api/v1/admin/people?${params.toString()}`,
      authenticated: true,
      ...(input.signal && { signal: input.signal }),
    },
    (
      body,
    ): body is {
      data: {
        id: string
        display_name: string
        primary_email: string | null
        primary_phone: string | null
      }[]
      meta: { page: number; per_page: number; total: number; last_page: number }
    } =>
      isRecord(body) &&
      Array.isArray(body.data) &&
      body.data.every(
        (row) =>
          isRecord(row) &&
          isString(row.id) &&
          isString(row.display_name) &&
          isNullableString(row.primary_email) &&
          isNullableString(row.primary_phone),
      ) &&
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
      people: data.map((row) => ({
        id: row.id,
        displayName: row.display_name,
        primaryEmail: row.primary_email,
        primaryPhone: row.primary_phone,
      })),
      page: meta.page,
      perPage: meta.per_page,
      total: meta.total,
      lastPage: meta.last_page,
    },
  }
}

/** GET /admin/people/{person}. 404 for a Person who does not exist. */
export function getPerson(personId: string, signal?: AbortSignal): Promise<Result<PersonRecord>> {
  return record(
    requestJson(
      {
        method: 'GET',
        path: `/api/v1/admin/people/${idPath(personId)}`,
        authenticated: true,
        ...(signal && { signal }),
      },
      isWirePersonRecord,
    ),
  )
}

export interface NewContactMethod {
  kind: ContactMethodKind
  value: string
  label?: string | null
  isPrimary?: boolean
}

const methodBody = (method: NewContactMethod) => ({
  kind: method.kind,
  value: method.value,
  label: method.label ?? null,
  is_primary: method.isPrimary ?? false,
})

/**
 * POST /admin/people: a new Person with no Account, Membership or access (ADR 0015), and what is recorded about them, in one
 * step. Possible duplicates answer `409 possible_duplicate` (see `duplicateCandidates`) and create nothing; sending the same
 * request with `confirmDistinct` registers a distinct Person anyway. Advice, never a merge.
 */
export function registerPerson(input: {
  displayName: string
  howWeKnow: string | null
  affiliation: string | null
  contactMethods: NewContactMethod[]
  confirmDistinct: boolean
}): Promise<Result<PersonRecord>> {
  return record(
    requestJson(
      {
        method: 'POST',
        path: '/api/v1/admin/people',
        authenticated: true,
        body: {
          display_name: input.displayName,
          how_we_know: input.howWeKnow,
          affiliation: input.affiliation,
          contact_methods: input.contactMethods.map(methodBody),
          ...(input.confirmDistinct && { confirm_distinct: true }),
        },
      },
      isWirePersonRecord,
    ),
  )
}

/**
 * What a `409` says about possible duplicates, checked: the candidates, or `null` if this failure is not that advice (or its
 * body is not the documented shape, in which case the Console treats it as an ordinary conflict).
 */
export function duplicateCandidates(failure: Failure): DuplicateCandidate[] | null {
  if (failure.kind !== 'conflict' || failure.code !== 'possible_duplicate') return null
  const wire = failure.candidates
  if (wire === undefined) return null
  const candidates: DuplicateCandidate[] = []
  for (const entry of wire) {
    if (
      !isRecord(entry) ||
      !isString(entry.id) ||
      !isString(entry.display_name) ||
      !Array.isArray(entry.matched_on) ||
      !entry.matched_on.every(isString)
    ) {
      return null
    }
    candidates.push({
      id: entry.id,
      displayName: entry.display_name,
      matchedOn: entry.matched_on,
    })
  }
  return candidates
}

/** The fields of PATCH /admin/people/{person}: only what is present is sent, so what is absent is left alone. */
export interface PersonChanges {
  displayName?: string
  howWeKnow?: string | null
  affiliation?: string | null
}

export interface PersonUpdated {
  person: PersonRef
  profile: PersonProfile
}

/** PATCH /admin/people/{person}. A profile field sent as null (or blank) is cleared; a name is never cleared. */
export async function updatePerson(
  personId: string,
  changes: PersonChanges,
): Promise<Result<PersonUpdated>> {
  const result = await requestJson(
    {
      method: 'PATCH',
      path: `/api/v1/admin/people/${idPath(personId)}`,
      authenticated: true,
      body: {
        ...(changes.displayName !== undefined && { display_name: changes.displayName }),
        ...(changes.howWeKnow !== undefined && { how_we_know: changes.howWeKnow }),
        ...(changes.affiliation !== undefined && { affiliation: changes.affiliation }),
      },
    },
    (body): body is Pick<WirePersonRecord, 'person' | 'profile'> =>
      isRecord(body) && isPersonRef(body.person) && isWireProfile(body.profile),
  )
  if (!result.ok) return result
  return {
    ok: true,
    value: {
      person: { id: result.value.person.id, displayName: result.value.person.display_name },
      profile: {
        howWeKnow: result.value.profile.how_we_know,
        affiliation: result.value.profile.affiliation,
      },
    },
  }
}

async function method(request: Promise<Result<WireContactMethod>>): Promise<Result<ContactMethod>> {
  const result = await request
  return result.ok ? { ok: true, value: methodFrom(result.value) } : result
}

/** POST /admin/people/{person}/contact-methods. The first method of a kind becomes that kind's primary, whatever is asked. */
export function addContactMethod(
  personId: string,
  input: NewContactMethod,
): Promise<Result<ContactMethod>> {
  return method(
    requestJson(
      {
        method: 'POST',
        path: `/api/v1/admin/people/${idPath(personId)}/contact-methods`,
        authenticated: true,
        body: methodBody(input),
      },
      isWireContactMethod,
    ),
  )
}

/**
 * PATCH .../contact-methods/{method}: any of `value`, `label`, `isPrimary`. Making a method primary demotes the one that was;
 * the kind never changes, and the current primary cannot be un-set (promote another instead), so `isPrimary` here is only
 * ever sent as `true`.
 */
export function updateContactMethod(
  personId: string,
  methodId: string,
  changes: { value?: string; label?: string | null; makePrimary?: true },
): Promise<Result<ContactMethod>> {
  return method(
    requestJson(
      {
        method: 'PATCH',
        path: `/api/v1/admin/people/${idPath(personId)}/contact-methods/${idPath(methodId)}`,
        authenticated: true,
        body: {
          ...(changes.value !== undefined && { value: changes.value }),
          ...(changes.label !== undefined && { label: changes.label }),
          ...(changes.makePrimary === true && { is_primary: true }),
        },
      },
      isWireContactMethod,
    ),
  )
}

/** DELETE .../contact-methods/{method}. If it was the primary of its kind, the earliest remaining one becomes primary. */
export function removeContactMethod(personId: string, methodId: string): Promise<Result<null>> {
  return requestVoid({
    method: 'DELETE',
    path: `/api/v1/admin/people/${idPath(personId)}/contact-methods/${idPath(methodId)}`,
    authenticated: true,
  })
}
