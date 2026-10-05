// The Resources management endpoints the Console uses (openapi/openapi.yaml is the contract, ADR 0037). Management is the
// authoring surface: Categories, Resource Packs and their Cards, audiences, publication, ordering and the one managed file a
// File Card owns. It is NOT the library a Guardian reads (a later package), and it re-implements none of the server's rules:
// what is visible to whom, what may be published and what may be deleted are decided by the server, which this only asks.
//
// A file is never located here: the API describes it (name, type, size) and names the management path that serves it. No
// storage key, disk or filesystem path exists in any response, so none exists in these types.

import { requestJson, requestVoid, type Result } from './http.ts'

export type Audience = 'guardian' | 'member'
export const AUDIENCES: readonly Audience[] = ['guardian', 'member']

/** The audience a preview starts on: the one the Console itself delivers to. */
export const DEFAULT_PREVIEW_AUDIENCE: Audience = 'guardian'

export type PublicationState = 'draft' | 'published'
export type CardType = 'basic' | 'external_link' | 'file'
export type SummaryMode = 'derived' | 'custom'
export type AudienceMode = 'inherit' | 'narrowed'

export interface PersonRef {
  id: string
  displayName: string | null
}

export interface CategoryRef {
  id: string
  name: string
}

export interface ResourceCategory extends CategoryRef {
  position: number
  packCount: number
}

export interface ResourceFile {
  name: string
  mediaType: string
  byteSize: number
}

/** A File Card's file for management: also whether the store holds it now, who uploaded it, and the path that serves it. */
export interface ManagedFile extends ResourceFile {
  uploadedBy: PersonRef
  uploadedAt: string
  /** False after, say, a partial restore: the row is there and the bytes are not, and a download answers `asset_unavailable`. */
  available: boolean
  /** The API path (no host) of the MANAGEMENT download: it serves Drafts, which the library route does not. */
  downloadPath: string
}

export interface ManagedCardOutline {
  id: string
  packId: string
  type: CardType
  title: string
  summaryMode: SummaryMode
  summary: string
  uri: string | null
  file: ResourceFile | null
  audienceMode: AudienceMode
  audiences: Audience[]
  state: PublicationState
  revision: number
  updatedBy: PersonRef
  updatedAt: string
}

/** A Card with its content, for editing. `document` is the server's canonical document: it is parsed by the editor, never trusted here. */
export interface ManagedCard extends Omit<ManagedCardOutline, 'file'> {
  file: ManagedFile | null
  content: { format: string; version: number; document: unknown }
}

export interface ManagedPack {
  id: string
  title: string
  summary: string | null
  isSeries: boolean
  category: CategoryRef | null
  state: PublicationState
  revision: number
  audiences: Audience[]
  cardCount: number
  publishedCardCount: number
  createdBy: PersonRef
  updatedBy: PersonRef
  updatedAt: string
  /** Every Card, Drafts included, in the server's order, without content. Present when one Pack is shown, absent from a list. */
  cards: ManagedCardOutline[] | null
}

export interface PackPage {
  packs: ManagedPack[]
  page: number
  lastPage: number
  total: number
}

export interface PackPreview {
  audience: Audience
  packState: PublicationState
  audienceTargeted: boolean
  visible: boolean
  pack: PreviewPack | null
}

/** What an audience would receive: the Pack and the Cards it could see, in the order it would see them. Content is not carried here. */
export interface PreviewPack {
  id: string
  title: string
  summary: string | null
  isSeries: boolean
  category: CategoryRef
  cards: { id: string; index: number; type: CardType; title: string; summary: string }[]
}

// --- Wire shapes and the checks that make them trustworthy ----------------------------------------------------------------

const isString = (value: unknown): value is string => typeof value === 'string'
const isNumber = (value: unknown): value is number => typeof value === 'number'
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null
const isNullableString = (value: unknown): value is string | null =>
  value === null || typeof value === 'string'

const STATES: readonly unknown[] = ['draft', 'published']
const TYPES: readonly unknown[] = ['basic', 'external_link', 'file']

const isAudience = (value: unknown): value is Audience => value === 'guardian' || value === 'member'
const isAudienceList = (value: unknown): value is Audience[] =>
  Array.isArray(value) && value.every(isAudience)

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

const isCategoryRef = (value: unknown): value is CategoryRef =>
  isRecord(value) && isString(value.id) && isString(value.name)

