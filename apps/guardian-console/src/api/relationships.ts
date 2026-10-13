// The Relationships endpoints the Console uses (openapi/openapi.yaml is the contract, ADR 0038). The wire is snake_case; the
// Console's own types are camelCase. This file names only `/admin/relationship-types` and `/admin/relationships*`: nothing of
// CRM, Access or Identity is reached from here, and a source test pins that.
//
// Three shapes describe one relationship and they are NOT interchangeable (ADR 0038, F13):
//   - `RelationshipRecord` (the view capability): history and provenance, no transitions.
//   - `RelationshipManagement` (the manage capability): revision and allowed transitions, no history and no provenance.
//   - a directory row.
// Every mutation of an existing relationship names the `relationship_id` it was shown (F10). Status, fields, default-role
// and deletion also name the `revision`; a basic-detail edit names the instance only and compares `{from, to}` instead.
//
// What a type IS (its labels, states, transitions, fields and features) arrives as data from `listRelationshipTypes` and is
// the only source of it: this file knows no relationship type. A state's tone and a field's value type are read as plain
// strings and interpreted by the components, so a value this Console has never seen is shown safely and never crashes a page.

import { requestJson, requestVoid, type Failure, type Result } from './http.ts'

export type StateTone = 'positive' | 'caution' | 'neutral'

export interface RelationshipState {
  key: string
  label: string
  description: string
  tone: StateTone
  /** Whether a relationship in this state counts (it is what a default role is offered on). The server decides what it means. */
  qualifies: boolean
}

export interface RelationshipField {
  key: string
  /** Read as text: a value type this Console has no control for is shown, never edited. */
  valueType: string
  maxLength: number | null
  notAfter: string | null
  required: boolean
  visibility: string
  label: string
  help: string | null
  order: number
  group: string | null
  options: string[]
}

export interface RelationshipActions {
  view: boolean
  manage: boolean
  delete: boolean
  /** A hint for presentation only (manage plus role-assignment authority). The server decides every request. */
  provisionDefaultRole: boolean
}

export interface RelationshipType {
  type: string
  slug: string
  definitionVersion: number
  labels: { singular: string; plural: string; description: string; help: string | null }
  states: RelationshipState[]
  initialStates: string[]
  transitions: Record<string, string[]>
  fields: RelationshipField[]
  features: string[]
  position: number
  defaultRole: { displayName: string } | null
  actions: RelationshipActions
}

export interface PersonRef {
  id: string
  /** The server may send none; show `personName()`'s fallback then. */
  displayName: string | null
}

export type FieldValue = string | boolean
export type FieldValues = Record<string, FieldValue>

export interface RelationshipBasics {
  primaryEmail: string | null
  primaryPhone: string | null
}

export type DefaultRoleDecision = 'granted' | 'declined'

export interface DefaultRoleState {
  displayName: string
  decision: DefaultRoleDecision | null
  decidedBy: PersonRef | null
  decidedAt: string | null
  /** Whether a grant from THIS relationship is in effect. Never about the Person's other roles. */
  effective: boolean
}

export interface HistoryEntry {
  fromStatus: string | null
  toStatus: string
  changedAt: string
  changedBy: PersonRef
}

export interface RelationshipRecord {
  relationshipId: string
  type: string
  definitionVersion: number
  person: PersonRef
  status: { key: string; since: string; by: PersonRef }
  revision: number
  createdAt: string
  createdBy: PersonRef
  fields: FieldValues
  defaultRole: DefaultRoleState | null
  basics: RelationshipBasics | null
  history: HistoryEntry[]
}

export interface RelationshipManagement {
  relationshipId: string
  type: string
  person: PersonRef
  status: string
  revision: number
  transitions: string[]
  fields: FieldValues
  defaultRole: DefaultRoleState | null
  basics: RelationshipBasics | null
}

