// The Resource LIBRARY endpoints (openapi/openapi.yaml, ADR 0037): what a Guardian may read, as the platform's one delivery projection
// has already decided it. This is the CONSUMING side; the authoring side is `resources.ts`. Nothing here re-implements a visibility
// rule: which Packs and Cards a viewer sees, how they are numbered and what a count includes are the server's, and a Pack or Card
// that is not in an answer does not exist as far as this module (and the screens above it) can tell.
//
// A file is never located here: the answer names the library path that serves it, and only a path under the library's own prefix is
// ever used. No storage key, disk or filesystem path exists in any response, so none exists in these types.

import { requestJson, type Result } from './http.ts'
import type { CategoryRef } from './resources.ts'

export type DeliveredCardType = 'basic' | 'external_link' | 'file'

export interface LibraryPackEntry {
  id: string
  title: string
  summary: string | null
  isSeries: boolean
  /** How many Cards THIS viewer can see in it. Never a count that includes a hidden one. */
  cardCount: number
}

export interface LibraryCategory {
  category: CategoryRef
  packs: LibraryPackEntry[]
}

export interface DeliveredFile {
  name: string
  mediaType: string
  byteSize: number
  /** The library path that serves this file to this viewer, or null if the answer named anything else (it is then not offered). */
  downloadPath: string | null
}

export interface DeliveredCard {
  id: string
  /** Its place among the Cards this viewer can see, 1..n. A stored position never reaches the Console. */
  index: number
  type: DeliveredCardType
  title: string
  summary: string
  uri: string | null
  file: DeliveredFile | null
  /** The server's canonical document. It is checked by the renderer, never trusted here. */
  document: unknown
}

export interface DeliveredPack {
  id: string
  title: string
  summary: string | null
  isSeries: boolean
  category: CategoryRef
  /** At least one Card, in the order this viewer sees them. */
  cards: DeliveredCard[]
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isNumber = (value: unknown): value is number => typeof value === 'number'
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null
const isNullableString = (value: unknown): value is string | null =>
  value === null || typeof value === 'string'
const isCategoryRef = (value: unknown): value is CategoryRef =>
  isRecord(value) && isString(value.id) && isString(value.name)

const LIBRARY_FILE = /^\/api\/v1\/admin\/resource-library\/packs\/[^/?#]+\/cards\/[^/?#]+\/file$/

/** A file path is used only if it is the library's own file route. Anything else (a management path, another host) is not offered. */
function libraryFilePath(path: string): string | null {
  return LIBRARY_FILE.test(path) ? path : null
}

interface WireListedPack {
  id: string
  title: string
  summary: string | null
  is_series: boolean
  card_count: number
}
const isWireListedPack = (value: unknown): value is WireListedPack =>
  isRecord(value) &&
  isString(value.id) &&
  isString(value.title) &&
  isNullableString(value.summary) &&
  typeof value.is_series === 'boolean' &&
  isNumber(value.card_count)

interface WireGroup {
  category: CategoryRef
  packs: WireListedPack[]
}
const isWireGroup = (value: unknown): value is WireGroup =>
  isRecord(value) &&
  isCategoryRef(value.category) &&
  Array.isArray(value.packs) &&
  value.packs.every(isWireListedPack)

export interface LibraryQuery {
  /** A Category id, or none for every one. */
  category?: string
  /** Text matched by the server against titles and summaries of what this viewer can see. */
  query?: string
  signal?: AbortSignal
}

/** GET /admin/resource-library: Categories in order, each with the Packs this viewer may see in it. Not paged. */
export async function browseLibrary(query: LibraryQuery = {}): Promise<Result<LibraryCategory[]>> {
  const params = new URLSearchParams()
  if (query.category !== undefined && query.category !== '') params.set('category', query.category)
  if (query.query !== undefined && query.query !== '') params.set('q', query.query)
  const suffix = params.size === 0 ? '' : `?${params.toString()}`

  const result = await requestJson(
    {
      method: 'GET',
      path: `/api/v1/admin/resource-library${suffix}`,
      authenticated: true,
      ...(query.signal && { signal: query.signal }),
    },
    (body): body is { data: WireGroup[] } =>
      isRecord(body) && Array.isArray(body.data) && body.data.every(isWireGroup),
  )
  if (!result.ok) return result
  return {
    ok: true,
    value: result.value.data.map((group) => ({
      category: { id: group.category.id, name: group.category.name },
      packs: group.packs.map((pack) => ({
        id: pack.id,
        title: pack.title,
        summary: pack.summary,
        isSeries: pack.is_series,
        cardCount: pack.card_count,
      })),
    })),
  }
}

interface WireDeliveredFile {
  name: string
  media_type: string
  byte_size: number
  download_path: string
}
const isWireDeliveredFile = (value: unknown): value is WireDeliveredFile =>
  isRecord(value) &&
  isString(value.name) &&
  isString(value.media_type) &&
  isNumber(value.byte_size) &&
  isString(value.download_path)

const CARD_TYPES: readonly unknown[] = ['basic', 'external_link', 'file']

interface WireDeliveredCard {
  id: string
  index: number
  type: DeliveredCardType
  title: string
  summary: string
  uri: string | null
  file: unknown
  content: { document: unknown }
}
const isWireDeliveredCard = (value: unknown): value is WireDeliveredCard =>
  isRecord(value) &&
  isString(value.id) &&
  isNumber(value.index) &&
  CARD_TYPES.includes(value.type) &&
  isString(value.title) &&
  isString(value.summary) &&
  isNullableString(value.uri) &&
  (value.file === null || isWireDeliveredFile(value.file)) &&
  isRecord(value.content) &&
  'document' in value.content

interface WireDeliveredPack {
  id: string
  title: string
  summary: string | null
  is_series: boolean
  category: CategoryRef
  cards: WireDeliveredCard[]
}
const isWireDeliveredPack = (value: unknown): value is WireDeliveredPack =>
  isRecord(value) &&
  isString(value.id) &&
  isString(value.title) &&
  isNullableString(value.summary) &&
  typeof value.is_series === 'boolean' &&
  isCategoryRef(value.category) &&
  Array.isArray(value.cards) &&
  value.cards.length > 0 &&
  value.cards.every(isWireDeliveredCard)

/**
 * GET /admin/resource-library/packs/{pack}: the Pack with only the Cards this viewer may see, in their order. A Pack that is missing,
 * a Draft, unpublished, not for this viewer or empty for them is one and the same `404 resource_pack_not_found`.
 */
export async function getLibraryPack(
  packId: string,
  signal?: AbortSignal,
): Promise<Result<DeliveredPack>> {
  const result = await requestJson(
    {
      method: 'GET',
      path: `/api/v1/admin/resource-library/packs/${encodeURIComponent(packId)}`,
      authenticated: true,
      ...(signal && { signal }),
    },
    isWireDeliveredPack,
  )
  if (!result.ok) return result
  const wire = result.value
  return {
    ok: true,
    value: {
      id: wire.id,
      title: wire.title,
      summary: wire.summary,
      isSeries: wire.is_series,
      category: { id: wire.category.id, name: wire.category.name },
      cards: wire.cards.map((card) => {
        const file = card.file as WireDeliveredFile | null
        return {
          id: card.id,
          index: card.index,
          type: card.type,
          title: card.title,
          summary: card.summary,
          uri: card.uri,
          file:
            file === null
              ? null
              : {
                  name: file.name,
                  mediaType: file.media_type,
                  byteSize: file.byte_size,
                  downloadPath: libraryFilePath(file.download_path),
                },
          document: card.content.document,
        }
      }),
    },
  }
}
