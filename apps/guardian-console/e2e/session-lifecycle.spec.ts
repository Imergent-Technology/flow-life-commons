import { expect, test } from '@playwright/test'

import { apiFrom, recoveryCodesFor, replayedStatus, sessionCookie, signedInAs } from './support.ts'
import { VerifiedSession, verifiedAdminPage } from './verified.ts'

// The browser suite's session lifecycle (G10 WP7 e2e stabilization). Journeys must not depend on how long the suite has been running
// since it was seeded, and these prove the parts that make that so, without waiting 15 or 30 real minutes:
//   - a session minted STALE (its last proof older than the platform's 15 minutes) is nevertheless alive and authenticated when a journey
//     starts, and the platform still asks it to prove (the journeys about step-up depend on both);
//   - proving rotates only the session that proves: the one it replaces is dead, and another session of the same Account, held by another
//     journey, is neither signed out nor given a proof it did not make;
//   - a journey that needs a recent proof gets one from the worker's own verified session, whatever the age of the minted ones;
//   - a second sign-in of an Account (a worker that was replaced signs in again) leaves the first sign-in's session alone.
// Minted sessions are kept from idling out by E2eSessionKeepAlive (backend `E2eSessionKeepAliveTest`); a session that has idled out is
// exactly the Sign-in page the `resources-stepup` journeys met, and is covered there.
//
// All four use the Volunteer manager of E2eAccountSeeder::VOLUNTEER_MANAGER, which holds the Console user role and nothing in use by any
// other journey but `relationships.spec`'s Volunteer-deletion journey, which has a session and recovery codes of its own (9 and 0). This
// file uses recovery codes 8 and 6 to sign its two minted sessions in and 7 to prove, and the Account's authenticator for the fourth.

test.describe.configure({ timeout: 240_000 })

const MANAGER = {
  email: 'e2e.persona.volmanager@example.org',
  password: 'e2e-persona-volmanager-password-not-a-secret',
  secret: 'T2DMZVACSB32R6KLLIJASUSSQRHTK3AW',
}
const CODES = recoveryCodesFor('PA')

// A deletion that asks for a recent proof before it looks at anything else: the verification check is a route middleware, so a person and
// a relationship that do not exist are all it needs. Once the session has proved, the platform answers about the missing relationship
// instead, which is how a test can tell "asked" from "accepted" without deleting anything.
const ABSENT = '01arz3ndektsv4rrffq69g5fav'
const PROTECTED = `/api/v1/admin/relationships/volunteers/${ABSENT}?relationship_id=${ABSENT}&revision=1`

// What a session that HAS a recent proof is answered: the platform got past the verification check and looked for the relationship.
// Asserting this exact answer, not merely "not asked", is what stops a mistyped route (which is also not a 403) from passing.
const acceptedProof = (r: { status: number; body: unknown }): boolean =>
  r.status === 404 &&
  typeof r.body === 'object' &&
  r.body !== null &&
  (r.body as { code?: unknown }).code === 'relationship_not_found'

const asksForProof = (r: { status: number; body: unknown }): boolean =>
  r.status === 403 &&
  typeof r.body === 'object' &&
  r.body !== null &&
  (r.body as { verification_required?: unknown }).verification_required === true

test('a session minted stale is alive and authenticated, and the platform still asks it to prove', async ({
  browser,
  baseURL,
}) => {
  const page = await signedInAs(browser, baseURL ?? '', 'lifecycle-b-stale')
  await page.goto('/api/v1/health')

  expect((await apiFrom(page, 'GET', '/api/v1/me')).status).toBe(200)
  expect(asksForProof(await apiFrom(page, 'DELETE', PROTECTED))).toBe(true)
  // Asking again changes nothing: it is still alive, and still asked.
  expect((await apiFrom(page, 'GET', '/api/v1/me')).status).toBe(200)
  expect(asksForProof(await apiFrom(page, 'DELETE', PROTECTED))).toBe(true)
  await page.context().close()
})