interface WireCategory {
  id: string
  name: string
  position: number
  pack_count: number
}
const isWireCategory = (value: unknown): value is WireCategory =>
  isRecord(value) &&
  isString(value.id) &&
  isString(value.name) &&
  isNumber(value.position) &&
  isNumber(value.pack_count)
const categoryFrom = (wire: WireCategory): ResourceCategory => ({
  id: wire.id,
  name: wire.name,
  position: wire.position,
  packCount: wire.pack_count,
})

interface WireFileSummary {
  name: string
  media_type: string
  byte_size: number
}
const isWireFileSummary = (value: unknown): value is WireFileSummary =>
  isRecord(value) && isString(value.name) && isString(value.media_type) && isNumber(value.byte_size)

interface WireManagedFile extends WireFileSummary {
  uploaded_by: WirePerson
  uploaded_at: string
  available: boolean
  download_path: string
}
const isWireManagedFile = (value: unknown): value is WireManagedFile =>
  isWireFileSummary(value) &&
  isRecord(value) &&
  isWirePerson(value.uploaded_by) &&
  isString(value.uploaded_at) &&
  typeof value.available === 'boolean' &&
  isString(value.download_path)

const fileFrom = (wire: WireFileSummary): ResourceFile => ({
  name: wire.name,
  mediaType: wire.media_type,
  byteSize: wire.byte_size,
})
const managedFileFrom = (wire: WireManagedFile): ManagedFile => ({
  ...fileFrom(wire),
  uploadedBy: personFrom(wire.uploaded_by),
  uploadedAt: wire.uploaded_at,
  available: wire.available,
  downloadPath: wire.download_path,
})

interface WireCardOutline {
  id: string
  pack_id: string
  type: CardType
  title: string
  summary_mode: SummaryMode
  summary: string
  uri: string | null
  file: unknown
  audience_mode: AudienceMode
  audiences: Audience[]
  state: PublicationState
  revision: number
  updated_by: WirePerson
  updated_at: string
}
const isWireCardOutline = (value: unknown): value is WireCardOutline =>
  isRecord(value) &&
  isString(value.id) &&
  isString(value.pack_id) &&
  TYPES.includes(value.type) &&
  isString(value.title) &&
  (value.summary_mode === 'derived' || value.summary_mode === 'custom') &&
  isString(value.summary) &&
  isNullableString(value.uri) &&
  (value.file === null || isWireFileSummary(value.file)) &&
  (value.audience_mode === 'inherit' || value.audience_mode === 'narrowed') &&
  isAudienceList(value.audiences) &&
  STATES.includes(value.state) &&
  isNumber(value.revision) &&
  isWirePerson(value.updated_by) &&
  isString(value.updated_at)

const outlineFrom = (wire: WireCardOutline): ManagedCardOutline => ({
  id: wire.id,
  packId: wire.pack_id,
  type: wire.type,
  title: wire.title,
  summaryMode: wire.summary_mode,
  summary: wire.summary,
  uri: wire.uri,
  file: wire.file === null ? null : fileFrom(wire.file as WireFileSummary),
  audienceMode: wire.audience_mode,
  audiences: wire.audiences,
  state: wire.state,
  revision: wire.revision,
  updatedBy: personFrom(wire.updated_by),
  updatedAt: wire.updated_at,
})

interface WireCard extends WireCardOutline {
  content: { format: string; version: number; document: unknown }
}
const isWireCard = (value: unknown): value is WireCard =>
  isWireCardOutline(value) &&
  isRecord(value) &&
  isRecord(value.content) &&
  isString(value.content.format) &&
  isNumber(value.content.version) &&
  'document' in value.content &&
  (value.file === null || isWireManagedFile(value.file))

const cardFrom = (wire: WireCard): ManagedCard => ({
  ...outlineFrom(wire),
  file: wire.file === null ? null : managedFileFrom(wire.file as WireManagedFile),
  content: wire.content,
})

interface WirePack {
  id: string
  title: string
  summary: string | null
  is_series: boolean
  category: CategoryRef | null
  state: PublicationState
  revision: number
  audiences: Audience[]
  card_count: number
  published_card_count: number
  created_by: WirePerson
  updated_by: WirePerson
  updated_at: string
  cards?: unknown
}
const isWirePack = (value: unknown): value is WirePack =>
  isRecord(value) &&
  isString(value.id) &&
  isString(value.title) &&
  isNullableString(value.summary) &&
  typeof value.is_series === 'boolean' &&
  (value.category === null || isCategoryRef(value.category)) &&
  STATES.includes(value.state) &&
  isNumber(value.revision) &&
  isAudienceList(value.audiences) &&
  isNumber(value.card_count) &&
  isNumber(value.published_card_count) &&
  isWirePerson(value.created_by) &&
  isWirePerson(value.updated_by) &&
  isString(value.updated_at) &&
  (value.cards === undefined ||
    (Array.isArray(value.cards) && value.cards.every(isWireCardOutline)))

