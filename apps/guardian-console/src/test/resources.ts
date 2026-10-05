import { json } from './fakeApi.ts'

// Resources management as the server sends it (openapi/openapi.yaml, ADR 0037). Wire shapes (snake_case), so a test proves the
// Console reads what the server really says.

export const CATEGORY_ID = '01J000000000000000CATEGORY1'
export const CATEGORY2_ID = '01J000000000000000CATEGORY2'
export const PACK_ID = '01J00000000000000000PACK001'
export const PACK2_ID = '01J00000000000000000PACK002'
export const CARD_ID = '01J00000000000000000CARD001'
export const CARD2_ID = '01J00000000000000000CARD002'
export const CARD3_ID = '01J00000000000000000CARD003'

export const MANAGE = ['console.access', 'resources.manage']
export const VIEW_ONLY = ['console.access', 'resources.view']

export const ROOT = '/api/v1/admin/resources'
export const PACK_PATH = `${ROOT}/packs/${PACK_ID}`
export const CARD_PATH = `${PACK_PATH}/cards/${CARD_ID}`

const GWEN = { id: '01J0000000000000000000PRSN', display_name: 'Gwen Guardian' }
export const HONE = { id: '01J000000000000000000OTHER1', display_name: 'Hone Guardian' }

export const wireCategory = (overrides: Record<string, unknown> = {}): Record<string, unknown> => ({
  id: CATEGORY_ID,
  name: 'Training guides',
  position: 1,
  pack_count: 0,
  created_by: GWEN,
  updated_by: GWEN,
  created_at: '2026-10-01T09:00:00Z',
  updated_at: '2026-10-01T09:00:00Z',
  ...overrides,
})

export const categoryList = (rows: unknown[]) => ({ data: rows })

export const EMPTY_DOC = { type: 'doc', content: [] }

export const doc = (text: string) => ({
  type: 'doc',
  content: [{ type: 'paragraph', content: [{ type: 'text', text }] }],
})

export const wireFileSummary = (overrides: Record<string, unknown> = {}) => ({
  name: 'Welcome handbook.pdf',
  media_type: 'application/pdf',
  byte_size: 1_572_864,
  ...overrides,
})

export const wireManagedFile = (overrides: Record<string, unknown> = {}) => ({
  ...wireFileSummary(),
  sha256: 'a'.repeat(64),
  uploaded_by: GWEN,
  uploaded_at: '2026-10-02T10:00:00Z',
  available: true,
  download_path: `${CARD_PATH}/file`,
  ...overrides,
})

/** A Card without its content, as a Pack lists it. */
export const wireOutline = (overrides: Record<string, unknown> = {}): Record<string, unknown> => ({
  id: CARD_ID,
  pack_id: PACK_ID,
  position: 1,
  type: 'basic',
  title: 'Opening hours',
  summary_mode: 'derived',
  summary: 'We are open every day.',
  uri: null,
  file: null,
  audience_mode: 'inherit',
  audiences: [],
  state: 'draft',
  revision: 1,
  created_by: GWEN,
  updated_by: GWEN,
  created_at: '2026-10-01T09:00:00Z',
  updated_at: '2026-10-01T09:00:00Z',
  ...overrides,
})

/** A Card with its content, as one Card is shown. */
export const wireCard = (overrides: Record<string, unknown> = {}): Record<string, unknown> => ({
  ...wireOutline(),
  content: { format: 'prosemirror', version: 1, document: doc('We are open every day.') },
  ...overrides,
})

export const wireFileCard = (overrides: Record<string, unknown> = {}): Record<string, unknown> =>
  wireCard({
    type: 'file',
    title: 'Handbook',
    summary: '',
    file: wireManagedFile(),
    content: { format: 'prosemirror', version: 1, document: EMPTY_DOC },
    ...overrides,
  })

export const wireLinkCard = (overrides: Record<string, unknown> = {}): Record<string, unknown> =>
  wireCard({
    type: 'external_link',
    title: 'The venue',
    uri: 'https://example.org/venue',
    ...overrides,
  })

export const wirePack = (overrides: Record<string, unknown> = {}): Record<string, unknown> => ({
  id: PACK_ID,
  title: 'Welcome pack',
  summary: 'Everything a new Guardian needs.',
  is_series: false,
  category: { id: CATEGORY_ID, name: 'Training guides' },
  position: 1,
  state: 'draft',
  revision: 1,
  audiences: ['guardian'],
  card_count: 1,
  published_card_count: 0,
  created_by: GWEN,
  updated_by: GWEN,
  created_at: '2026-10-01T09:00:00Z',
  updated_at: '2026-10-01T09:00:00Z',
  cards: [wireOutline()],
  ...overrides,
})

/** A Pack as a list shows it: counts, and no `cards` key at all. */
export const wireListedPack = (
  overrides: Record<string, unknown> = {},
): Record<string, unknown> => {
  const listed = wirePack(overrides)
  delete listed.cards
  return listed
}

export const packsPage = (rows: unknown[], meta: Record<string, number> = {}) => ({
  data: rows,
  meta: { page: 1, per_page: 25, total: rows.length, last_page: 1, ...meta },
})

export const wirePreview = (overrides: Record<string, unknown> = {}) => ({
  audience: 'guardian',
  pack_state: 'draft',
  audience_targeted: true,
  visible: true,
  pack: {
    id: PACK_ID,
    title: 'Welcome pack',
    summary: 'Everything a new Guardian needs.',
    is_series: false,
    category: { id: CATEGORY_ID, name: 'Training guides' },
    card_count: 1,
    cards: [
      {
        id: CARD_ID,
        index: 1,
        type: 'basic',
        title: 'Opening hours',
        summary: 'We are open every day.',
        uri: null,
        file: null,
        content: { format: 'prosemirror', version: 1, document: EMPTY_DOC },
      },
    ],
  },
  ...overrides,
})

// --- Refusals, with the codes the platform answers -------------------------------------------------------------------------

export const coded = (status: number, code: string, extra: Record<string, unknown> = {}) =>
  json({ message: 'Server wording that the Console must not show.', code, ...extra }, status)

export const staleRevision = (current: unknown) => coded(409, 'stale_revision', { current })
export const orderMismatch = () => coded(409, 'order_mismatch')
export const packNotFound = () => coded(404, 'pack_not_found')
export const cardNotFound = () => coded(404, 'card_not_found')
export const categoryNotFound = () => coded(404, 'category_not_found')
export const duplicateCategory = () => coded(409, 'duplicate_category')
export const categoryNotEmpty = () => coded(409, 'category_not_empty')
export const publishedPackRequirement = (requirement: string) =>
  coded(409, 'published_pack_requirement', { requirement })
export const packNotPublishable = (unmet: string[]) => coded(409, 'pack_not_publishable', { unmet })
export const cardNotPublishable = (unmet: string[]) => coded(409, 'card_not_publishable', { unmet })
export const cardAudienceConflict = (cards: string[]) =>
  coded(409, 'card_audience_conflict', { requirement: 'subset', cards })
export const fileTypeNotAllowed = () =>
  json(
    {
      message: 'That file type is not allowed.',
      code: 'file_type_not_allowed',
      errors: { file: ['That file type is not allowed.'] },
    },
    422,
  )
export const invalidContent = (message = 'Content is not allowed at content.1.') =>
  json({ message, code: 'invalid_content', errors: { content: [message] } }, 422)
export const invalidUri = () =>
  json(
    {
      message: 'That is not a valid web address.',
      code: 'invalid_uri',
      errors: { uri: ['That is not a valid web address.'] },
    },
    422,
  )
