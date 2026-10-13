import { expect, test, type Browser, type Page } from '@playwright/test'

import { makeCategory, makePack, RemoveAfter, ROOT } from './resources.ts'
import { apiFrom, replayedStatus, signedInAs } from './support.ts'
import { VerifiedSession, workerSession } from './verified.ts'

// The Resources journeys' cleanup (`RemoveAfter`, e2e/resources.ts) is part of the suite's correctness: a deletion that fails quietly
// leaves Packs and Categories in the development database for the next run to trip over. These prove what it does when it cannot simply
// delete (G10 WP7): a session whose recent proof has lapsed (a long run outlives the minted sessions' 15 minutes), a deletion the
// platform refuses, and something the journey already removed itself. None of them waits 15 real minutes: the probe's session is minted
// with its proof already 16 minutes old (E2eSessionSeeder 'cleanup-probe-stale').

test.describe.configure({ timeout: 120_000 })

/** The cleanup probe (E2eAccountSeeder::CLEANUP_PROBE): a Resources manager, starting from its STALE minted session. */
const probe = (browser: Browser, baseURL: string): VerifiedSession =>
  new VerifiedSession(
    browser,
    baseURL,
    {
      email: 'e2e.cleanup.probe@example.org',
      password: 'e2e-cleanup-probe-password-not-a-secret',
      secret: 'HIGRQXHHM2TI3UZQKLY2JRM22RWF65XE',
    },
    'cleanup-probe-stale',
  )

/** Where the journeys make their fixtures: the worker's own administrator session, so nothing here shares a session with anyone. */
async function maker(browser: Browser): Promise<Page> {
  return workerSession(browser).ready()
}

const statusOf = async (page: Page, path: string): Promise<number> =>
  (await apiFrom(page, 'GET', path)).status

/** Whether management still lists a Category (there is no route for one Category alone). */
async function categoryListed(page: Page, id: string): Promise<boolean> {
  const listed = await apiFrom(page, 'GET', `${ROOT}/categories`)
  expect(listed.status).toBe(200)
  return (listed.body as { data: { id: string }[] }).data.some((c) => c.id === id)
}

test('removes what it registered when its session’s proof has lapsed: it proves with its own authenticator, then deletes, Packs before Categories', async ({
  browser,
  baseURL,
}) => {
  const page = await maker(browser)
  const category = await makeCategory(page)
  const pack = await makePack(page, { categoryId: category.id })

  // The negative control: the same stale session, asked directly, is refused for want of a recent proof, and nothing is deleted.
  const stale = await signedInAs(browser, baseURL ?? '', 'cleanup-probe-stale')
  await stale.goto('/api/v1/health')
  const refused = await apiFrom(stale, 'DELETE', `${ROOT}/packs/${pack.id}`)
  expect(refused.status).toBe(403)
  expect(refused.body).toMatchObject({ verification_required: true })
  expect(await statusOf(page, `${ROOT}/packs/${pack.id}`)).toBe(200)
  await stale.context().close()

  // The cleanup, from that stale session: refused once, proves, deletes; then the Category (which needs no proof).
  const session = probe(browser, baseURL ?? '')
  const removal = new RemoveAfter(() => session)
  removal.category(category.id) // registered first, deleted last: a Category holding a Pack cannot go first
  removal.pack(pack.id)
  try {
    const report = await removal.run(page)
    expect(report).toEqual({
      removed: [`Pack ${pack.id}`, `Category ${category.id}`],
      alreadyGone: [],
      signIns: 0,
      proofs: 1,
    })
  } finally {
    await session.close()
  }
  expect(await statusOf(page, `${ROOT}/packs/${pack.id}`)).toBe(404)
  expect(await categoryListed(page, category.id)).toBe(false)
})

test('fails, naming every fixture it could not remove with the platform’s answer, and still removes the rest', async ({
  browser,
}) => {
  const page = await maker(browser)
  // A Category that holds a Pack the journey did NOT register: the platform refuses to delete it (`category_not_empty`).
  const held = await makeCategory(page)
  const holder = await makePack(page, { categoryId: held.id })
  const other = await makePack(page)

  const removal = new RemoveAfter()
  removal.category(held.id)
  removal.pack(other.id)
  const failure = await removal.run(page).then(
    () => null,
    (error: unknown) => (error instanceof Error ? error.message : String(error)),
  )
  expect(failure, 'a refused deletion fails the cleanup').not.toBeNull()
  expect(failure).toContain('Cleanup left 1 fixture(s) behind')
  expect(failure).toContain(`Category ${held.id}: 409`)
  expect(failure).toContain('category_not_empty')
  // The failure hid nothing: the other Pack was still removed.
  expect(await statusOf(page, `${ROOT}/packs/${other.id}`)).toBe(404)
  // A failed run does not keep what it failed on: running again does nothing, and reports nothing.
  expect(await removal.run(page)).toEqual({ removed: [], alreadyGone: [], signIns: 0, proofs: 0 })

  // This journey's own cleanup, done properly.
  const tidy = new RemoveAfter()
  tidy.pack(holder.id)
  tidy.category(held.id)
  expect((await tidy.run(page)).removed).toEqual([`Pack ${holder.id}`, `Category ${held.id}`])
})

test('reports a fixture the journey already deleted as already gone, by the management route’s own not-found answer', async ({
  browser,
}) => {
  const page = await maker(browser)
  const category = await makeCategory(page)
  const pack = await makePack(page, { categoryId: category.id })
  expect((await workerSession(browser).request('DELETE', `${ROOT}/packs/${pack.id}`)).status).toBe(
    204,
  )

  const removal = new RemoveAfter()
  removal.pack(pack.id)
  removal.category(category.id)
  expect(await removal.run(page)).toMatchObject({
    removed: [`Category ${category.id}`],
    alreadyGone: [`Pack ${pack.id}`],
  })
})

test('deletes in the worker’s own session, signed in for real, without proving on (or touching) a session a journey holds', async ({
  browser,
  baseURL,
  request,
}) => {
  // A journey's page in the minted `plain-guardian` session, which journeys in other workers share.
  const journey = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
  await journey.goto('/api/v1/health')
  const before = (await journey.context().cookies()).find(
    (c) => c.name === '__Host-flowlife-session',
  )
  const pack = await makePack(journey)

  const removal = new RemoveAfter()
  removal.pack(pack.id)
  const report = await removal.run(journey)
  expect(report.removed).toEqual([`Pack ${pack.id}`])
  expect(report.proofs).toBe(0)

  // The journey's session was not rotated by the cleanup: the cookie every sharer of it holds still authenticates (a value is
  // re-encrypted on each response, so it is the session id behind it that is checked, by replaying the value taken before).
  expect(before).toBeDefined()
  expect(await replayedStatus(request, baseURL ?? '', before?.value ?? '')).toBe(200)
  expect((await apiFrom(journey, 'GET', '/api/v1/me')).status).toBe(200)
  await journey.context().close()
})