const packFrom = (wire: WirePack): ManagedPack => ({
  id: wire.id,
  title: wire.title,
  summary: wire.summary,
  isSeries: wire.is_series,
  category: wire.category === null ? null : { id: wire.category.id, name: wire.category.name },
  state: wire.state,
  revision: wire.revision,
  audiences: wire.audiences,
  cardCount: wire.card_count,
  publishedCardCount: wire.published_card_count,
  createdBy: personFrom(wire.created_by),
  updatedBy: personFrom(wire.updated_by),
  updatedAt: wire.updated_at,
  cards: Array.isArray(wire.cards) ? (wire.cards as WireCardOutline[]).map(outlineFrom) : null,
})

const idPath = (id: string) => encodeURIComponent(id)
const BASE = '/api/v1/admin/resources'

// --- Categories -------------------------------------------------------------------------------------------------------------

async function categoryList(
  request: Promise<Result<{ data: WireCategory[] }>>,
): Promise<Result<ResourceCategory[]>> {
  const result = await request
  return result.ok ? { ok: true, value: result.value.data.map(categoryFrom) } : result
}

const isCategoryList = (body: unknown): body is { data: WireCategory[] } =>
  isRecord(body) && Array.isArray(body.data) && body.data.every(isWireCategory)

/** GET /admin/resources/categories: every Category in display order. Not paged. */
export function listCategories(signal?: AbortSignal): Promise<Result<ResourceCategory[]>> {
  return categoryList(
    requestJson(
      { method: 'GET', path: `${BASE}/categories`, authenticated: true, ...(signal && { signal }) },
      isCategoryList,
    ),
  )
}

async function oneCategory(
  request: Promise<Result<WireCategory>>,
): Promise<Result<ResourceCategory>> {
  const result = await request
  return result.ok ? { ok: true, value: categoryFrom(result.value) } : result
}

/** POST /admin/resources/categories. A name that matches an existing one (ignoring case and spacing) is `409 duplicate_category`. */
export function createCategory(name: string): Promise<Result<ResourceCategory>> {
  return oneCategory(
    requestJson(
      { method: 'POST', path: `${BASE}/categories`, authenticated: true, body: { name } },
      isWireCategory,
    ),
  )
}

/** PATCH /admin/resources/categories/{category}. */
export function renameCategory(id: string, name: string): Promise<Result<ResourceCategory>> {
  return oneCategory(
    requestJson(
      {
        method: 'PATCH',
        path: `${BASE}/categories/${idPath(id)}`,
        authenticated: true,
        body: { name },
      },
      isWireCategory,
    ),
  )
}

/** DELETE /admin/resources/categories/{category}. Refused `409 category_not_empty` while any Pack holds it, in any state. */
export function deleteCategory(id: string): Promise<Result<null>> {
  return requestVoid({
    method: 'DELETE',
    path: `${BASE}/categories/${idPath(id)}`,
    authenticated: true,
  })
}

/** PUT /admin/resources/categories/order: the COMPLETE ordered list of Category ids. Anything else is `409 order_mismatch`. */
export function reorderCategories(ids: readonly string[]): Promise<Result<ResourceCategory[]>> {
  return categoryList(
    requestJson(
      { method: 'PUT', path: `${BASE}/categories/order`, authenticated: true, body: { ids } },
      isCategoryList,
    ),
  )
}

/** PUT /admin/resources/categories/{category}/pack-order: the COMPLETE ordered list of that Category's Pack ids (all states). */
export async function reorderPacks(
  categoryId: string,
  ids: readonly string[],
): Promise<Result<ManagedPack[]>> {
  const result = await requestJson(
    {
      method: 'PUT',
      path: `${BASE}/categories/${idPath(categoryId)}/pack-order`,
      authenticated: true,
      body: { ids },
    },
    (body): body is { data: WirePack[] } =>
      isRecord(body) && Array.isArray(body.data) && body.data.every(isWirePack),
  )
  return result.ok ? { ok: true, value: result.value.data.map(packFrom) } : result
}

// --- Packs ------------------------------------------------------------------------------------------------------------------

