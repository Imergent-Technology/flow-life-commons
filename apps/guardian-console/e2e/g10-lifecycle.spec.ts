import { expect, test, type Page } from '@playwright/test'

import {
  accountOf,
  capabilitiesOf,
  deliveredCards,
  demo,
  fileStatus,
  persona,
  RELATIONSHIPS,
  relationshipOf,
} from './g10.ts'
import { LIBRARY } from './resources.ts'
import { cardIdOf, CARD, PACK } from './resourcesDemo.ts'
import { apiFrom, signedInAs } from './support.ts'
import { workerSession } from './verified.ts'

// G10 end to end (ADR 0038, WP7): a relationship's lifecycle changes what is sourced from it, on the very next request, and nothing
// else. Each journey CHANGES its own persona (E2eAccountSeeder::PERSONA_LIFECYCLE_*), which no other journey reads, so they run in
// parallel safely; within a journey the steps are in order. The administrator is the worker's own (e2e/verified.ts), so the recent
// proof that Guardian status changes and deletion need is never spent on a session anyone else holds. The exhaustive race and
// rollback cases are the backend's (RelationshipsRaceTest, GuardianProvisioningTest); these show the seam end to end.

test.describe.configure({ timeout: 180_000 })

const KEPT = 'e2e.persona.lifecycle.kept@example.org'
const SOLE = 'e2e.persona.lifecycle.sole@example.org'

type Slug = 'guardians' | 'volunteers'

async function setStatus(
  admin: ReturnType<typeof workerSession>,
  slug: Slug,
  personId: string,
  current: { id: string; revision: number },
  status: string,
  defaultRole?: 'grant' | 'decline',
): Promise<{ status: number; body: unknown }> {
  return admin.request('PUT', `${RELATIONSHIPS}/${slug}/${personId}/status`, {
    relationship_id: current.id,
    revision: current.revision,
    status,
    ...(defaultRole !== undefined && { default_role: defaultRole }),
  })
}

const notFoundOnScreen = async (page: Page, packId: string): Promise<void> => {
  await page.goto(`/resource-library/${packId}`)
  await expect(page.getByRole('heading', { level: 1, name: 'Resource not found' })).toBeVisible()
}

