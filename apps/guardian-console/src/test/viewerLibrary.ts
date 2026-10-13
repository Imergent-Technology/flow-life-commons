import { json } from './fakeApi.ts'
import { numbered, wireDeliveredCard } from './library.ts'
import {
  CARD2_ID,
  CARD3_ID,
  CARD_ID,
  CATEGORY2_ID,
  CATEGORY_ID,
  doc,
  EMPTY_DOC,
  PACK2_ID,
  PACK_ID,
} from './resources.ts'

// The PRIVILEGED VIEWER's answers (ADR 0038, R6-R9; `/admin/resource-library`, policy A, `resources.view`) as the server sends them: wire
// shapes (snake_case). Unlike the audience reader's (`test/library.ts`), every Pack and Card is here, Drafts included, with the
// publication state, the audiences and the last editor as data. A test that serves both readers must serve DIFFERENT content from them
// (a Draft the audience reader never sees), or it proves nothing about which one was asked.

export const RESOURCE_LIBRARY = '/api/v1/admin/resource-library'
export const RESOURCE_LIBRARY_PACK = `${RESOURCE_LIBRARY}/packs/${PACK_ID}`

/** Someone who may view the whole library and manage nothing: the viewer. */
export const VIEWER = ['console.access', 'resources.view']

export const viewerFilePath = (packId = PACK_ID, cardId = CARD_ID) =>
  `${RESOURCE_LIBRARY}/packs/${packId}/cards/${cardId}/file`

const EDITOR = { display_name: 'Hone Guardian' }
const WHEN = '2026-09-22T08:00:00Z'

/** A Card as the viewer is sent it: the reading fields, plus what was written about it. */
export const wireViewerCard = (
  overrides: Record<string, unknown> = {},
): Record<string, unknown> => ({
  ...wireDeliveredCard(),
  state: 'published',
  audience_mode: 'inherit',
  audiences: ['guardian'],
  updated_at: WHEN,
  updated_by: EDITOR,
  ...overrides,
})

export const wireViewerFileCard = (
  overrides: Record<string, unknown> = {},
): Record<string, unknown> => {
  const id = (overrides.id as string | undefined) ?? CARD_ID
  return wireViewerCard({
    type: 'file',
    title: 'Handbook',
    summary: '',
    file: {
      name: 'Welcome handbook.pdf',
      media_type: 'application/pdf',
      byte_size: 1_048_576,
      download_path: viewerFilePath(PACK_ID, id),
    },
    content: { format: 'prosemirror', version: 1, document: EMPTY_DOC },
    ...overrides,
  })
}

/** The Cards of a Pack, numbered 1..n across ALL of them (Drafts included), as the viewer is told. */
export { numbered }

/** A Pack as the viewer reads it. The counts follow the Cards unless a test says otherwise. */
export const wireViewerPack = (
  overrides: Record<string, unknown> = {},
): Record<string, unknown> => {
  const cards = (overrides.cards as Record<string, unknown>[] | undefined) ?? [wireViewerCard()]
  return {
    id: PACK_ID,
    title: 'Welcome pack',
    summary: 'Everything a new Guardian needs.',
    is_series: false,
    category: { id: CATEGORY_ID, name: 'Training guides' },
    state: 'published',
    audiences: ['guardian'],
    card_count: cards.length,
    draft_card_count: cards.filter((card) => card.state === 'draft').length,
    updated_at: WHEN,
    updated_by: EDITOR,
    cards,
    ...overrides,
  }
}

export const THREE_VIEWER_CARDS = numbered([
  wireViewerCard({ id: CARD_ID, title: 'Before you start', summary: 'What to bring.' }),
  wireViewerCard({
    id: CARD2_ID,
    title: 'On the day (still being written)',
    summary: 'How the day runs.',
    state: 'draft',
    content: { format: 'prosemirror', version: 1, document: doc('Arrive by nine.') },
  }),
  wireViewerCard({
    id: CARD3_ID,
    title: 'Afterwards',
    summary: 'What happens next.',
    audience_mode: 'narrowed',
    audiences: ['volunteer'],
    content: { format: 'prosemirror', version: 1, document: doc('Send your notes.') },
  }),
])

/** A Pack as the viewer's LIBRARY lists it. */
export const wireViewerListed = (overrides: Record<string, unknown> = {}) => ({
  id: PACK_ID,
  title: 'Welcome pack',
  summary: 'Everything a new Guardian needs.',
  is_series: false,
  state: 'published',
  audiences: ['guardian'],
  card_count: 1,
  draft_card_count: 0,
  updated_at: WHEN,
  updated_by: EDITOR,
  ...overrides,
})

/** A group of the viewer's library. `category: null` is the group of Packs that have none. */
export const wireViewerGroup = (
  packs: unknown[],
  category: { id: string; name: string } | null = { id: CATEGORY_ID, name: 'Training guides' },
) => ({ category, packs })

export const viewerLibraryOf = (...groups: unknown[]) => json({ data: groups })

/** Content the viewer sees and the audience reader never does: a Draft Pack, a Pack for someone else, and one with no Category. */
export const DRAFT_PACK_ID = '01J00000000000000000PACK009'
export const UNCATEGORIZED_PACK_ID = '01J00000000000000000PACK008'

export const VIEWER_GROUPS = [
  wireViewerGroup([
    wireViewerListed({
      card_count: 3,
      draft_card_count: 1,
      audiences: ['guardian', 'member'],
    }),
    wireViewerListed({
      id: DRAFT_PACK_ID,
      title: 'Secret draft plan',
      summary: null,
      state: 'draft',
      audiences: ['volunteer'],
      card_count: 2,
      draft_card_count: 2,
    }),
  ]),
  wireViewerGroup(
    [
      wireViewerListed({
        id: PACK2_ID,
        title: 'Soup',
        summary: null,
        audiences: ['member'],
        card_count: 0,
      }),
    ],
    { id: CATEGORY2_ID, name: 'Recipes' },
  ),
  wireViewerGroup(
    [
      wireViewerListed({
        id: UNCATEGORIZED_PACK_ID,
        title: 'Loose notes',
        summary: null,
        state: 'draft',
        audiences: [],
        card_count: 1,
        draft_card_count: 1,
      }),
    ],
    null,
  ),
]
