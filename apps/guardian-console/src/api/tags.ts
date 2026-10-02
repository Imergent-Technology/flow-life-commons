// The CRM tag endpoints the Console uses (openapi/openapi.yaml is the contract, ADR 0034). A tag is a LABEL a Guardian creates:
// it carries no authority, Membership or Volunteer meaning, so nothing here (or anywhere in the Console) reads a tag's name to
// decide anything. There is deliberately no list of tag names in the Console: the vocabulary is whatever the server holds.

import { requestJson, requestVoid, type Result } from './http.ts'

export interface TagRef {
  id: string
  name: string
}

/** A tag in the vocabulary, with how many People hold it. */
export interface Tag extends TagRef {
  personCount: number
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null

export const isTagRef = (value: unknown): value is TagRef =>
  isRecord(value) && isString(value.id) && isString(value.name)

interface WireTag {
  id: string
  name: string
  person_count: number
}

const isWireTag = (value: unknown): value is WireTag =>
  isRecord(value) && isTagRef(value) && typeof value.person_count === 'number'

const tagFrom = (wire: WireTag): Tag => ({
  id: wire.id,
  name: wire.name,
  personCount: wire.person_count,
})

const idPath = (id: string) => encodeURIComponent(id)

/** GET /admin/contact-tags: the whole vocabulary, by name, each with how many People hold it. */
export async function listTags(signal?: AbortSignal): Promise<Result<Tag[]>> {
  const result = await requestJson(
    {
      method: 'GET',
      path: '/api/v1/admin/contact-tags',
      authenticated: true,
      ...(signal && { signal }),
    },
    (body): body is { data: WireTag[] } =>
      isRecord(body) && Array.isArray(body.data) && body.data.every(isWireTag),
  )
  return result.ok ? { ok: true, value: result.value.data.map(tagFrom) } : result
}

async function oneTag(request: Promise<Result<WireTag>>): Promise<Result<Tag>> {
  const result = await request
  return result.ok ? { ok: true, value: tagFrom(result.value) } : result
}

/** POST /admin/contact-tags. A name that matches an existing tag (ignoring case and spacing) is `409 duplicate_tag`. */
export function createTag(name: string): Promise<Result<Tag>> {
  return oneTag(
    requestJson(
      { method: 'POST', path: '/api/v1/admin/contact-tags', authenticated: true, body: { name } },
      isWireTag,
    ),
  )
}

/** PATCH /admin/contact-tags/{tag}: renames the label; every Person who holds it keeps it under the new name. */
export function renameTag(tagId: string, name: string): Promise<Result<Tag>> {
  return oneTag(
    requestJson(
      {
        method: 'PATCH',
        path: `/api/v1/admin/contact-tags/${idPath(tagId)}`,
        authenticated: true,
        body: { name },
      },
      isWireTag,
    ),
  )
}

/** DELETE /admin/contact-tags/{tag}. Refused with `409 tag_in_use` while any Person holds it: assignments are never removed for it. */
export function deleteTag(tagId: string): Promise<Result<null>> {
  return requestVoid({
    method: 'DELETE',
    path: `/api/v1/admin/contact-tags/${idPath(tagId)}`,
    authenticated: true,
  })
}

/**
 * PUT /admin/people/{person}/tags: makes the Person's tags EXACTLY this set (an empty list clears them). The server returns
 * what the Person holds afterwards.
 */
export async function setPersonTags(
  personId: string,
  tagIds: readonly string[],
): Promise<Result<TagRef[]>> {
  const result = await requestJson(
    {
      method: 'PUT',
      path: `/api/v1/admin/people/${idPath(personId)}/tags`,
      authenticated: true,
      body: { tag_ids: tagIds },
    },
    (body): body is { data: TagRef[] } =>
      isRecord(body) && Array.isArray(body.data) && body.data.every(isTagRef),
  )
  return result.ok
    ? { ok: true, value: result.value.data.map((tag) => ({ id: tag.id, name: tag.name })) }
    : result
}