test('admission kept: inactivating, reactivating, deleting and re-recording a Guardian changes exactly what the Guardian relationship gave', async ({
  browser,
  baseURL,
}) => {
  const admin = workerSession(browser, baseURL)
  const page = await persona(browser, baseURL, 'persona-lifecycle-kept')
  const packs = await demo(browser)
  const rota = cardIdOf(packs.operations, CARD.rota)
  const start = await accountOf(browser, KEPT)
  const person = start.personId

  // Before: an Active Guardian (with relationship-managed Initiate access) and an Active Volunteer, admitted by an independent role.
  expect(start.assignments).toEqual(['console-participant'])
  expect(start.sourced).toEqual(['guardian-initiate'])
  expect(await deliveredCards(page, packs.shared.id)).toEqual({
    status: 200,
    titles: [CARD.dayPlan, CARD.stations, CARD.hosting],
  })
  expect((await deliveredCards(page, packs.single.id)).status).toBe(200)
  expect(await fileStatus(page, packs.operations.id, rota)).toBe(200)

  // Inactive: the next request no longer has Guardian eligibility, the relationship-managed grant is withdrawn, the independent role is not.
  const active = await relationshipOf(browser, 'guardians', person)
  expect(active?.status).toBe('active')
  expect(
    (await setStatus(admin, 'guardians', person, active ?? { id: '', revision: 0 }, 'inactive'))
      .status,
  ).toBe(200)
  const inactive = await relationshipOf(browser, 'guardians', person)
  expect(inactive).toEqual({
    id: active?.id,
    revision: (active?.revision ?? 0) + 1,
    status: 'inactive',
  })
  expect(await capabilitiesOf(page)).toEqual(['console.access'])
  expect((await deliveredCards(page, packs.single.id)).status).toBe(404)
  expect(await fileStatus(page, packs.operations.id, rota)).toBe(404)
  expect(await deliveredCards(page, packs.shared.id)).toEqual({
    status: 200,
    titles: [CARD.dayPlan, CARD.stations],
  })
  const afterInactive = await accountOf(browser, KEPT)
  expect(afterInactive.sourced).toEqual([])
  expect(afterInactive.assignments).toEqual(['console-participant'])
  await notFoundOnScreen(page, packs.single.id)
  await expect(page.locator('body')).not.toContainText(PACK.single)

  // Reactivated, with the grant chosen again: the SAME relationship, and everything it gave is back.
  expect(
    (
      await setStatus(
        admin,
        'guardians',
        person,
        inactive ?? { id: '', revision: 0 },
        'active',
        'grant',
      )
    ).status,
  ).toBe(200)
  const reactivated = await relationshipOf(browser, 'guardians', person)
  expect(reactivated?.id).toBe(active?.id)
  expect(reactivated?.status).toBe('active')
  expect((await deliveredCards(page, packs.single.id)).status).toBe(200)
  expect((await accountOf(browser, KEPT)).sourced).toEqual(['guardian-initiate'])

  // The Volunteer relationship lives its own life: Inactive takes the Volunteer Resources away (and leaves the Guardian ones).
  const volunteer = await relationshipOf(browser, 'volunteers', person)
  expect(
    (await setStatus(admin, 'volunteers', person, volunteer ?? { id: '', revision: 0 }, 'inactive'))
      .status,
  ).toBe(200)
  expect((await deliveredCards(page, packs.volunteersOnly.id)).status).toBe(404)
  expect(await deliveredCards(page, packs.shared.id)).toEqual({
    status: 200,
    titles: [CARD.dayPlan, CARD.hosting],
  })
  const volunteerInactive = await relationshipOf(browser, 'volunteers', person)
  expect(
    (
      await setStatus(
        admin,
        'volunteers',
        person,
        volunteerInactive ?? { id: '', revision: 0 },
        'active',
      )
    ).status,
  ).toBe(200)
  expect((await relationshipOf(browser, 'volunteers', person))?.id).toBe(volunteer?.id)
  expect((await deliveredCards(page, packs.volunteersOnly.id)).status).toBe(200)

  // A Volunteer manager (the Guardian role's holder, who may manage Volunteers and not Guardians) corrects this Guardian-and-Volunteer's
  // phone through the scoped path: the Guardian relationship, the grant and the Account's security are untouched.
  const guardianBefore = await relationshipOf(browser, 'guardians', person)
  const manager = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
  await manager.goto('/api/v1/health')
  const view = await apiFrom(manager, 'GET', `${RELATIONSHIPS}/volunteers/${person}/management`)
  expect(view.status).toBe(200)
  const basics = view.body as { relationship_id: string; basics: { primary_phone: string | null } }
  const edited = await apiFrom(manager, 'PATCH', `${RELATIONSHIPS}/volunteers/${person}/basics`, {
    relationship_id: basics.relationship_id,
    primary_phone: { from: basics.basics.primary_phone, to: '+1 555 010 0177' },
  })
  expect(edited.status, JSON.stringify(edited.body)).toBe(200)
  expect(await relationshipOf(browser, 'guardians', person)).toEqual(guardianBefore)
  const afterEdit = await accountOf(browser, KEPT)
  expect(afterEdit.sourced).toEqual(['guardian-initiate'])
  expect(afterEdit.assignments).toEqual(['console-participant'])
  expect((await apiFrom(page, 'GET', '/api/v1/me')).status).toBe(200) // the persona's session survives: no security change
  // A Volunteer manager has no Guardian route at all.
  expect(
    (
      await apiFrom(manager, 'PUT', `${RELATIONSHIPS}/guardians/${person}/status`, {
        relationship_id: guardianBefore?.id,
        revision: guardianBefore?.revision,
        status: 'inactive',
      })
    ).status,
  ).toBe(403)
  await manager.context().close()

  // Permanent deletion withdraws the grant; recording again makes a NEW instance at revision 1.
  const before = await relationshipOf(browser, 'guardians', person)
  const deleted = await admin.request(
    'DELETE',
    `${RELATIONSHIPS}/guardians/${person}?relationship_id=${before?.id ?? ''}&revision=${String(before?.revision ?? 0)}`,
  )
  expect(deleted.status, JSON.stringify(deleted.body)).toBe(204)
  expect((await deliveredCards(page, packs.single.id)).status).toBe(404)
  expect((await accountOf(browser, KEPT)).sourced).toEqual([])
  const again = await admin.request('POST', `${RELATIONSHIPS}/guardians`, {
    person_id: person,
    confirm_existing_person: true,
    status: 'active',
    fields: {},
    default_role: 'decline',
  })
  expect(again.status, JSON.stringify(again.body)).toBe(201)
  const replacement = await relationshipOf(browser, 'guardians', person)
  expect(replacement?.id).not.toBe(before?.id)
  expect(replacement?.revision).toBe(1)

  // A request naming the deleted instance cannot touch the replacement, even at the replacement's revision.
  for (const stale of [
    await setStatus(
      admin,
      'guardians',
      person,
      { id: before?.id ?? '', revision: before?.revision ?? 0 },
      'inactive',
    ),
    await setStatus(admin, 'guardians', person, { id: before?.id ?? '', revision: 1 }, 'inactive'),
    await admin.request(
      'DELETE',
      `${RELATIONSHIPS}/guardians/${person}?relationship_id=${before?.id ?? ''}&revision=1`,
    ),
  ]) {
    expect(stale.status, JSON.stringify(stale.body)).toBe(409)
    expect((stale.body as { code: string }).code).toBe('stale_revision')
  }
  expect(await relationshipOf(browser, 'guardians', person)).toEqual(replacement)
  // Declined this time: a Guardian again (eligible), with no relationship-managed grant.
  expect((await deliveredCards(page, packs.single.id)).status).toBe(200)
  expect((await accountOf(browser, KEPT)).sourced).toEqual([])
  await page.context().close()
})

