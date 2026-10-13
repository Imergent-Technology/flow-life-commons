import { expect, test, type Page } from '@playwright/test'

import {
  accountOf,
  capabilitiesOf,
  deliveredAll,
  deliveredCards,
  deliveredDemoTitles,
  demo,
  eligibleFor,
  fileStatus,
  persona,
  RELATIONSHIPS,
} from './g10.ts'
import { LIBRARY, ROOT, VIEWER_LIBRARY } from './resources.ts'
import { cardIdOf, CARD, PACK, VOLUNTEER_WORD } from './resourcesDemo.ts'
import { apiFrom } from './support.ts'
import { workerSession } from './verified.ts'

// G10 end to end (ADR 0038, WP7): Guardians and Volunteers are relationships, not roles; Console admission is a capability; what a
// reader is served is their CURRENT relationships and Membership, combined by OR. Every persona is a real Account with a real role and
// real relationships (E2eAccountSeeder::PERSONA_*), reading the real demo data, through the real gateway, with the real platform
// deciding every request. Nothing intercepts `/me`. These journeys only read, so they may run in parallel.
//
// Each persona's eligibility is judged against the demo's own Packs (other journeys make Packs of their own while these run); a persona
// eligible for nothing is judged on the whole answer, which must be empty. Both the response and the screen are read: what the server
// refuses is absent from the page, not merely unlinked.

test.describe.configure({ timeout: 120_000 })

const ADMIN_ONLY = ['access.roles.assign', 'guardians.manage', 'resources.view']

async function libraryHeading(page: Page): Promise<void> {
  await page.goto('/resource-library')
  await expect(page.getByRole('heading', { level: 1, name: 'Resource Library' })).toBeVisible()
}

test.describe('a role is not a relationship', () => {
  test('the Guardian role without a Guardian relationship: its capabilities, and no Guardian audience, no Guardian record', async ({
    browser,
    baseURL,
  }) => {
    const page = await persona(browser, baseURL, 'persona-role-only')
    // What the role genuinely grants is there, the Resources viewing permission included...
    const capabilities = await capabilitiesOf(page)
    expect(capabilities).toEqual(
      expect.arrayContaining([
        'console.access',
        'resources.view',
        'resources.manage',
        'guardians.view',
      ]),
    )
    expect(capabilities).not.toContain('guardians.manage')
    // ...but audience delivery is relationships and Membership: nothing at all, though every demo Pack has a Guardian audience somewhere.
    expect(await deliveredAll(page)).toEqual([])
    const packs = await demo(browser)
    expect((await deliveredCards(page, packs.single.id)).status).toBe(404)

    // Not a Guardian anywhere: no relationship, and not in the Guardian directory.
    const account = await accountOf(browser, 'e2e.persona.roleonly@example.org')
    const admin = workerSession(browser)
    expect(
      (await admin.request('GET', `${RELATIONSHIPS}/guardians/${account.personId}/management`))
        .status,
    ).toBe(404)
    const directory = await admin.request(
      'GET',
      `${RELATIONSHIPS}/guardians?q=${encodeURIComponent('E2E Role Only')}`,
    )
    expect(directory.status).toBe(200)
    expect((directory.body as { data: unknown[] }).data).toEqual([])
    expect(account.assignments).toEqual(['guardian-full'])
    expect(account.sourced).toEqual([])
    await page.context().close()
  })

  test('a Guardian relationship without a role: a Guardian, eligible, and still not admitted to the Console', async ({
    browser,
    baseURL,
  }) => {
    const page = await persona(browser, baseURL, 'persona-unadmitted')
    expect(await capabilitiesOf(page)).toEqual([])
    expect((await apiFrom(page, 'GET', LIBRARY)).status).toBe(403)

    // The Console does not open for them: the root routes them to the Member surface, with no Console navigation.
    await page.goto('/')
    await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible()
    await expect(page.getByRole('navigation', { name: 'Console' })).toHaveCount(0)
    await page.goto('/resource-library')
    await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()
    await expect(page.getByRole('heading', { level: 1, name: 'Resource Library' })).toHaveCount(0)

    // And yet they ARE an Active Guardian, the declined decision recorded.
    const account = await accountOf(browser, 'e2e.persona.unadmitted@example.org')
    const guardian = await workerSession(browser).request(
      'GET',
      `${RELATIONSHIPS}/guardians/${account.personId}/management`,
    )
    expect(guardian.status).toBe(200)
    expect((guardian.body as { status: string }).status).toBe('active')
    expect(account.sourced).toEqual([])
    await page.context().close()
  })
})