export interface DirectoryEntry {
  relationshipId: string
  person: PersonRef
  status: string
  statusChangedAt: string
  basics: RelationshipBasics | null
}

export interface DirectoryPage {
  entries: DirectoryEntry[]
  page: number
  perPage: number
  total: number
  lastPage: number
}

export type CandidateMatch = 'display_name' | 'email' | 'phone'

export interface Candidate {
  person: PersonRef
  matchedOn: CandidateMatch
  /** The status in THIS type only, or null when the Person does not hold it. */
  status: string | null
}

export interface CandidateList {
  candidates: Candidate[]
  truncated: boolean
}

/** What an operator decided about the type's default role. Sent explicitly: the server never grants by omission (K5). */
export type DefaultRoleChoice = 'grant' | 'decline' | 'defer'

/** The three current basic details, as the server returns them with a `stale_person_basics` refusal. */
export interface CurrentBasics {
  displayName: string
  primaryEmail: string | null
  primaryPhone: string | null
}

/** One basic detail edit: what the operator saw, compared exactly, and what they want it to be. */
export interface BasicChange {
  from: string | null
  to: string
}

export interface BasicChanges {
  displayName?: BasicChange
  primaryEmail?: BasicChange
  primaryPhone?: BasicChange
}

export interface NewPersonDetails {
  displayName: string
  email: string | null
  phone: string | null
}

/** A candidate the server advises about with `possible_duplicate`: id, name and what matched. Never a contact value. */
export interface DuplicateAdvice {
  id: string
  displayName: string
  matchedOn: string[]
}

/** `null` stands for clearing a value. A field that is not in the object is left alone. */
export type FieldChanges = Record<string, FieldValue | null>

const isString = (value: unknown): value is string => typeof value === 'string'
const isNullableString = (value: unknown): value is string | null =>
  value === null || isString(value)
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
const isNumber = (value: unknown): value is number => typeof value === 'number'
const isStringList = (value: unknown): value is string[] =>
  Array.isArray(value) && value.every(isString)

interface WirePerson {
  id: string
  display_name: string | null
}
const isWirePerson = (value: unknown): value is WirePerson =>
  isRecord(value) && isString(value.id) && isNullableString(value.display_name)
const personFrom = (wire: WirePerson): PersonRef => ({
  id: wire.id,
  displayName: wire.display_name,
})

function isFieldValues(value: unknown): value is Record<string, FieldValue> {
  return (
    isRecord(value) &&
    Object.values(value).every((item) => isString(item) || typeof item === 'boolean')
  )
}

interface WireBasics {
  primary_email: string | null
  primary_phone: string | null
}
const isWireBasics = (value: unknown): value is WireBasics =>
  isRecord(value) && isNullableString(value.primary_email) && isNullableString(value.primary_phone)
const isNullableBasics = (value: unknown): value is WireBasics | null =>
  value === null || isWireBasics(value)
const basicsFrom = (wire: WireBasics | null): RelationshipBasics | null =>
  wire === null ? null : { primaryEmail: wire.primary_email, primaryPhone: wire.primary_phone }

interface WireDefaultRole {
  display_name: string
  decision: DefaultRoleDecision | null
  decided_by: WirePerson | null
  decided_at: string | null
  effective: boolean
}
function isWireDefaultRole(value: unknown): value is WireDefaultRole {
  return (
    isRecord(value) &&
    isString(value.display_name) &&
    (value.decision === null || value.decision === 'granted' || value.decision === 'declined') &&
    (value.decided_by === null || isWirePerson(value.decided_by)) &&
    isNullableString(value.decided_at) &&
    typeof value.effective === 'boolean'
  )
}
const isNullableDefaultRole = (value: unknown): value is WireDefaultRole | null =>
  value === null || isWireDefaultRole(value)
const defaultRoleFrom = (wire: WireDefaultRole | null): DefaultRoleState | null =>
  wire === null
    ? null
    : {
        displayName: wire.display_name,
        decision: wire.decision,
        decidedBy: wire.decided_by === null ? null : personFrom(wire.decided_by),
        decidedAt: wire.decided_at,
        effective: wire.effective,
      }

