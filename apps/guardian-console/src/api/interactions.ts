// The CRM notes-and-interactions endpoints the Console uses (openapi/openapi.yaml is the contract, ADR 0034). The author and the
// last editor arrive as the backend's minimal Person projection (id and name) and nothing else, and the Console never sends
// who wrote anything: the server takes that from the signed-in session. `kind` is read back as plain `string`, never the input
// union: a kind the server holds but this Console does not yet know must be shown, not crash the page.

import { requestJson, requestVoid, type Result } from './http.ts'
import type { PersonRef } from './people.ts'

/** Kinds this Console's forms may choose. */
export type InteractionKind = 'note' | 'call' | 'email' | 'meeting'

export interface Interaction {
  id: string
  kind: string
  body: string
  /** When the contact happened: what the list is ordered by. */
  occurredAt: string
  /** Null only if Identity no longer holds the Person. */
  author: PersonRef | null
  /** Null until someone corrects it. There is no edit history behind it. */
  updatedBy: PersonRef | null
  createdAt: string
  updatedAt: string
}

export interface InteractionsPage {
  interactions: Interaction[]
  page: number
  perPage: number
  total: number
  lastPage: number
}

interface WirePersonRef {
  id: string
  display_name: string
}

interface WireInteraction {
  id: string
  kind: string
  body: string
  occurred_at: string
  author: WirePersonRef | null
  updated_by: WirePersonRef | null
  created_at: string
  updated_at: string
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null
const isWirePersonRef = (value: unknown): value is WirePersonRef =>
  isRecord(value) && isString(value.id) && isString(value.display_name)
const isNullableWirePersonRef = (value: unknown): value is WirePersonRef | null =>
  value === null || isWirePersonRef(value)

function isWireInteraction(value: unknown): value is WireInteraction {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.kind) &&
    isString(value.body) &&
    isString(value.occurred_at) &&
    isNullableWirePersonRef(value.author) &&
    isNullableWirePersonRef(value.updated_by) &&
    isString(value.created_at) &&
    isString(value.updated_at)
  )
}

const personFrom = (wire: WirePersonRef | null): PersonRef | null =>
  wire === null ? null : { id: wire.id, displayName: wire.display_name }

function interactionFrom(wire: WireInteraction): Interaction {
  return {
    id: wire.id,
    kind: wire.kind,
    body: wire.body,
    occurredAt: wire.occurred_at,
    author: personFrom(wire.author),
    updatedBy: personFrom(wire.updated_by),
    createdAt: wire.created_at,
    updatedAt: wire.updated_at,
  }
}

const idPath = (id: string) => encodeURIComponent(id)
const base = (personId: string) => `/api/v1/admin/people/${idPath(personId)}/interactions` as const

/** GET .../interactions: newest first by when it happened, a bounded page at a time. The order is the server's. */
export async function listInteractions(input: {
  personId: string
  page: number
  perPage?: number
  signal?: AbortSignal
}): Promise<Result<InteractionsPage>> {
  const params = new URLSearchParams({ page: String(input.page) })
  if (input.perPage !== undefined) params.set('per_page', String(input.perPage))

  const result = await requestJson(
    {
      method: 'GET',
      path: `${base(input.personId)}?${params.toString()}`,
      authenticated: true,
      ...(input.signal && { signal: input.signal }),
    },
    (
      body,
    ): body is {
      data: WireInteraction[]
      meta: { page: number; per_page: number; total: number; last_page: number }
    } =>
      isRecord(body) &&
      Array.isArray(body.data) &&
      body.data.every(isWireInteraction) &&
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
      interactions: data.map(interactionFrom),
      page: meta.page,
      perPage: meta.per_page,
      total: meta.total,
      lastPage: meta.last_page,
    },
  }
}

async function one(request: Promise<Result<WireInteraction>>): Promise<Result<Interaction>> {
  const result = await request
  return result.ok ? { ok: true, value: interactionFrom(result.value) } : result
}

/**
 * POST .../interactions. The author is the signed-in caller, taken by the server: it is not, and cannot be, sent. `kind`
 * and `occurredAt` are optional: the server defaults them to a note and to now.
 */
export function recordInteraction(
  personId: string,
  input: { body: string; kind?: InteractionKind; occurredAt?: string },
): Promise<Result<Interaction>> {
  return one(
    requestJson(
      {
        method: 'POST',
        path: base(personId),
        authenticated: true,
        body: {
          body: input.body,
          ...(input.kind !== undefined && { kind: input.kind }),
          ...(input.occurredAt !== undefined && { occurred_at: input.occurredAt }),
        },
      },
      isWireInteraction,
    ),
  )
}

export interface InteractionChanges {
  kind?: InteractionKind
  body?: string
  occurredAt?: string
}

/**
 * PATCH .../interactions/{interaction}: a field that is sent is changed and one that is not is left alone. The Person and the
 * author never change, so neither can be sent.
 */
export function editInteraction(
  personId: string,
  interactionId: string,
  changes: InteractionChanges,
): Promise<Result<Interaction>> {
  return one(
    requestJson(
      {
        method: 'PATCH',
        path: `${base(personId)}/${idPath(interactionId)}`,
        authenticated: true,
        body: {
          ...(changes.kind !== undefined && { kind: changes.kind }),
          ...(changes.body !== undefined && { body: changes.body }),
          ...(changes.occurredAt !== undefined && { occurred_at: changes.occurredAt }),
        },
      },
      isWireInteraction,
    ),
  )
}

/** DELETE .../interactions/{interaction}: removed outright. There is no history to restore it from. */
export function removeInteraction(personId: string, interactionId: string): Promise<Result<null>> {
  return requestVoid({
    method: 'DELETE',
    path: `${base(personId)}/${idPath(interactionId)}`,
    authenticated: true,
  })
}
