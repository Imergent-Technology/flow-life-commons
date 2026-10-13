// The Resource LIBRARY endpoints (openapi/openapi.yaml, ADR 0037 and ADR 0038 C7, R4-R14): two readers, one client for each, and
// no way to mistake one for the other. This is the CONSUMING side; the authoring side is `resources.ts`.
//
//   - The VIEWER client reads policy A, `GET /admin/resource-library*`, for an Actor who holds `resources.view`: every Pack and
//     Card, Drafts included, every audience, with the publication state, the audiences and the last editor as DATA.
//   - The AUDIENCE client reads policy C, `GET /admin/my-resources*`, for any Console user: only what the signed-in Person is
//     eligible for, as the server's projection has decided it, with no state, audience, revision or provenance at all.
//
// They share nothing but the pure pieces (the audience catalog, the shape of a Card as it is read). Each has its own wire
// validators, its own types and its own file-path allowlist. The audience parser REFUSES a body that carries a privileged field
// (a Draft marker, an audience list, an editor), and the viewer parser REFUSES one that lacks them, so neither can take the other's
// answer for its own. Nothing here re-implements a visibility rule: which Packs and Cards are seen, how they are numbered and what a
// count includes are the server's, and a Pack or Card that is not in an answer does not exist as far as this module can tell.
//
// A file is never located here: the answer names the API path that serves it, and a path is used only if it is exactly the file
// route of THIS client's family for THAT Pack and Card. Anything else (a management path, the other reader's path, another host, an
// absolute URL, a path for a different Card) is not offered. No storage key, disk or filesystem path exists in any response, so none
// exists in these types.
//
// A 403 from the viewer client means the capability behind it is gone, and the screens act on it (ADR 0038, C1). A file is requested by
// an `<img>` or a link, never by this module, so a refused file is invisible to it and is not a signal of anything.

import { isAudienceList, type Audience } from './audiences.ts'
import { requestJson, type Result } from './http.ts'
import type { CategoryRef } from './resources.ts'

// -- What both readers share ----------------------------------------------------------------------------------------------------

export type ReadingCardType = 'basic' | 'external_link' | 'file'

export interface ReadingFile {
  name: string
  mediaType: string
  byteSize: number
  /** The API path that serves this file to this reader, or null if the answer named anything else (it is then not offered). */
  downloadPath: string | null
}

/**
 * What the reader of a Card needs, and nothing more: no state, no audience, no editor. Both readers' Cards are given to the shared
 * reading components as this, so the components cannot show what they were never handed.
 */
export interface ReadingCard {
  id: string
  /** Its place among the Cards this reader is shown, 1..n. A stored position never reaches the Console. */
  index: number
  type: ReadingCardType
  title: string
  summary: string
  uri: string | null
  file: ReadingFile | null
  /** The server's canonical document. It is checked by the renderer, never trusted here. */
  document: unknown
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isNumber = (value: unknown): value is number => typeof value === 'number'
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
const isNullableString = (value: unknown): value is string | null =>
  value === null || typeof value === 'string'
const isCategoryRef = (value: unknown): value is CategoryRef =>
  isRecord(value) && isString(value.id) && isString(value.name)

const CARD_TYPES: readonly unknown[] = ['basic', 'external_link', 'file']
const STATES: readonly unknown[] = ['draft', 'published']
const AUDIENCE_MODES: readonly unknown[] = ['inherit', 'narrowed']

interface WireFile {
  name: string
  media_type: string
  byte_size: number
  download_path: string
}
const isWireFile = (value: unknown): value is WireFile =>
  isRecord(value) &&
  isString(value.name) &&
  isString(value.media_type) &&
  isNumber(value.byte_size) &&
  isString(value.download_path)

interface WireReadingCard {
  id: string
  index: number
  type: ReadingCardType
  title: string
  summary: string
  uri: string | null
  file: WireFile | null
  content: { document: unknown }
}
const isWireReadingCard = (value: unknown): value is WireReadingCard =>
  isRecord(value) &&
  isString(value.id) &&
  isNumber(value.index) &&
  CARD_TYPES.includes(value.type) &&
  isString(value.title) &&
  isString(value.summary) &&
  isNullableString(value.uri) &&
  (value.file === null || isWireFile(value.file)) &&
  isRecord(value.content) &&
  'document' in value.content

/** The file route of one reader family, for exactly this Pack and this Card. Anything else is no file route of ours. */
function expectedFilePath(family: string, packId: string, cardId: string): string {
  return `/api/v1/admin/${family}/packs/${encodeURIComponent(packId)}/cards/${encodeURIComponent(cardId)}/file`
}

function readingCardFrom(card: WireReadingCard, family: string, packId: string): ReadingCard {
  const file = card.file
  const allowed = expectedFilePath(family, packId, card.id)
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
            downloadPath: file.download_path === allowed ? allowed : null,
          },
    document: card.content.document,
  }
}