// -- Type definitions ---------------------------------------------------------------------------------------------------

interface WireState {
  key: string
  label: string
  description: string
  tone: string
  qualifies: boolean
}
const isWireState = (value: unknown): value is WireState =>
  isRecord(value) &&
  isString(value.key) &&
  isString(value.label) &&
  isString(value.description) &&
  isString(value.tone) &&
  typeof value.qualifies === 'boolean'

interface WireField {
  key: string
  value_type: string
  max_length: number | null
  not_after: string | null
  required: boolean
  visibility: string
  label: string
  help: string | null
  order: number
  group: string | null
  options: string[]
}
const isWireField = (value: unknown): value is WireField =>
  isRecord(value) &&
  isString(value.key) &&
  isString(value.value_type) &&
  (value.max_length === null || isNumber(value.max_length)) &&
  isNullableString(value.not_after) &&
  typeof value.required === 'boolean' &&
  isString(value.visibility) &&
  isString(value.label) &&
  isNullableString(value.help) &&
  isNumber(value.order) &&
  isNullableString(value.group) &&
  isStringList(value.options)

interface WireType {
  type: string
  slug: string
  version: number
  labels: { singular: string; plural: string; description: string; help: string | null }
  states: WireState[]
  initial_states: string[]
  transitions: Record<string, string[]>
  fields: WireField[]
  features: string[]
  presentation: { position: number }
  default_role: { display_name: string } | null
  actions: { view: boolean; manage: boolean; delete: boolean; provision_default_role: boolean }
}

function isWireType(value: unknown): value is WireType {
  if (!isRecord(value)) return false
  const { labels, transitions, presentation, default_role: role, actions } = value
  return (
    isString(value.type) &&
    isString(value.slug) &&
    isNumber(value.version) &&
    isRecord(labels) &&
    isString(labels.singular) &&
    isString(labels.plural) &&
    isString(labels.description) &&
    isNullableString(labels.help) &&
    Array.isArray(value.states) &&
    value.states.every(isWireState) &&
    isStringList(value.initial_states) &&
    isRecord(transitions) &&
    Object.values(transitions).every(isStringList) &&
    Array.isArray(value.fields) &&
    value.fields.every(isWireField) &&
    isStringList(value.features) &&
    isRecord(presentation) &&
    isNumber(presentation.position) &&
    (role === null || (isRecord(role) && isString(role.display_name))) &&
    isRecord(actions) &&
    typeof actions.view === 'boolean' &&
    typeof actions.manage === 'boolean' &&
    typeof actions.delete === 'boolean' &&
    typeof actions.provision_default_role === 'boolean'
  )
}

const tones: readonly string[] = ['positive', 'caution', 'neutral']

function typeFrom(wire: WireType): RelationshipType {
  return {
    type: wire.type,
    slug: wire.slug,
    definitionVersion: wire.version,
    labels: { ...wire.labels },
    states: wire.states.map((state) => ({
      key: state.key,
      label: state.label,
      description: state.description,
      // A tone from a later vocabulary is shown as neutral: the label carries the meaning, and the shape never lies.
      tone: tones.includes(state.tone) ? (state.tone as StateTone) : 'neutral',
      qualifies: state.qualifies,
    })),
    initialStates: wire.initial_states,
    transitions: wire.transitions,
    fields: wire.fields.map((field) => ({
      key: field.key,
      valueType: field.value_type,
      maxLength: field.max_length,
      notAfter: field.not_after,
      required: field.required,
      visibility: field.visibility,
      label: field.label,
      help: field.help,
      order: field.order,
      group: field.group,
      options: field.options,
    })),
    features: wire.features,
    position: wire.presentation.position,
    defaultRole:
      wire.default_role === null ? null : { displayName: wire.default_role.display_name },
    actions: {
      view: wire.actions.view,
      manage: wire.actions.manage,
      delete: wire.actions.delete,
      provisionDefaultRole: wire.actions.provision_default_role,
    },
  }
}