export interface PackFilters {
  page: number
  perPage: number
  category?: string
  audience?: Audience
  state?: PublicationState
  cardType?: CardType
  query?: string
  signal?: AbortSignal
}

/** GET /admin/resources/packs: the server's order (Category, Pack, id; uncategorised last), filtered and paged by the server. */
export async function listPacks(filters: PackFilters): Promise<Result<PackPage>> {
  const query = new URLSearchParams({
    page: String(filters.page),
    per_page: String(filters.perPage),
  })
  if (filters.category !== undefined && filters.category !== '') {
    query.set('category', filters.category)
  }
  if (filters.audience !== undefined) query.set('audience', filters.audience)
  if (filters.state !== undefined) query.set('state', filters.state)
  if (filters.cardType !== undefined) query.set('card_type', filters.cardType)
  if (filters.query !== undefined && filters.query !== '') query.set('q', filters.query)

  const result = await requestJson(
    {
      method: 'GET',
      path: `${BASE}/packs?${query.toString()}`,
      authenticated: true,
      ...(filters.signal && { signal: filters.signal }),
    },
    (
      body,
    ): body is {
      data: WirePack[]
      meta: { page: number; last_page: number; total: number }
    } =>
      isRecord(body) &&
      Array.isArray(body.data) &&
      body.data.every(isWirePack) &&
      isRecord(body.meta) &&
      isNumber(body.meta.page) &&
      isNumber(body.meta.last_page) &&
      isNumber(body.meta.total),
  )
  return result.ok
    ? {
        ok: true,
        value: {
          packs: result.value.data.map(packFrom),
          page: result.value.meta.page,
          lastPage: result.value.meta.last_page,
          total: result.value.meta.total,
        },
      }
    : result
}

async function onePack(request: Promise<Result<WirePack>>): Promise<Result<ManagedPack>> {
  const result = await request
  return result.ok ? { ok: true, value: packFrom(result.value) } : result
}

/** GET /admin/resources/packs/{pack}: the Pack with every one of its Cards (Drafts too), without content. */
export function getPack(id: string, signal?: AbortSignal): Promise<Result<ManagedPack>> {
  return onePack(
    requestJson(
      {
        method: 'GET',
        path: `${BASE}/packs/${idPath(id)}`,
        authenticated: true,
        ...(signal && { signal }),
      },
      isWirePack,
    ),
  )
}

export interface NewPack {
  title: string
  summary: string | null
  isSeries: boolean
  categoryId: string | null
}

/** POST /admin/resources/packs: a new Draft Pack. It starts with no audience and no Card; the server decides what that means. */
export function createPack(pack: NewPack): Promise<Result<ManagedPack>> {
  return onePack(
    requestJson(
      {
        method: 'POST',
        path: `${BASE}/packs`,
        authenticated: true,
        body: {
          title: pack.title,
          summary: pack.summary,
          is_series: pack.isSeries,
          category_id: pack.categoryId,
        },
      },
      isWirePack,
    ),
  )
}

/** The authored fields of a Pack a person changed: only what is present is sent. */
export interface PackChanges {
  title?: string
  summary?: string | null
  isSeries?: boolean
  categoryId?: string | null
}

/** PATCH /admin/resources/packs/{pack}: the revision the edit was based on is required; a stale one is `409 stale_revision`. */
export function updatePack(
  id: string,
  revision: number,
  changes: PackChanges,
): Promise<Result<ManagedPack>> {
  return onePack(
    requestJson(
      {
        method: 'PATCH',
        path: `${BASE}/packs/${idPath(id)}`,
        authenticated: true,
        body: {
          revision,
          ...(changes.title !== undefined && { title: changes.title }),
          ...(changes.summary !== undefined && { summary: changes.summary }),
          ...(changes.isSeries !== undefined && { is_series: changes.isSeries }),
          ...(changes.categoryId !== undefined && { category_id: changes.categoryId }),
        },
      },
      isWirePack,
    ),
  )
}

/** PUT /admin/resources/packs/{pack}/audiences: makes the Pack's audiences EXACTLY this set. */
export function setPackAudiences(
  id: string,
  audiences: readonly Audience[],
): Promise<Result<ManagedPack>> {
  return onePack(
    requestJson(
      {
        method: 'PUT',
        path: `${BASE}/packs/${idPath(id)}/audiences`,
        authenticated: true,
        body: { audiences },
      },
      isWirePack,
    ),
  )
}