const hasAny = (value: Record<string, unknown>, keys: readonly string[]): boolean =>
  keys.some((key) => key in value)

// -- The AUDIENCE reader: policy C, `/admin/my-resources` ---------------------------------------------------------------------------

const AUDIENCE_FAMILY = 'my-resources'

/**
 * Fields that belong to the privileged reader or to management, and that policy C never carries (ADR 0038, R11, R14). An answer that
 * has one is not the audience contract, so it is refused rather than quietly stripped: the audience reader is never shown a Draft
 * marker, an audience list or an editor, and a server that began to send them would be a defect to see, not to hide.
 */
const PRIVILEGED_FIELDS: readonly string[] = [
  'state',
  'audiences',
  'audience_mode',
  'draft_card_count',
  'revision',
  'position',
  'updated_at',
  'updated_by',
  'created_by',
]

export interface AudiencePackEntry {
  id: string
  title: string
  summary: string | null
  isSeries: boolean
  /** How many Cards THIS reader can see in it. Never a count that includes a hidden one. */
  cardCount: number
}

export interface AudienceCategory {
  category: CategoryRef
  packs: AudiencePackEntry[]
}

export interface AudiencePack {
  id: string
  title: string
  summary: string | null
  isSeries: boolean
  category: CategoryRef
  /** At least one Card, in the order this reader sees them. */
  cards: ReadingCard[]
}

export interface AudienceLibraryQuery {
  /** A Category id, or none for every one. */
  category?: string
  /** Text matched by the server against titles and summaries of what this reader can see. */
  query?: string
  signal?: AbortSignal
}

interface WireAudienceListedPack {
  id: string
  title: string
  summary: string | null
  is_series: boolean
  card_count: number
}
const isWireAudienceListedPack = (value: unknown): value is WireAudienceListedPack =>
  isRecord(value) &&
  !hasAny(value, PRIVILEGED_FIELDS) &&
  isString(value.id) &&
  isString(value.title) &&
  isNullableString(value.summary) &&
  typeof value.is_series === 'boolean' &&
  isNumber(value.card_count)

interface WireAudienceGroup {
  category: CategoryRef
  packs: WireAudienceListedPack[]
}
const isWireAudienceGroup = (value: unknown): value is WireAudienceGroup =>
  isRecord(value) &&
  isCategoryRef(value.category) &&
  Array.isArray(value.packs) &&
  value.packs.every(isWireAudienceListedPack)

