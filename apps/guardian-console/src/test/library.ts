import { json } from './fakeApi.ts'
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

// The Resource library as the server sends it (openapi/openapi.yaml, ADR 0037): wire shapes (snake_case), so a test proves the Console
// reads what the server really says. A delivered Pack has only the Cards the viewer may see; nothing here carries state, revision,
// audiences, provenance or a stored position, because the server's delivery never does.

export const LIBRARY = '/api/v1/admin/resource-library'
export const LIBRARY_PACK = `${LIBRARY}/packs/${PACK_ID}`

/** A Guardian who may read the library and manage nothing. */
export const READER = ['console.access', 'resources.view']
/** One who may manage Resources and, since the role catalog grants it separately, nothing of the library: the Console must not assume. */
export const MANAGER_ONLY = ['console.access', 'resources.manage']

export const filePath = (packId = PACK_ID, cardId = CARD_ID) =>
  `${LIBRARY}/packs/${packId}/cards/${cardId}/file`

export const wireDeliveredFile = (
  overrides: Record<string, unknown> = {},
): Record<string, unknown> => ({
  name: 'Welcome handbook.pdf',
  media_type: 'application/pdf',
  byte_size: 1_048_576,
  download_path: filePath(),
  ...overrides,
})

export const wireDeliveredCard = (
  overrides: Record<string, unknown> = {},
): Record<string, unknown> => ({
  id: CARD_ID,
  index: 1,
  type: 'basic',
  title: 'Opening hours',
  summary: 'When the doors are open.',
  uri: null,
  file: null,
  content: { format: 'prosemirror', version: 1, document: doc('We are open every day.') },
  ...overrides,
})

export const wireFileCard = (overrides: Record<string, unknown> = {}): Record<string, unknown> =>
  wireDeliveredCard({
    type: 'file',
    title: 'Handbook',
    summary: '',
    file: wireDeliveredFile(),
    content: { format: 'prosemirror', version: 1, document: EMPTY_DOC },
    ...overrides,
  })

export const wireLinkCard = (overrides: Record<string, unknown> = {}): Record<string, unknown> =>
  wireDeliveredCard({
    type: 'external_link',
    title: 'The venue',
    summary: 'Where we meet.',
    uri: 'https://example.org/venue',
    content: { format: 'prosemirror', version: 1, document: EMPTY_DOC },
    ...overrides,
  })

/** A Pack as delivered. `card_count` follows the Cards unless a test says otherwise. */
export const wireLibraryPack = (
  overrides: Record<string, unknown> = {},
): Record<string, unknown> => {
  const cards = (overrides.cards as unknown[] | undefined) ?? [wireDeliveredCard()]
  return {
    id: PACK_ID,
    title: 'Welcome pack',
    summary: 'Everything a new Guardian needs.',
    is_series: false,
    category: { id: CATEGORY_ID, name: 'Training guides' },
    card_count: cards.length,
    cards,
    ...overrides,
  }
}

/** The Cards of a Pack, numbered 1..n among themselves as delivery numbers them. */
export const numbered = (cards: Record<string, unknown>[]): Record<string, unknown>[] =>
  cards.map((card, index) => ({ ...card, index: index + 1 }))

export const THREE_CARDS = numbered([
  wireDeliveredCard({ id: CARD_ID, title: 'Before you start', summary: 'What to bring.' }),
  wireDeliveredCard({
    id: CARD2_ID,
    title: 'On the day',
    summary: 'How the day runs.',
    content: { format: 'prosemirror', version: 1, document: doc('Arrive by nine.') },
  }),
  wireDeliveredCard({
    id: CARD3_ID,
    title: 'Afterwards',
    summary: 'What happens next.',
    content: { format: 'prosemirror', version: 1, document: doc('Send your notes.') },
  }),
])

export const wireListedEntry = (overrides: Record<string, unknown> = {}) => ({
  id: PACK_ID,
  title: 'Welcome pack',
  summary: 'Everything a new Guardian needs.',
  is_series: false,
  card_count: 1,
  ...overrides,
})

export const wireGroup = (
  packs: unknown[],
  category: { id: string; name: string } = { id: CATEGORY_ID, name: 'Training guides' },
) => ({ category, packs })

export const libraryOf = (...groups: unknown[]) => json({ data: groups })

export const TWO_GROUPS = [
  wireGroup([wireListedEntry(), wireListedEntry({ id: PACK2_ID, title: 'Zebra rules' })]),
  wireGroup(
    [wireListedEntry({ id: '01J00000000000000000PACK003', title: 'Soup', summary: null })],
    {
      id: CATEGORY2_ID,
      name: 'Recipes',
    },
  ),
]

export const resourceNotFound = () =>
  json(
    { message: 'Server wording the Console must not show.', code: 'resource_pack_not_found' },
    404,
  )