/** POST .../publish and .../unpublish: reversible and idempotent, and asking for no recent verification. */
export function setPackPublication(
  id: string,
  action: 'publish' | 'unpublish',
): Promise<Result<ManagedPack>> {
  return onePack(
    requestJson(
      { method: 'POST', path: `${BASE}/packs/${idPath(id)}/${action}`, authenticated: true },
      isWirePack,
    ),
  )
}

/** DELETE /admin/resources/packs/{pack}: PERMANENT, with its Cards and their files. Needs recent verification. */
export function deletePack(id: string): Promise<Result<null>> {
  return requestVoid({ method: 'DELETE', path: `${BASE}/packs/${idPath(id)}`, authenticated: true })
}

/** PUT /admin/resources/packs/{pack}/card-order: the COMPLETE ordered list of the Pack's Card ids. Returns the Pack. */
export function reorderCards(packId: string, ids: readonly string[]): Promise<Result<ManagedPack>> {
  return onePack(
    requestJson(
      {
        method: 'PUT',
        path: `${BASE}/packs/${idPath(packId)}/card-order`,
        authenticated: true,
        body: { ids },
      },
      isWirePack,
    ),
  )
}

/** GET /admin/resources/packs/{pack}/preview: what an audience would receive if the Pack were Published as it stands. */
export async function previewPack(
  packId: string,
  audience: Audience,
  signal?: AbortSignal,
): Promise<Result<PackPreview>> {
  const result = await requestJson(
    {
      method: 'GET',
      path: `${BASE}/packs/${idPath(packId)}/preview?audience=${audience}`,
      authenticated: true,
      ...(signal && { signal }),
    },
    (body): body is Record<string, unknown> =>
      isRecord(body) &&
      isAudience(body.audience) &&
      STATES.includes(body.pack_state) &&
      typeof body.audience_targeted === 'boolean' &&
      typeof body.visible === 'boolean' &&
      (body.pack === null || isRecord(body.pack)),
  )
  if (!result.ok) return result
  const body = result.value
  const pack = body.pack
  return {
    ok: true,
    value: {
      audience: body.audience as Audience,
      packState: body.pack_state as PublicationState,
      audienceTargeted: body.audience_targeted as boolean,
      visible: body.visible as boolean,
      pack: isRecord(pack) ? previewPackFrom(pack) : null,
    },
  }
}

function previewPackFrom(pack: Record<string, unknown>): PreviewPack | null {
  if (!isString(pack.id) || !isString(pack.title) || !isCategoryRef(pack.category)) return null
  const cards: PreviewPack['cards'] = []
  for (const card of Array.isArray(pack.cards) ? (pack.cards as unknown[]) : []) {
    if (
      isRecord(card) &&
      isString(card.id) &&
      isNumber(card.index) &&
      TYPES.includes(card.type) &&
      isString(card.title) &&
      isString(card.summary)
    ) {
      cards.push({
        id: card.id,
        index: card.index,
        type: card.type as CardType,
        title: card.title,
        summary: card.summary,
      })
    }
  }
  return {
    id: pack.id,
    title: pack.title,
    summary: isString(pack.summary) ? pack.summary : null,
    isSeries: pack.is_series === true,
    category: { id: pack.category.id, name: pack.category.name },
    cards,
  }
}

// --- Cards ------------------------------------------------------------------------------------------------------------------

async function oneCard(request: Promise<Result<WireCard>>): Promise<Result<ManagedCard>> {
  const result = await request
  return result.ok ? { ok: true, value: cardFrom(result.value) } : result
}

/** GET /admin/resources/packs/{pack}/cards/{card}: the Card with its content; Drafts too. */
export function getCard(
  packId: string,
  cardId: string,
  signal?: AbortSignal,
): Promise<Result<ManagedCard>> {
  return oneCard(
    requestJson(
      {
        method: 'GET',
        path: `${BASE}/packs/${idPath(packId)}/cards/${idPath(cardId)}`,
        authenticated: true,
        ...(signal && { signal }),
      },
      isWireCard,
    ),
  )
}

/** A `basic` or `external_link` Card, created with a JSON body. The Type is fixed from here on. */
export interface NewJsonCard {
  type: 'basic' | 'external_link'
  title: string
  /** A canonical document, or null for an empty one. */
  content: unknown
  uri: string | null
  /** Null to derive it from the content; text makes it the Card's own (custom) summary. */
  summary: string | null
}