/** GET /admin/relationship-types: the types this operator may view or manage, and how to present each. */
export async function listRelationshipTypes(
  signal?: AbortSignal,
): Promise<Result<RelationshipType[]>> {
  const result = await requestJson(
    {
      method: 'GET',
      path: '/api/v1/admin/relationship-types',
      authenticated: true,
      ...(signal && { signal }),
    },
    (body): body is { data: WireType[] } =>
      isRecord(body) && Array.isArray(body.data) && body.data.every(isWireType),
  )
  return result.ok ? { ok: true, value: result.value.data.map(typeFrom) } : result
}

// -- Records ------------------------------------------------------------------------------------------------------------

interface WireRecord {
  relationship_id: string
  type: string
  definition_version: number
  person: WirePerson
  status: { key: string; since: string; by: WirePerson }
  revision: number
  created_at: string
  created_by: WirePerson
  fields: Record<string, FieldValue>
  default_role: WireDefaultRole | null
  basics: WireBasics | null
  history: {
    from_status: string | null
    to_status: string
    changed_at: string
    changed_by: WirePerson
  }[]
}

function isWireRecord(value: unknown): value is WireRecord {
  if (!isRecord(value)) return false
  const { status } = value
  return (
    isString(value.relationship_id) &&
    isString(value.type) &&
    isNumber(value.definition_version) &&
    isWirePerson(value.person) &&
    isRecord(status) &&
    isString(status.key) &&
    isString(status.since) &&
    isWirePerson(status.by) &&
    isNumber(value.revision) &&
    isString(value.created_at) &&
    isWirePerson(value.created_by) &&
    isFieldValues(value.fields) &&
    isNullableDefaultRole(value.default_role) &&
    isNullableBasics(value.basics) &&
    Array.isArray(value.history) &&
    value.history.every(
      (entry: unknown) =>
        isRecord(entry) &&
        isNullableString(entry.from_status) &&
        isString(entry.to_status) &&
        isString(entry.changed_at) &&
        isWirePerson(entry.changed_by),
    )
  )
}

function recordFrom(wire: WireRecord): RelationshipRecord {
  return {
    relationshipId: wire.relationship_id,
    type: wire.type,
    definitionVersion: wire.definition_version,
    person: personFrom(wire.person),
    status: { key: wire.status.key, since: wire.status.since, by: personFrom(wire.status.by) },
    revision: wire.revision,
    createdAt: wire.created_at,
    createdBy: personFrom(wire.created_by),
    fields: wire.fields,
    defaultRole: defaultRoleFrom(wire.default_role),
    basics: basicsFrom(wire.basics),
    history: wire.history.map((entry) => ({
      fromStatus: entry.from_status,
      toStatus: entry.to_status,
      changedAt: entry.changed_at,
      changedBy: personFrom(entry.changed_by),
    })),
  }
}

interface WireManagement {
  relationship_id: string
  type: string
  person: WirePerson
  status: string
  revision: number
  transitions: string[]
  fields: Record<string, FieldValue>
  default_role: WireDefaultRole | null
  basics: WireBasics | null
}

function isWireManagement(value: unknown): value is WireManagement {
  return (
    isRecord(value) &&
    isString(value.relationship_id) &&
    isString(value.type) &&
    isWirePerson(value.person) &&
    isString(value.status) &&
    isNumber(value.revision) &&
    isStringList(value.transitions) &&
    isFieldValues(value.fields) &&
    isNullableDefaultRole(value.default_role) &&
    isNullableBasics(value.basics)
  )
}