test('proving rotates only the session that proves; another session of the Account is neither signed out nor given the proof', async ({
  browser,
  baseURL,
  request,
}) => {
  const proving = await signedInAs(browser, baseURL ?? '', 'lifecycle-a-stale')
  const bystander = await signedInAs(browser, baseURL ?? '', 'lifecycle-b-stale')
  await proving.goto('/api/v1/health')
  await bystander.goto('/api/v1/health')
  const provingBefore = (await sessionCookie(proving.context()))?.value ?? ''
  const bystanderBefore = (await sessionCookie(bystander.context()))?.value ?? ''
  expect(provingBefore).not.toBe('')
  expect(bystanderBefore).not.toBe('')
  expect(asksForProof(await apiFrom(proving, 'DELETE', PROTECTED))).toBe(true)

  const proof = await apiFrom(proving, 'POST', '/api/v1/security/verify', {
    current_password: MANAGER.password,
    recovery_code: CODES[7],
  })
  expect(proof.status, JSON.stringify(proof.body)).toBe(204)

  // The session that proved is now accepted (the platform answers about the missing relationship, not about a proof), and the cookie
  // it held before is dead: the proof was real, and it replaced that session's id.
  const accepted = await apiFrom(proving, 'DELETE', PROTECTED)
  expect(acceptedProof(accepted), JSON.stringify(accepted.body)).toBe(true)
  expect(await replayedStatus(request, baseURL ?? '', provingBefore)).toBe(401)

  // The other session of the same Account is untouched: alive, with the same cookie, and still without a proof.
  expect(await replayedStatus(request, baseURL ?? '', bystanderBefore)).toBe(200)
  expect((await apiFrom(bystander, 'GET', '/api/v1/me')).status).toBe(200)
  expect(asksForProof(await apiFrom(bystander, 'DELETE', PROTECTED))).toBe(true)
  await proving.context().close()
  await bystander.context().close()
})

test('a journey that needs a recent proof gets it from the worker’s verified session, however old the minted sessions are', async ({
  browser,
  baseURL,
}) => {
  const page = await verifiedAdminPage(browser, baseURL)

  const answer = await apiFrom(page, 'DELETE', PROTECTED)
  expect(acceptedProof(answer), JSON.stringify(answer.body)).toBe(true)
  await page.context().close()
})

test('signing in again as an Account, as a replaced worker does, leaves the first sign-in’s session alone, and a proof stays with the session that made it', async ({
  browser,
  baseURL,
  request,
}) => {
  const first = new VerifiedSession(browser, baseURL ?? '', MANAGER)
  const second = new VerifiedSession(browser, baseURL ?? '', MANAGER)
  try {
    const firstCookie = await first.sessionCookie()
    const secondCookie = await second.sessionCookie()
    expect(await replayedStatus(request, baseURL ?? '', firstCookie)).toBe(200)
    expect(await replayedStatus(request, baseURL ?? '', secondCookie)).toBe(200)
    // A fresh sign-in with a second factor IS a verification: neither was asked to prove, and neither has.
    expect(first.proofs + second.proofs).toBe(0)
    expect(acceptedProof(await apiFrom(await first.ready(), 'DELETE', PROTECTED))).toBe(true)
    expect(acceptedProof(await apiFrom(await second.ready(), 'DELETE', PROTECTED))).toBe(true)

    // The first proves again, on purpose (as a session does when the platform asks): that replaces the first's id and no other.
    const proving = await apiFrom(await first.ready(), 'POST', '/api/v1/security/verify', {
      current_password: MANAGER.password,
      recovery_code: CODES[5],
    })
    expect(proving.status, JSON.stringify(proving.body)).toBe(204)
    expect(await replayedStatus(request, baseURL ?? '', firstCookie)).toBe(401)
    expect(await replayedStatus(request, baseURL ?? '', secondCookie)).toBe(200)
  } finally {
    await first.close()
    await second.close()
  }
})