export function createJsonCard(packId: string, card: NewJsonCard): Promise<Result<ManagedCard>> {
  return oneCard(
    requestJson(
      {
        method: 'POST',
        path: `${BASE}/packs/${idPath(packId)}/cards`,
        authenticated: true,
        body: {
          type: card.type,
          title: card.title,
          content: card.content,
          uri: card.uri,
          summary: card.summary,
        },
      },
      isWireCard,
    ),
  )
}

/** A `file` Card: a multipart form whose `file` part is the file. A File Card never exists without one. */
export interface NewFileCard {
  title: string
  file: File
  /** The document's JSON text, or null for none (a form carries only text). */
  content: unknown
  summary: string | null
}

export function createFileCard(packId: string, card: NewFileCard): Promise<Result<ManagedCard>> {
  const form = new FormData()
  form.set('type', 'file')
  form.set('title', card.title)
  if (card.content !== null && card.content !== undefined) {
    form.set('content', JSON.stringify(card.content))
  }
  if (card.summary !== null) form.set('summary', card.summary)
  // The browser's claimed type for the part is ignored by the server; the file's own name is what it judges by.
  form.set('file', card.file, card.file.name)
  return oneCard(
    requestJson(
      {
        method: 'POST',
        path: `${BASE}/packs/${idPath(packId)}/cards`,
        authenticated: true,
        form,
      },
      isWireCard,
    ),
  )
}

/** The authored fields of a Card a person changed: only what is present is sent. */
export interface CardChanges {
  title?: string
  content?: unknown
  uri?: string | null
  summary?: string
  summaryMode?: SummaryMode
}

/** PATCH .../cards/{card}: the revision the edit was based on is required; a stale one is `409 stale_revision`. */
export function updateCard(
  packId: string,
  cardId: string,
  revision: number,
  changes: CardChanges,
): Promise<Result<ManagedCard>> {
  return oneCard(
    requestJson(
      {
        method: 'PATCH',
        path: `${BASE}/packs/${idPath(packId)}/cards/${idPath(cardId)}`,
        authenticated: true,
        body: {
          revision,
          ...(changes.title !== undefined && { title: changes.title }),
          ...(changes.content !== undefined && { content: changes.content }),
          ...(changes.uri !== undefined && { uri: changes.uri }),
          ...(changes.summary !== undefined && { summary: changes.summary }),
          ...(changes.summaryMode !== undefined && { summary_mode: changes.summaryMode }),
        },
      },
      isWireCard,
    ),
  )
}

/** PUT .../cards/{card}/audiences: inherit the Pack's, or narrow to a subset of them. The server holds the subset rule. */
export function setCardAudiences(
  packId: string,
  cardId: string,
  mode: AudienceMode,
  audiences: readonly Audience[],
): Promise<Result<ManagedCard>> {
  return oneCard(
    requestJson(
      {
        method: 'PUT',
        path: `${BASE}/packs/${idPath(packId)}/cards/${idPath(cardId)}/audiences`,
        authenticated: true,
        body: mode === 'inherit' ? { mode } : { mode, audiences },
      },
      isWireCard,
    ),
  )
}

/** POST .../cards/{card}/publish and .../unpublish: reversible and idempotent, and asking for no recent verification. */
export function setCardPublication(
  packId: string,
  cardId: string,
  action: 'publish' | 'unpublish',
): Promise<Result<ManagedCard>> {
  return oneCard(
    requestJson(
      {
        method: 'POST',
        path: `${BASE}/packs/${idPath(packId)}/cards/${idPath(cardId)}/${action}`,
        authenticated: true,
      },
      isWireCard,
    ),
  )
}

/** DELETE .../cards/{card}: PERMANENT, with its file. Needs recent verification. */
export function deleteCard(packId: string, cardId: string): Promise<Result<null>> {
  return requestVoid({
    method: 'DELETE',
    path: `${BASE}/packs/${idPath(packId)}/cards/${idPath(cardId)}`,
    authenticated: true,
  })
}

/**
 * POST .../cards/{card}/file: replaces a File Card's file. It does NOT use the Card's revision (the later replacement wins) and
 * asks for no recent verification. The old file stays the Card's until the server confirms the new one.
 */
export function replaceCardFile(
  packId: string,
  cardId: string,
  file: File,
): Promise<Result<ManagedCard>> {
  const form = new FormData()
  form.set('file', file, file.name)
  return oneCard(
    requestJson(
      {
        method: 'POST',
        path: `${BASE}/packs/${idPath(packId)}/cards/${idPath(cardId)}/file`,
        authenticated: true,
        form,
      },
      isWireCard,
    ),
  )
}