function managementFrom(wire: WireManagement): RelationshipManagement {
  return {
    relationshipId: wire.relationship_id,
    type: wire.type,
    person: personFrom(wire.person),
    status: wire.status,
    revision: wire.revision,
    transitions: wire.transitions,
    fields: wire.fields,
    defaultRole: defaultRoleFrom(wire.default_role),
    basics: basicsFrom(wire.basics),
  }
}

async function management(
  request: Promise<Result<WireManagement>>,
): Promise<Result<RelationshipManagement>> {
  const result = await request
  return result.ok ? { ok: true, value: managementFrom(result.value) } : result
}

const segment = (value: string) => encodeURIComponent(value)
const typePath = (slug: string) => `/api/v1/admin/relationships/${segment(slug)}` as const
const personPath = (slug: string, personId: string) =>
  `${typePath(slug)}/${segment(personId)}` as const

/** GET /admin/relationships/{slug}/{person}: the view capability's read, with history and provenance. */
export async function getRelationship(
  slug: string,
  personId: string,
  signal?: AbortSignal,
): Promise<Result<RelationshipRecord>> {
  const result = await requestJson(
    {
      method: 'GET',
      path: personPath(slug, personId),
      authenticated: true,
      ...(signal && { signal }),
    },
    isWireRecord,
  )
  return result.ok ? { ok: true, value: recordFrom(result.value) } : result
}

/** GET /admin/relationships/{slug}/{person}/management: the manage capability's read. It needs no view capability. */
export function getRelationshipManagement(
  slug: string,
  personId: string,
  signal?: AbortSignal,
): Promise<Result<RelationshipManagement>> {
  return management(
    requestJson(
      {
        method: 'GET',
        path: `${personPath(slug, personId)}/management`,
        authenticated: true,
        ...(signal && { signal }),
      },
      isWireManagement,
    ),
  )
}

// -- Directory and candidates -------------------------------------------------------------------------------------------

/** GET /admin/relationships/{slug}: one page of the directory (view capability). */
export async function listDirectory(input: {
  slug: string
  page: number
  perPage: number
  query: string
  status: string
  signal?: AbortSignal
}): Promise<Result<DirectoryPage>> {
  const params = new URLSearchParams({
    page: String(input.page),
    per_page: String(input.perPage),
  })
  if (input.query !== '') params.set('q', input.query)
  if (input.status !== '') params.set('status', input.status)

  const result = await requestJson(
    {
      method: 'GET',
      path: `${typePath(input.slug)}?${params.toString()}`,
      authenticated: true,
      ...(input.signal && { signal: input.signal }),
    },
    (
      body,
    ): body is {
      data: {
        relationship_id: string
        person: WirePerson
        status: string
        status_changed_at: string
        basics: WireBasics | null
      }[]
      meta: { page: number; per_page: number; total: number }
    } =>
      isRecord(body) &&
      Array.isArray(body.data) &&
      body.data.every(
        (row: unknown) =>
          isRecord(row) &&
          isString(row.relationship_id) &&
          isWirePerson(row.person) &&
          isString(row.status) &&
          isString(row.status_changed_at) &&
          isNullableBasics(row.basics),
      ) &&
      isRecord(body.meta) &&
      isNumber(body.meta.page) &&
      isNumber(body.meta.per_page) &&
      isNumber(body.meta.total),
  )
  if (!result.ok) return result
  const { data, meta } = result.value
  return {
    ok: true,
    value: {
      entries: data.map((row) => ({
        relationshipId: row.relationship_id,
        person: personFrom(row.person),
        status: row.status,
        statusChangedAt: row.status_changed_at,
        basics: basicsFrom(row.basics),
      })),
      page: meta.page,
      perPage: meta.per_page,
      total: meta.total,
      lastPage: Math.max(1, Math.ceil(meta.total / Math.max(1, meta.per_page))),
    },
  }
}