test.describe('who reads what (policy C)', () => {
  test('the restricted Guardian (Guardian Initiate through the relationship): published Guardian Resources only, and no privileged or management route', async ({
    browser,
    baseURL,
  }) => {
    const page = await persona(browser, baseURL, 'persona-initiate')
    const packs = await demo(browser)
    expect(await capabilitiesOf(page)).toEqual(['console.access'])
    const account = await accountOf(browser, 'e2e.persona.initiate@example.org')
    expect(account.assignments).toEqual([])
    expect(account.sourced).toEqual(['guardian-initiate'])

    expect(await deliveredDemoTitles(page)).toEqual(eligibleFor('guardian'))
    // In the shared Pack: the Card for both and the Guardians' own; never the Volunteers', never the Draft.
    expect(await deliveredCards(page, packs.shared.id)).toEqual({
      status: 200,
      titles: [CARD.dayPlan, CARD.hosting],
    })
    // Draft, Member-only and Volunteer-only Packs answer exactly as one that does not exist.
    const missing = await apiFrom(page, 'GET', `${LIBRARY}/packs/01arz3ndektsv4rrffq69g5fav`)
    for (const hidden of [packs.draft, packs.membersOnly, packs.volunteersOnly]) {
      const read = await apiFrom(page, 'GET', `${LIBRARY}/packs/${hidden.id}`)
      expect(read, hidden.title).toEqual(missing)
    }
    // Files follow the same rule: the Guardians' CSV is served, the Volunteers' is "not found".
    expect(await fileStatus(page, packs.operations.id, cardIdOf(packs.operations, CARD.rota))).toBe(
      200,
    )
    expect(
      await fileStatus(page, packs.volunteersOnly.id, cardIdOf(packs.volunteersOnly, CARD.signUp)),
    ).toBe(404)

    // Admission is not a key to anything else: not the privileged library, not management or its preview, not Relationships.
    for (const path of [
      VIEWER_LIBRARY,
      `${ROOT}/packs`,
      `${ROOT}/packs/${packs.single.id}/preview`,
      `${RELATIONSHIPS}/guardians`,
    ]) {
      expect((await apiFrom(page, 'GET', path)).status, path).toBe(403)
    }

    // On screen: the audience reader, with the Guardian Packs, the shared Pack, and none of the rest.
    await libraryHeading(page)
    await expect(page.getByRole('link', { name: PACK.shared, exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: PACK.single, exact: true })).toBeVisible()
    const body = page.locator('body')
    for (const absent of [
      PACK.draft,
      PACK.membersOnly,
      PACK.volunteersOnly,
      'Draft',
      'For Guardians',
    ]) {
      await expect(body).not.toContainText(absent)
    }
    await expect(page.getByRole('link', { name: 'Relationships' })).toHaveCount(0)
    await page.context().close()
  })

  test('a Console Participant with no relationship and no Membership: admitted, and the empty library', async ({
    browser,
    baseURL,
  }) => {
    const page = await persona(browser, baseURL, 'persona-participant')
    expect(await capabilitiesOf(page)).toEqual(['console.access'])
    expect(await deliveredAll(page)).toEqual([])
    await libraryHeading(page)
    await expect(page.getByText('There are no Resources for you yet.')).toBeVisible()
    await page.context().close()
  })

  test('a Console Participant who is an Active Volunteer: the Volunteer Resources, and no Guardian eligibility from being admitted', async ({
    browser,
    baseURL,
  }) => {
    const page = await persona(browser, baseURL, 'persona-volunteer')
    const packs = await demo(browser)
    expect(await capabilitiesOf(page)).toEqual(['console.access'])
    expect(await deliveredDemoTitles(page)).toEqual(eligibleFor('volunteer'))
    expect(await deliveredCards(page, packs.shared.id)).toEqual({
      status: 200,
      titles: [CARD.dayPlan, CARD.stations],
    })
    expect((await deliveredCards(page, packs.single.id)).status).toBe(404)
    expect(
      await fileStatus(page, packs.volunteersOnly.id, cardIdOf(packs.volunteersOnly, CARD.signUp)),
    ).toBe(200)
    expect(await fileStatus(page, packs.operations.id, cardIdOf(packs.operations, CARD.rota))).toBe(
      404,
    )

    // On screen, and by search: the Volunteer-only Pack's word finds it; a Guardian Pack's title finds nothing.
    await libraryHeading(page)
    await expect(page.getByRole('link', { name: PACK.volunteersOnly, exact: true })).toBeVisible()
    await expect(page.locator('body')).not.toContainText(PACK.single)
    const search = page.getByRole('search', { name: 'Find Resources' })
    await search.getByRole('searchbox').fill(VOLUNTEER_WORD)
    await search.getByRole('button', { name: 'Search' }).click()
    await expect(page.getByRole('link', { name: PACK.volunteersOnly, exact: true })).toBeVisible()
    await search.getByRole('searchbox').fill('hosting')
    await search.getByRole('button', { name: 'Search' }).click()
    await expect(page.getByRole('link', { name: PACK.shared, exact: true })).toHaveCount(0)
    await page.context().close()
  })

  test('a Guardian, Volunteer and Member: the union of all three, each source independent', async ({
    browser,
    baseURL,
  }) => {
    const page = await persona(browser, baseURL, 'persona-multi')
    const packs = await demo(browser)
    expect(await deliveredDemoTitles(page)).toEqual(eligibleFor('guardian', 'volunteer', 'member'))
    // OR, card by card: both narrowed Cards of the shared Pack, and the Member-only Card in the Guardian Series.
    expect(await deliveredCards(page, packs.shared.id)).toEqual({
      status: 200,
      titles: [CARD.dayPlan, CARD.stations, CARD.hosting],
    })
    expect((await deliveredCards(page, packs.narrowed.id)).titles).toEqual([
      CARD.welcome,
      CARD.hidden,
      CARD.firstShift,
      CARD.handover,
    ])
    // Never a Draft, whoever is asking.
    expect((await deliveredCards(page, packs.draft.id)).status).toBe(404)
    expect((await deliveredCards(page, packs.shared.id)).titles).not.toContain(CARD.rain)
    await page.context().close()
  })

  test('a former Guardian who is a Pending Volunteer: neither Inactive nor Pending qualifies', async ({
    browser,
    baseURL,
  }) => {
    const page = await persona(browser, baseURL, 'persona-former')
    expect(await capabilitiesOf(page)).toEqual(['console.access'])
    expect(await deliveredAll(page)).toEqual([])
    const account = await accountOf(browser, 'e2e.persona.former@example.org')
    const admin = workerSession(browser)
    const guardian = await admin.request(
      'GET',
      `${RELATIONSHIPS}/guardians/${account.personId}/management`,
    )
    const volunteer = await admin.request(
      'GET',
      `${RELATIONSHIPS}/volunteers/${account.personId}/management`,
    )
    expect((guardian.body as { status: string }).status).toBe('inactive')
    expect((volunteer.body as { status: string }).status).toBe('pending')
    // The relationship-managed grant was withdrawn when the Guardian became Inactive; the independent role is untouched.
    expect(account.sourced).toEqual([])
    expect(account.assignments).toEqual(['console-participant'])
    await page.context().close()
  })
})

