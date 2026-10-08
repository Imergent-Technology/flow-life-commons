import { expect, type Page } from '@playwright/test'

import { LIBRARY, ROOT } from './resources.ts'
import { apiFrom } from './support.ts'

// The Resources demo dataset as the browser journeys see it (apps/platform/database/seeders/ResourcesDemoSeeder.php, which
// `./flow test e2e` runs before the suite; the names below are the seeder's constants and its backend test pins the dataset
// itself, so a rename in one place fails loudly in the other). Nothing here is a test.

export const CATEGORY = {
  started: 'Getting Started',
  running: 'Running the Sanctuary',
  safety: 'Safety and Wellbeing',
  members: 'Member Circle',
} as const

export const PACK = {
  /** A Series of four: the second is for Members only, so a Guardian reads three. */
  narrowed: 'Guardian Onboarding',
  /** One Card, titled as its Pack. */
  single: 'Opening and Closing Checklist',
  /** Three Cards to choose between (a table, a code block, a CSV) and a Draft Card; not a Series. */
  operations: 'Sanctuary Operations',
  link: 'Room Booking Calendar',
  image: 'Sanctuary Floor Plan',
  draft: 'Winter Gathering Plan',
  /** A Series of three, ending in a PDF. */
  series: 'Fire Safety Orientation',
  membersOnly: 'Member Circle Handbook',
} as const

export const CARD = {
  welcome: 'Welcome to the team',
  hidden: 'Member circle facilitation notes',
  firstShift: 'Your first shift',
  handover: 'Handover and support',
  keys: 'Keys, alarm and access',
  cleaning: 'Cleaning and supplies',
  rota: 'Shift rota template',
  draft: 'Winter heating guide',
  why: 'Why fire safety matters',
  exits: 'Know your exits, and where to meet if you have to leave the building',
  evacuation: 'Evacuation plan',
  booking: 'Open the room booking calendar',
  floorPlan: 'Floor plan of the sanctuary',
} as const

/** A word that is in the hidden Card's title and body and nowhere a Guardian may read. */
export const HIDDEN_WORD = 'facilitation'

export const LINK_ADDRESS = 'https://example.org/flow-life/room-booking'
export const ROTA_FILE = 'two-week-shift-rota-template-for-sanctuary-guardians.csv'
export const PDF_FILE = 'evacuation-plan.pdf'
export const PNG_FILE = 'sanctuary-floor-plan.png'

export interface DemoPack {
  id: string
  title: string
  /** Every Card management lists, Drafts and Member-only ones included. */
  cards: { id: string; title: string; state: string }[]
}

type PackTitle = (typeof PACK)[keyof typeof PACK]

/** The ids of the seeded Packs and their Cards, as MANAGEMENT lists them. Only for finding ids: what a Guardian sees is judged from the library. */
export async function demoPacks(page: Page): Promise<Record<keyof typeof PACK, DemoPack>> {
  const found: Partial<Record<keyof typeof PACK, DemoPack>> = {}
  for (const [key, title] of Object.entries(PACK) as [keyof typeof PACK, PackTitle][]) {
    const list = await apiFrom(
      page,
      'GET',
      `${ROOT}/packs?q=${encodeURIComponent(title)}&per_page=100`,
    )
    expect(list.status, `list ${title}`).toBe(200)
    const row = (list.body as { data: { id: string; title: string }[] }).data.find(
      (p) => p.title === title,
    )
    expect(row, `the demo Pack “${title}” is seeded`).toBeDefined()
    const pack = await apiFrom(page, 'GET', `${ROOT}/packs/${row?.id ?? ''}`)
    expect(pack.status).toBe(200)
    found[key] = {
      id: row?.id ?? '',
      title,
      cards: (pack.body as { cards: DemoPack['cards'] }).cards.map((c) => ({
        id: c.id,
        title: c.title,
        state: c.state,
      })),
    }
  }
  return found as Record<keyof typeof PACK, DemoPack>
}

export function cardIdOf(pack: DemoPack, title: string): string {
  const card = pack.cards.find((c) => c.title === title)
  expect(card, `the demo Card “${title}” is in “${pack.title}”`).toBeDefined()
  return card?.id ?? ''
}

export const cardAddress = (packId: string, cardId: string): string =>
  `/resource-library/${packId}?card=${cardId}`

export interface DeliveredPackBody {
  title: string
  cards: { id: string; title: string; summary: string; index: number }[]
}

/** The Pack exactly as the platform delivers it to this signed-in Guardian. */
export async function deliveredPack(page: Page, packId: string): Promise<DeliveredPackBody> {
  const response = await apiFrom(page, 'GET', `${LIBRARY}/packs/${packId}`)
  expect(response.status).toBe(200)
  return response.body as DeliveredPackBody
}