/** GET /admin/my-resources: Categories in order, each with the Packs this reader may see in it. Not paged. */
export async function browseAudienceLibrary(
  query: AudienceLibraryQuery = {},
): Promise<Result<AudienceCategory[]>> {
  const params = new URLSearchParams()
  if (query.category !== undefined && query.category !== '') params.set('category', query.category)
  if (query.query !== undefined && query.query !== '') params.set('q', query.query)
  const suffix = params.size === 0 ? '' : `?${params.toString()}`

  const result = await requestJson(
    {
      method: 'GET',
      path: `/api/v1/admin/my-resources${suffix}`,
      authenticated: true,
      ...(query.signal && { signal: query.signal }),
    },
    (body): body is { data: WireAudienceGroup[] } =>
      isRecord(body) && Array.isArray(body.data) && body.data.every(isWireAudienceGroup),
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

const isWireAudienceCard = (value: unknown): value is WireReadingCard =>
  isRecord(value) && !hasAny(value, PRIVILEGED_FIELDS) && isWireReadingCard(value)

interface WireAudiencePack {
  id: string
  title: string
  summary: string | null
  is_series: boolean
  category: CategoryRef
  cards: WireReadingCard[]
}
const isWireAudiencePack = (value: unknown): value is WireAudiencePack =>
  isRecord(value) &&
  !hasAny(value, PRIVILEGED_FIELDS) &&
  isString(value.id) &&
  isString(value.title) &&
  isNullableString(value.summary) &&
  typeof value.is_series === 'boolean' &&
  isCategoryRef(value.category) &&
  Array.isArray(value.cards) &&
  value.cards.length > 0 &&
  value.cards.every(isWireAudienceCard)

/**
 * GET /admin/my-resources/packs/{pack}: the Pack with only the Cards this reader may see, in their order. A Pack that is missing, a
 * Draft, unpublished, not for this reader or empty for them is one and the same `404 resource_pack_not_found`.
 */
export async function getAudiencePack(
  packId: string,
  signal?: AbortSignal,
): Promise<Result<AudiencePack>> {
  const result = await requestJson(
    {
      method: 'GET',
      path: `/api/v1/admin/my-resources/packs/${encodeURIComponent(packId)}`,
      authenticated: true,
      ...(signal && { signal }),
    },
    isWireAudiencePack,
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
      cards: wire.cards.map((card) => readingCardFrom(card, AUDIENCE_FAMILY, wire.id)),
    },
  }
}

// -- The VIEWER reader: policy A, `/admin/resource-library`, behind `resources.view` ------------------------------------------------

const VIEWER_FAMILY = 'resource-library'

export type ViewerPublication = 'published' | 'draft'
export type ViewerPublicationFilter = ViewerPublication
export type ViewerAudienceMode = 'inherit' | 'narrowed'

/** Who last edited, as a name. Never an id: `resources.view` needs a name, and no more (ADR 0038, R7). */
export interface ViewerEditor {
  displayName: string | null
}

export interface ViewerPackEntry {
  id: string
  title: string
  summary: string | null
  isSeries: boolean
  state: ViewerPublication
  /** Whom the Pack is aimed at. A label of what was authored, not of who this viewer is. */
  audiences: Audience[]
  /** Every Card, Drafts included. */
  cardCount: number
  draftCardCount: number
  updatedAt: string
  editor: ViewerEditor
}

/** A group of the viewer's library. `category` is null for the Packs that have none, listed last. */
export interface ViewerCategory {
  category: CategoryRef | null
  packs: ViewerPackEntry[]
}

export interface ViewerCard {
  id: string
  index: number
  type: ReadingCardType
  title: string
  summary: string
  uri: string | null
  file: ReadingFile | null
  document: unknown
  state: ViewerPublication
  audienceMode: ViewerAudienceMode
  /** The audiences this Card is effectively aimed at: its Pack's, or the narrower set it chose. */
  audiences: Audience[]
  updatedAt: string
  editor: ViewerEditor
}

export interface ViewerPack {
  id: string
  title: string
  summary: string | null
  isSeries: boolean
  category: CategoryRef | null
  state: ViewerPublication
  audiences: Audience[]
  cardCount: number
  draftCardCount: number
  updatedAt: string
  editor: ViewerEditor
  /** Every Card in the server's order, Drafts included. May be none: an empty Pack is a Pack. */
  cards: ViewerCard[]
}

export interface ViewerLibraryQuery {
  /** A Category id, or none for every one. */
  category?: string
  /** Text matched by the server against titles and summaries of a Pack and ALL its Cards, Drafts included. */
  query?: string
  /** `published`, or `draft` (Draft Packs and Published Packs that hold a Draft Card). Narrows only. */
  publication?: ViewerPublicationFilter
  /** The Packs aimed at this audience. A FILTER on the viewer's own answer; it never decides what the viewer may read. */
  audience?: Audience
  signal?: AbortSignal
}

interface WireEditor {
  display_name: string | null
}
const isWireEditor = (value: unknown): value is WireEditor =>
  isRecord(value) && isNullableString(value.display_name)

interface WireViewerListedPack {
  id: string
  title: string
  summary: string | null
  is_series: boolean
  state: ViewerPublication
  audiences: Audience[]
  card_count: number
  draft_card_count: number
  updated_at: string
  updated_by: WireEditor
}
const isWireViewerListedPack = (value: unknown): value is WireViewerListedPack =>
  isRecord(value) &&
  isString(value.id) &&
  isString(value.title) &&
  isNullableString(value.summary) &&
  typeof value.is_series === 'boolean' &&
  STATES.includes(value.state) &&
  isAudienceList(value.audiences) &&
  isNumber(value.card_count) &&
  isNumber(value.draft_card_count) &&
  isString(value.updated_at) &&
  isWireEditor(value.updated_by)

interface WireViewerGroup {
  category: CategoryRef | null
  packs: WireViewerListedPack[]
}
const isWireViewerGroup = (value: unknown): value is WireViewerGroup =>
  isRecord(value) &&
  (value.category === null || isCategoryRef(value.category)) &&
  Array.isArray(value.packs) &&
  value.packs.every(isWireViewerListedPack)

/**
 * GET /admin/resource-library: Categories in order, each with every Pack of it, then the Packs that have none under `category: null`.
 * Needs `resources.view`; without it the platform answers 403, which the screens follow (ADR 0038, C1). The filters only narrow.
 */
export async function browseViewerLibrary(
  query: ViewerLibraryQuery = {},
): Promise<Result<ViewerCategory[]>> {
  const params = new URLSearchParams()
  if (query.category !== undefined && query.category !== '') params.set('category', query.category)
  if (query.query !== undefined && query.query !== '') params.set('q', query.query)
  if (query.publication !== undefined) params.set('publication', query.publication)
  if (query.audience !== undefined) params.set('audience', query.audience)
  const suffix = params.size === 0 ? '' : `?${params.toString()}`

  const result = await requestJson(
    {
      method: 'GET',
      path: `/api/v1/admin/resource-library${suffix}`,
      authenticated: true,
      ...(query.signal && { signal: query.signal }),
    },
    (body): body is { data: WireViewerGroup[] } =>
      isRecord(body) && Array.isArray(body.data) && body.data.every(isWireViewerGroup),
  )
  if (!result.ok) return result
  return {
    ok: true,
    value: result.value.data.map((group) => ({
      category:
        group.category === null ? null : { id: group.category.id, name: group.category.name },
      packs: group.packs.map((pack) => ({
        id: pack.id,
        title: pack.title,
        summary: pack.summary,
        isSeries: pack.is_series,
        state: pack.state,
        audiences: pack.audiences,
        cardCount: pack.card_count,
        draftCardCount: pack.draft_card_count,
        updatedAt: pack.updated_at,
        editor: { displayName: pack.updated_by.display_name },
      })),
    })),
  }
}

interface WireViewerCard extends WireReadingCard {
  state: ViewerPublication
  audience_mode: ViewerAudienceMode
  audiences: Audience[]
  updated_at: string
  updated_by: WireEditor
}
const isWireViewerCard = (value: unknown): value is WireViewerCard =>
  isWireReadingCard(value) &&
  isRecord(value) &&
  STATES.includes(value.state) &&
  AUDIENCE_MODES.includes(value.audience_mode) &&
  isAudienceList(value.audiences) &&
  isString(value.updated_at) &&
  isWireEditor(value.updated_by)

interface WireViewerPack extends WireViewerListedPack {
  category: CategoryRef | null
  cards: WireViewerCard[]
}
const isWireViewerPack = (value: unknown): value is WireViewerPack =>
  isWireViewerListedPack(value) &&
  isRecord(value) &&
  (value.category === null || isCategoryRef(value.category)) &&
  Array.isArray(value.cards) &&
  value.cards.every(isWireViewerCard)

/**
 * GET /admin/resource-library/packs/{pack}: the Pack with EVERY Card, Drafts included, numbered 1..n across all of them. Only a Pack
 * that does not exist (or a Card that is not in it) is a 404; nothing is hidden for being a Draft.
 */
export async function getViewerPack(
  packId: string,
  signal?: AbortSignal,
): Promise<Result<ViewerPack>> {
  const result = await requestJson(
    {
      method: 'GET',
      path: `/api/v1/admin/resource-library/packs/${encodeURIComponent(packId)}`,
      authenticated: true,
      ...(signal && { signal }),
    },
    isWireViewerPack,
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
      category: wire.category === null ? null : { id: wire.category.id, name: wire.category.name },
      state: wire.state,
      audiences: wire.audiences,
      cardCount: wire.card_count,
      draftCardCount: wire.draft_card_count,
      updatedAt: wire.updated_at,
      editor: { displayName: wire.updated_by.display_name },
      cards: wire.cards.map((card) => ({
        ...readingCardFrom(card, VIEWER_FAMILY, wire.id),
        state: card.state,
        audienceMode: card.audience_mode,
        audiences: card.audiences,
        updatedAt: card.updated_at,
        editor: { displayName: card.updated_by.display_name },
      })),
    },
  }
}
