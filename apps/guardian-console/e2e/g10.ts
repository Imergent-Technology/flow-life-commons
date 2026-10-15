import { expect, type Browser, type Page } from '@playwright/test'

import { inTheme, type Theme } from './axe.ts'
import { LIBRARY } from './resources.ts'
import { demoPacks, ELIGIBLE, PACK, type DemoPack } from './resourcesDemo.ts'
import { apiFrom, signedInAs, type FixtureSession } from './support.ts'
import { workerSession } from './verified.ts'

// Helpers for the G10 integration journeys (g10-*.spec.ts): the real personas of ADR 0038's testing strategy, each a real Account whose
// authority is a real role and whose audiences are real relationships and Membership (E2eAccountSeeder::PERSONA_*), reading the real
// demo data (ResourcesDemoSeeder, RelationshipsDemoSeeder). Nothing here is a test, and nothing intercepts `/me`.

export const RELATIONSHIPS = '/api/v1/admin/relationships'

/** Every Published demo Pack, in the order a reader who may read them all is shown them (Category order, then Pack order). */
const DEMO_ORDER: readonly string[] = [
  PACK.narrowed,
  PACK.single,
  PACK.operations,
  PACK.link,
  PACK.image,
  PACK.series,
  PACK.membersOnly,
  PACK.shared,
  PACK.volunteersOnly,
]

const DEMO_TITLES = new Set<string>(Object.values(PACK))

/** The demo Packs a reader eligible for exactly these audiences is served, in order (OR across audiences). */
export function eligibleFor(...audiences: (keyof typeof ELIGIBLE)[]): string[] {
  const union = new Set<string>(audiences.flatMap((a) => [...ELIGIBLE[a]]))
  return DEMO_ORDER.filter((title) => union.has(title))
}

/** A page signed in as a persona, standing at the Console's origin so it can make the Console's own requests. */
export async function persona(
  browser: Browser,
  baseURL: string | undefined,
  name: FixtureSession,
  theme?: Theme,
): Promise<Page> {
  const page = await signedInAs(browser, baseURL ?? '', name)
  if (theme !== undefined) await inTheme(page, theme)
  await page.goto('/api/v1/health')
  return page
}

/** What `/me` says this session may do. */
export async function capabilitiesOf(page: Page): Promise<string[]> {
  const me = await apiFrom(page, 'GET', '/api/v1/me')
  expect(me.status, JSON.stringify(me.body)).toBe(200)
  return [...(me.body as { capabilities: string[] }).capabilities].sort()
}

/**
 * The demo Packs policy C serves this persona, in the order served. Journeys running alongside make Packs of their own, which may be
 * eligible too, so only the demo's titles are judged; a persona with no audience is judged on the whole answer (see `deliveredAll`).
 */
export async function deliveredDemoTitles(page: Page): Promise<string[]> {
  return (await deliveredAll(page)).filter((title) => DEMO_TITLES.has(title))
}

/** Every Pack title policy C serves this persona. */
export async function deliveredAll(page: Page): Promise<string[]> {
  const listed = await apiFrom(page, 'GET', LIBRARY)
  expect(listed.status, JSON.stringify(listed.body)).toBe(200)
  return (listed.body as { data: { packs: { title: string }[] }[] }).data.flatMap((group) =>
    group.packs.map((p) => p.title),
  )
}

/** The Card titles of a Pack as policy C delivers it, or the status it was refused with. */
export async function deliveredCards(
  page: Page,
  packId: string,
): Promise<{ status: number; titles: string[] }> {
  const read = await apiFrom(page, 'GET', `${LIBRARY}/packs/${packId}`)
  const titles =
    read.status === 200
      ? (read.body as { cards: { title: string }[] }).cards.map((c) => c.title)
      : []
  return { status: read.status, titles }
}

/** The status policy C's file route answers this persona for a Card (headers only; the bytes are not read). */
export async function fileStatus(page: Page, packId: string, cardId: string): Promise<number> {
  return page.evaluate(
    async (path) => (await fetch(path)).status,
    `${LIBRARY}/packs/${packId}/cards/${cardId}/file`,
  )
}

let packs: Record<keyof typeof PACK, DemoPack> | undefined

/** The demo Packs' ids, as management lists them (read once per worker, in the worker's own administrator session). */
export async function demo(browser: Browser): Promise<Record<keyof typeof PACK, DemoPack>> {
  packs ??= await demoPacks(await workerSession(browser).ready())
  return packs
}

/** A relationship's management view, read by the worker's administrator: its instance, revision and status. */
export async function relationshipOf(
  browser: Browser,
  slug: 'guardians' | 'volunteers',
  personId: string,
): Promise<{ id: string; revision: number; status: string } | null> {
  const read = await workerSession(browser).request(
    'GET',
    `${RELATIONSHIPS}/${slug}/${personId}/management`,
  )
  if (read.status === 404) return null
  expect(read.status, JSON.stringify(read.body)).toBe(200)
  const body = read.body as { relationship_id: string; revision: number; status: string }
  return { id: body.relationship_id, revision: body.revision, status: body.status }
}

/** The Person behind a persona's Account, found by the worker's administrator on the Accounts screen's own search. */
export async function accountOf(
  browser: Browser,
  email: string,
): Promise<{
  id: string
  personId: string
  assignments: string[]
  sourced: string[]
}> {
  const session = workerSession(browser)
  const found = await session.request(
    'GET',
    `/api/v1/admin/accounts?q=${encodeURIComponent(email)}`,
  )
  expect(found.status, JSON.stringify(found.body)).toBe(200)
  const row = (found.body as { data: { id: string; email: string }[] }).data.find(
    (a) => a.email === email,
  )
  expect(row, `the persona ${email} is seeded`).toBeDefined()
  const read = await session.request('GET', `/api/v1/admin/accounts/${row?.id ?? ''}`)
  expect(read.status, JSON.stringify(read.body)).toBe(200)
  const body = read.body as {
    id: string
    person_id: string
    assignments: { key: string }[]
    sourced_grants: { key: string }[]
  }
  return {
    id: body.id,
    personId: body.person_id,
    assignments: body.assignments.map((a) => a.key).sort(),
    sourced: body.sourced_grants.map((g) => g.key).sort(),
  }
}