/** GET /admin/relationships/{slug}/candidates: the bounded lookup for intake (manage capability). */
export async function findCandidates(
  slug: string,
  query: string,
  signal?: AbortSignal,
): Promise<Result<CandidateList>> {
  const result = await requestJson(
    {
      method: 'GET',
      path: `${typePath(slug)}/candidates?${new URLSearchParams({ q: query }).toString()}`,
      authenticated: true,
      ...(signal && { signal }),
    },
    (
      body,
    ): body is {
      data: { person: WirePerson; matched_on: CandidateMatch; status: string | null }[]
      truncated: boolean
    } =>
      isRecord(body) &&
      typeof body.truncated === 'boolean' &&
      Array.isArray(body.data) &&
      body.data.every(
        (row: unknown) =>
          isRecord(row) &&
          isWirePerson(row.person) &&
          (row.matched_on === 'display_name' ||
            row.matched_on === 'email' ||
            row.matched_on === 'phone') &&
          isNullableString(row.status),
      ),
  )
  if (!result.ok) return result
  return {
    ok: true,
    value: {
      truncated: result.value.truncated,
      candidates: result.value.data.map((row) => ({
        person: personFrom(row.person),
        matchedOn: row.matched_on,
        status: row.status,
      })),
    },
  }
}

// -- Mutations ----------------------------------------------------------------------------------------------------------

/** Who intake records: an existing Person the operator has confirmed, or a new one. Never both. */
export type IntakeSubject =
  | { kind: 'existing'; personId: string }
  | { kind: 'new'; person: NewPersonDetails; confirmDistinct: boolean }

/**
 * POST /admin/relationships/{slug}. `defaultRole` is sent only when the caller states it: an Active intake of a type with a
 * default role must say `grant`, `decline` or `defer`, and any other must say nothing (K5, K7).
 */
export function establishRelationship(
  slug: string,
  input: {
    subject: IntakeSubject
    status: string
    fields: FieldChanges
    defaultRole?: DefaultRoleChoice
  },
): Promise<Result<RelationshipManagement>> {
  const subject =
    input.subject.kind === 'existing'
      ? { person_id: input.subject.personId, confirm_existing_person: true }
      : {
          new_person: {
            display_name: input.subject.person.displayName,
            email: input.subject.person.email,
            phone: input.subject.person.phone,
          },
          ...(input.subject.confirmDistinct && { confirm_distinct: true }),
        }
  return management(
    requestJson(
      {
        method: 'POST',
        path: typePath(slug),
        authenticated: true,
        body: {
          ...subject,
          status: input.status,
          fields: input.fields,
          ...(input.defaultRole !== undefined && { default_role: input.defaultRole }),
        },
      },
      isWireManagement,
    ),
  )
}

/** PUT .../status. Names the instance and the revision it was shown. `defaultRole` only when entering a qualifying state. */
export function changeRelationshipStatus(
  slug: string,
  personId: string,
  input: {
    relationshipId: string
    revision: number
    status: string
    defaultRole?: DefaultRoleChoice
  },
): Promise<Result<RelationshipManagement>> {
  return management(
    requestJson(
      {
        method: 'PUT',
        path: `${personPath(slug, personId)}/status`,
        authenticated: true,
        body: {
          relationship_id: input.relationshipId,
          revision: input.revision,
          status: input.status,
          ...(input.defaultRole !== undefined && { default_role: input.defaultRole }),
        },
      },
      isWireManagement,
    ),
  )
}

/** PUT .../default-role: a decision on the type's default role while the relationship exists. */
export function decideDefaultRole(
  slug: string,
  personId: string,
  input: { relationshipId: string; revision: number; decision: DefaultRoleChoice },
): Promise<Result<RelationshipManagement>> {
  return management(
    requestJson(
      {
        method: 'PUT',
        path: `${personPath(slug, personId)}/default-role`,
        authenticated: true,
        body: {
          relationship_id: input.relationshipId,
          revision: input.revision,
          default_role: input.decision,
        },
      },
      isWireManagement,
    ),
  )
}