test('admission from the relationship alone: inactivation shuts the Console door on the next request, reactivation with the grant opens it again', async ({
  browser,
  baseURL,
}) => {
  const admin = workerSession(browser, baseURL)
  const page = await persona(browser, baseURL, 'persona-lifecycle-sole')
  const packs = await demo(browser)
  const person = (await accountOf(browser, SOLE)).personId
  expect(await capabilitiesOf(page)).toEqual(['console.access'])
  expect((await deliveredCards(page, packs.single.id)).status).toBe(200)

  const active = await relationshipOf(browser, 'guardians', person)
  expect(
    (await setStatus(admin, 'guardians', person, active ?? { id: '', revision: 0 }, 'inactive'))
      .status,
  ).toBe(200)
  // The same session, the next request: no Console admission at all, so policy C refuses at the door (not "not found").
  expect(await capabilitiesOf(page)).toEqual([])
  expect((await apiFrom(page, 'GET', LIBRARY)).status).toBe(403)
  expect((await apiFrom(page, 'GET', `${LIBRARY}/packs/${packs.single.id}`)).status).toBe(403)
  await page.goto('/resource-library')
  await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()
  await expect(page.locator('body')).not.toContainText(PACK.single)

  const inactive = await relationshipOf(browser, 'guardians', person)
  expect(
    (
      await setStatus(
        admin,
        'guardians',
        person,
        inactive ?? { id: '', revision: 0 },
        'active',
        'grant',
      )
    ).status,
  ).toBe(200)
  expect(await capabilitiesOf(page)).toEqual(['console.access'])
  expect((await deliveredCards(page, packs.single.id)).status).toBe(200)
  await page.goto('/resource-library')
  await expect(page.getByRole('heading', { level: 1, name: 'Resource Library' })).toBeVisible()
  await expect(page.getByRole('link', { name: PACK.single, exact: true })).toBeVisible()
  await page.context().close()
})