test.describe('the browser cannot make authority', () => {
  test('no parameter, header, Card id or other client reaches more than the persona is served', async ({
    browser,
    baseURL,
  }) => {
    const page = await persona(browser, baseURL, 'persona-initiate')
    const packs = await demo(browser)
    const plain = await apiFrom(page, 'GET', LIBRARY)
    const elevations =
      '?audience=volunteer&audiences[]=member&audiences[]=volunteer&mode=viewer&publication=draft&state=draft&as=admin'
    expect(await apiFrom(page, 'GET', `${LIBRARY}${elevations}`)).toEqual(plain)
    expect(await apiFrom(page, 'GET', `${LIBRARY}/packs/${packs.shared.id}${elevations}`)).toEqual(
      await apiFrom(page, 'GET', `${LIBRARY}/packs/${packs.shared.id}`),
    )
    // The privileged library refuses the claim, and so does the file route of a Card narrowed away from this persona.
    expect((await apiFrom(page, 'GET', `${VIEWER_LIBRARY}${elevations}`)).status).toBe(403)
    const stations = cardIdOf(packs.shared, CARD.stations)
    expect(await fileStatus(page, packs.shared.id, stations)).toBe(404)

    // A deep link naming the hidden Card reads the first delivered one, and the hidden Card's words never reach the page.
    await page.goto(`/resource-library/${packs.shared.id}?card=${stations}`)
    await expect(page.getByRole('heading', { level: 1, name: PACK.shared })).toBeVisible()
    await expect(page.getByRole('article')).toContainText(CARD.dayPlan)
    await expect(page.locator('body')).not.toContainText(CARD.stations)
    await expect(page.locator('body')).not.toContainText('welcome table')
    // A Draft Pack's address is "not found", exactly as an unknown one.
    await page.goto(`/resource-library/${packs.draft.id}`)
    await expect(page.getByRole('heading', { level: 1, name: 'Resource not found' })).toBeVisible()
    await expect(page.locator('body')).not.toContainText(PACK.draft)
    for (const capability of ADMIN_ONLY)
      expect(await capabilitiesOf(page)).not.toContain(capability)
    await page.context().close()
  })
})