/** PATCH .../fields: a partial update. A key that is absent is left alone; `null` clears it. */
export function updateRelationshipFields(
  slug: string,
  personId: string,
  input: { relationshipId: string; revision: number; fields: FieldChanges },
): Promise<Result<RelationshipManagement>> {
  return management(
    requestJson(
      {
        method: 'PATCH',
        path: `${personPath(slug, personId)}/fields`,
        authenticated: true,
        body: {
          relationship_id: input.relationshipId,
          revision: input.revision,
          fields: input.fields,
        },
      },
      isWireManagement,
    ),
  )
}

const basicKeys = {
  displayName: 'display_name',
  primaryEmail: 'primary_email',
  primaryPhone: 'primary_phone',
} as const

/**
 * PATCH .../basics: exactly the three basic details, each as `{from, to}`. It names the instance and NO revision: the
 * details are CRM's, guarded by their own compare-and-set, and editing them does not change the relationship's revision.
 */
export function updateRelationshipBasics(
  slug: string,
  personId: string,
  input: { relationshipId: string; changes: BasicChanges },
): Promise<Result<RelationshipManagement>> {
  const body: Record<string, unknown> = { relationship_id: input.relationshipId }
  for (const [key, wireKey] of Object.entries(basicKeys) as [keyof BasicChanges, string][]) {
    const change = input.changes[key]
    if (change !== undefined) body[wireKey] = { from: change.from, to: change.to }
  }
  return management(
    requestJson(
      {
        method: 'PATCH',
        path: `${personPath(slug, personId)}/basics`,
        authenticated: true,
        body,
      },
      isWireManagement,
    ),
  )
}

/** DELETE .../{person}?relationship_id=&revision=. The instance and revision are query parameters: a DELETE body is unreliable. */
export function deleteRelationship(
  slug: string,
  personId: string,
  input: { relationshipId: string; revision: number },
): Promise<Result<null>> {
  const params = new URLSearchParams({
    relationship_id: input.relationshipId,
    revision: String(input.revision),
  })
  return requestVoid({
    method: 'DELETE',
    path: `${personPath(slug, personId)}?${params.toString()}`,
    authenticated: true,
  })
}

// -- What a refusal carries ---------------------------------------------------------------------------------------------

/** The management view a `stale_revision` refusal carries: the current state, including the current instance id. */
export function staleRevisionCurrent(failure: Failure): RelationshipManagement | null {
  if (failure.kind !== 'conflict' || failure.code !== 'stale_revision') return null
  return isWireManagement(failure.current) ? managementFrom(failure.current) : null
}

/** The three current values a `stale_person_basics` refusal carries. */
export function staleBasicsCurrent(failure: Failure): CurrentBasics | null {
  if (failure.kind !== 'conflict' || failure.code !== 'stale_person_basics') return null
  const current = failure.current
  if (
    !isRecord(current) ||
    !isString(current.display_name) ||
    !isNullableString(current.primary_email) ||
    !isNullableString(current.primary_phone)
  ) {
    return null
  }
  return {
    displayName: current.display_name,
    primaryEmail: current.primary_email,
    primaryPhone: current.primary_phone,
  }
}

/** The people a `possible_duplicate` refusal advises about. Untrusted, so each is checked; none is a decision. */
export function duplicateAdvice(failure: Failure): DuplicateAdvice[] | null {
  if (
    failure.kind !== 'conflict' ||
    failure.code !== 'possible_duplicate' ||
    failure.candidates === undefined
  ) {
    return null
  }
  const advice: DuplicateAdvice[] = []
  for (const candidate of failure.candidates) {
    if (
      !isRecord(candidate) ||
      !isString(candidate.id) ||
      !isString(candidate.display_name) ||
      !isStringList(candidate.matched_on)
    ) {
      return null
    }
    advice.push({
      id: candidate.id,
      displayName: candidate.display_name,
      matchedOn: candidate.matched_on,
    })
  }
  return advice
}
