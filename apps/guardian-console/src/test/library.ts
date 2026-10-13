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

// The Resource library as the server sends it (openapi/openapi.yaml, ADR 0037, ADR 0038): wire shapes (snake_case), so a test proves the
// Console reads what the server really says. There are TWO answers, and a test of one must never be served by the other:
//   - the AUDIENCE reader (`/admin/my-resources`, policy C): a delivered Pack has only the Cards the reader may see; nothing here carries
//     state, revision, audiences, provenance or a stored position, because delivery never does. (This file's `wireDelivered*`.)
//   - the VIEWER (`/admin/resource-library`, policy A, `resources.view`): everything, with the state, audiences and last editor as data.
//     (`test/viewerLibrary.ts`.)

/** Policy C, the audience reader's family of routes. */
export const MY_RESOURCES = '/api/v1/admin/my-resources'
export const MY_RESOURCES_PACK = `${MY_RESOURCES}/packs/${PACK_ID}`

/** Someone the Console admits who may not view the whole library: the audience reader. Nothing in it is a relationship or a role. */
export const READER = ['console.access']
/** One who may manage Resources and, since the role catalog grants it separately, nothing of the library: the Console must not assume. */
export const MANAGER_ONLY = ['console.access', 'resources.manage']

export const filePath = (packId = PACK_ID, cardId = CARD_ID) =>
  `${MY_RESOURCES}/packs/${packId}/cards/${cardId}/file`

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

/** A File Card, whose file is served by the route of ITS OWN id (the platform names no other, and the Console accepts no other). */
export const wireFileCard = (overrides: Record<string, unknown> = {}): Record<string, unknown> =>
  wireDeliveredCard({
    type: 'file',
    title: 'Handbook',
    summary: '',
    file: wireDeliveredFile({
      download_path: filePath(PACK_ID, (overrides.id as string | undefined) ?? CARD_ID),
    }),
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
