import { expect, test, type Browser, type Page } from '@playwright/test'

import {
  apiFrom,
  captureConsole,
  expectNoTokenShapedText,
  expectOnlyUiPreferences,
  goToAccountSecurity,
  invitationEmailedTo,
  meStatus,
  messageIdsTo,
  mailpit,
  nextCode,
  signedInAs,
  signOut,
  unique,
} from './support.ts'

// WP5 (ADR 0032, ADR 0033, docs/architecture/member-access.md): end-to-end validation of the Member
// foundation, in real Chromium through the real gateway. Two independent journeys, each proving a
// composition across layers that no lower-level test proves on its own:
//
//   Journey A — an existing Person with a Membership grant and no Account is invited to Commons by an
//   operator through the real Console (Work Package 5's own gap, closed by the Guardian package this
//   file validates), accepts through real Mailpit mail, and reaches their own /my/ with that same
//   Membership record intact.
//
//   Journey B — an existing password-only, non-Console Account is promoted to console.access by an
//   operator through the real Console, and the backend's own second-factor policy (ConsoleMultiFactorPolicy
//   / EndSessionWithoutSecondFactor, ADR 0023) — not this test — ends its already-open session and
//   requires MFA enrolment before it may enter the Console.
//
// Each journey's mutated identity is its own and read by nothing else in this suite (the WP4 audit
// lesson, applied here): Journey A creates a fresh Person+Membership+invitee email per run, suffixed with
// a random id, so parallel runs and workers cannot collide over it; Journey B's Account is
// E2eAccountSeeder's own PROMOTION_* fixture, reset every run and read by no other spec file. Both
// journeys' OPERATOR is the pre-minted `admin-read` session (a real Platform Administrator, freshly
// verified) that administration.spec.ts and membership.spec.ts already share for read/mutate-elsewhere
// journeys: it is not itself mutated by anything here, only the people it acts on are.

const consoleHeading = (page: Page) => page.getByRole('heading', { level: 1, name: 'Overview' })
const loginHeading = (page: Page) => page.getByRole('heading', { level: 1, name: 'Sign in' })
const homeHeading = (page: Page) => page.getByRole('heading', { level: 1, name: 'Home' })

async function freshPage(browser: Browser, baseURL: string): Promise<Page> {
  return (await browser.newContext({ baseURL })).newPage()
}

async function fillLogin(page: Page, email: string, password: string) {
  await page.goto('/login')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

/** An Account's id and owning Person's id, asked of the API as an operator's search would (administration.spec.ts's own pattern). */
async function accountOf(page: Page, email: string): Promise<{ id: string; personId: string }> {
  const listed = await apiFrom(page, 'GET', `/api/v1/admin/accounts?q=${encodeURIComponent(email)}`)
  const rows = (listed.body as { data: { id: string; email: string; person_id: string }[] }).data
  const found = rows.find((row) => row.email === email)
  if (found === undefined) throw new Error(`No account for ${email}.`)
  return { id: found.id, personId: found.person_id }
}

/** Opens an account's page by searching for it, as an operator would (administration.spec.ts's own pattern). */
async function openAccount(page: Page, email: string, name: string) {
  await page.goto('/admin/accounts')
  await page.getByLabel('Name or email').fill(email)
  await page.getByRole('button', { name: 'Search' }).click()
  await page.getByRole('link', { name, exact: true }).click()
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
}

interface CommonsAccess {
  commons_access: { state: string; can_invite: boolean }
}

async function commonsAccessOf(page: Page, personId: string): Promise<CommonsAccess> {
  const result = await apiFrom(page, 'GET', `/api/v1/admin/people/${personId}/commons-access`)
  expect(result.status).toBe(200)
  return result.body as CommonsAccess
}

interface AdminMember {
  person: { id: string; display_name: string }
  active: boolean
  open_ended: boolean
  grants: { starts_at: string; ends_at: string | null; revoked_at: string | null }[]
}

async function memberOf(page: Page, personId: string): Promise<AdminMember> {
  const result = await apiFrom(page, 'GET', `/api/v1/admin/members/${personId}`)
  expect(result.status).toBe(200)
  return result.body as AdminMember
}

test.describe.serial('Journey A — inviting an existing Person to Commons (WP5, ADR 0032)', () => {
  const suffix = crypto.randomUUID().slice(0, 8)
  const displayName = `E2E Journey A ${suffix}`
  const invitee = {
    email: `e2e.journey-a.${suffix}@example.org`,
    password: unique('journey a'),
  }
  const day = 86_400_000
  // Whole seconds: the backend's own instants are (credentials.spec.ts's own comment says why), so an
  // exact round-trip comparison later needs no separate truncation on the way back.
  const wholeSecond = (at: number) => new Date(Math.floor(at / 1000) * 1000).toISOString()
  const startsAt = wholeSecond(Date.now() - 30 * day)
  const endsAt = wholeSecond(Date.now() + 60 * day)

  let admin: Page
  let person: Page
  let personId: string

  test.beforeAll(async ({ browser, baseURL }) => {
    admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
    await admin.goto('/') // apiFrom evaluates a same-origin fetch; the minted session starts at about:blank
    person = await freshPage(browser, baseURL ?? '')

    // The fixture: a real Person with a real, currently-active Membership grant, created through the
    // same admin API membership.spec.ts's own "Add member" UI flow drives — an actual repository write,
    // not a browser-side fake — and NO Account. A random suffix keeps it dedicated to this run.
    const created = await apiFrom(admin, 'POST', '/api/v1/admin/members', {
      display_name: displayName,
      starts_at: startsAt,
      open_ended: false,
      ends_at: endsAt,
      source: 'operator',
      source_reference: null,
    })
    expect(created.status).toBe(201)
    // The record itself, not wrapped in `data` (MembershipPresenter::record; the list endpoint wraps,
    // this one does not).
    personId = (created.body as { person: { id: string } }).person.id
  })

  test('begins with a real Person, a real active Membership grant, and no Account', async () => {
    const member = await memberOf(admin, personId)
    expect(member.person.display_name).toBe(displayName)
    expect(member.active).toBe(true) // proves the Membership grant, not merely the Person
    expect(member.grants).toHaveLength(1)

    // No Account exists yet: asked of Identity through Access's own read, not inferred from the browser.
    const access = await commonsAccessOf(admin, personId)
    expect(access.commons_access).toEqual({ state: 'not_invited', can_invite: true })
    expect(
      (await apiFrom(admin, 'GET', `/api/v1/admin/accounts?q=${encodeURIComponent(invitee.email)}`))
        .body,
    ).toMatchObject({ data: [] })
  })

  test('the operator opens Member detail through the real Console and sees the uninvited state', async () => {
    await admin.goto(`/admin/members/${personId}`)
    await expect(admin.getByRole('heading', { level: 1, name: displayName })).toBeVisible()
    await expect(admin.getByText('Active', { exact: true })).toBeVisible()

    // Membership and Commons-access are two separate reads, composed on the one page.
    await expect(admin.getByRole('heading', { name: 'Commons Account' })).toBeVisible()
    await expect(admin.getByLabel('Email address')).toBeVisible()
    await expect(admin.getByRole('button', { name: 'Invite to Commons' })).toBeVisible()
  })

  test('the operator invites them through the real UI, and Mailpit delivers a neutral invitation', async ({
    baseURL,
    request,
  }) => {
    await admin.getByLabel('Email address').fill(invitee.email)
    await admin.getByRole('button', { name: 'Invite to Commons' }).click()

    await expect(admin.getByText(/Invited\. The invitation went to/)).toBeVisible()
    await expect(admin.getByText(invitee.email)).toBeVisible()
    await expectNoTokenShapedText(admin)

    // Server truth, not UI optimism: the read model now says invited, and the Account belongs to the
    // SAME Person — no duplicate was created — while the Membership record is untouched.
    expect((await commonsAccessOf(admin, personId)).commons_access).toEqual({
      state: 'invited',
      can_invite: false,
    })
    const account = await accountOf(admin, invitee.email)
    expect(account.personId).toBe(personId)
    const memberAfter = await memberOf(admin, personId)
    expect(memberAfter.active).toBe(true)
    expect(memberAfter.grants).toHaveLength(1)
    // The SAME grant seeded before the Account existed, unmoved by Account creation.
    expect(Date.parse(memberAfter.grants[0]?.starts_at ?? '')).toBe(Date.parse(startsAt))
    expect(Date.parse(memberAfter.grants[0]?.ends_at ?? '')).toBe(Date.parse(endsAt))
    expect(memberAfter.grants[0]?.revoked_at).toBeNull()

    const mail = await invitationEmailedTo(request, baseURL ?? '', invitee.email)
    expect(mail.link).toContain('/accept-invitation#token=') // in the FRAGMENT, out of access logs
    expect(mail.link).not.toContain('?token=')
    expect(mail.text).toContain('Flow Life Commons')
    expect(mail.text).not.toMatch(/Guardian Console/)
    expect(mail.text).toMatch(/within \d+ days?/) // the TTL

    // The subject line too: neutral, the same wording every invitation uses (WP3).
    const ids = await messageIdsTo(request, baseURL ?? '', invitee.email)
    const message = await mailpit(request, baseURL ?? '', `/api/v1/message/${ids[0] ?? ''}`)
    expect(((await message.json()) as { Subject: string }).Subject).toBe(
      'You have been invited to Flow Life Commons',
    )

    await person.goto(mail.link)
  })

  test('the invitee accepts through the neutral Commons page, and the Account becomes active', async () => {
    const logged = captureConsole(person)
    await expect(person.getByText('Your invitation link was recognised.')).toBeVisible()
    await expect(person.getByText('Guardian Console')).toHaveCount(0) // neutral throughout (WP3)

    await person.getByLabel('New password', { exact: true }).fill(invitee.password)
    await person.getByLabel('Confirm new password').fill(invitee.password)
    await person.getByRole('button', { name: 'Set password and activate' }).click()

    await expect(
      person.getByRole('heading', { level: 1, name: 'Invitation accepted' }),
    ).toBeVisible()
    expect(await meStatus(person)).toBe(401) // accepting signs no one in
    await expectNoTokenShapedText(person)
    expect(logged.join('\n')).not.toContain(invitee.password)

    // Consumed, and the Account is active, before any sign-in at all.
    expect((await commonsAccessOf(admin, personId)).commons_access).toEqual({
      state: 'active',
      can_invite: false,
    })
  })

  test('the new Member signs in, lands at /my, and their Membership record is unchanged', async () => {
    await person.getByRole('link', { name: 'Continue to sign in' }).click()
    await fillLogin(person, invitee.email, invitee.password)

    // No role was assigned (WP1's InviteExistingPerson gives none), so this is a one-step, non-Console
    // sign-in straight to the Member surface — not the Console, and not a second-factor challenge.
    await expect(homeHeading(person)).toBeVisible()
    expect(new URL(person.url()).pathname).toBe('/my')
    expect(await meStatus(person)).toBe(200)
    await expect(person.getByRole('navigation', { name: 'Console' })).toHaveCount(0)
    await expect(person.getByText('Guardian Console')).toHaveCount(0)
    await expect(person.getByText('Flow Life Commons')).toBeVisible()
    await expect(person.getByRole('navigation', { name: 'Member' })).toBeVisible()

    await person
      .getByRole('navigation', { name: 'Member' })
      .getByRole('link', { name: 'Membership' })
      .click()
    await expect(person.getByRole('heading', { level: 1, name: 'Membership' })).toBeVisible()
    await expect(person.getByText('Active', { exact: true })).toBeVisible()
    await expect(person.getByText(/Access through/)).toBeVisible()

    // Identity continuity, proved directly rather than inferred from matching text: the SAME grant that
    // existed before the Account did (compared against the exact instants this journey seeded, not
    // against another read of the same post-Account state), now visible through self-service, with no
    // admin-only field leaked.
    const own = await apiFrom(person, 'GET', '/api/v1/my/membership')
    const body = own.body as {
      active: boolean
      open_ended: boolean
      grants: { starts_at: string; ends_at: string | null; revoked: boolean }[]
    }
    expect(body.active).toBe(true)
    expect(body.open_ended).toBe(false)
    expect(body.grants).toHaveLength(1)
    const grant = body.grants[0]
    expect(grant?.revoked).toBe(false)
    expect(Date.parse(grant?.starts_at ?? '')).toBe(Date.parse(startsAt))
    expect(Date.parse(grant?.ends_at ?? '')).toBe(Date.parse(endsAt))
    expect(Object.keys(body).sort()).toEqual(
      ['active', 'current_access_ends_at', 'open_ended', 'grants'].sort(),
    )
    expect(Object.keys(grant ?? {}).sort()).toEqual(['starts_at', 'ends_at', 'revoked'].sort())
  })

  test('/my/security offers only self-service: no MFA prompt, and no Guardian-only route', async () => {
    await goToAccountSecurity(person)
    await expect(person.getByRole('heading', { level: 1, name: 'Security' })).toBeVisible()
    expect(new URL(person.url()).pathname).toBe('/my/security')
    await expect(person.getByRole('heading', { name: 'Two-step verification' })).toHaveCount(0)
    await expect(person.getByRole('button', { name: /set up.*authenticator/i })).toHaveCount(0)
    await expect(person.getByLabel('Current password')).toBeVisible()
    await expect(person.getByLabel('New password', { exact: true })).toBeVisible()
    await expectOnlyUiPreferences(person)
  })

  test('signs out through the real Member menu', async () => {
    await signOut(person)
    await expect(loginHeading(person)).toBeVisible()
    expect(await meStatus(person)).toBe(401)

    await person.context().close()
  })
})

test.describe
  .serial('Journey B — promoting an existing Account to console.access (WP5, ADR 0023)', () => {
  const PROMOTION = {
    email: 'e2e.member.promotion@example.org',
    name: 'E2E Member Promotion',
    password: 'e2e-member-promotion-password-not-a-secret',
  }

  let target: Page
  let secret = ''

  test('establishes a password-only Member session before promotion', async ({
    browser,
    baseURL,
  }) => {
    target = await freshPage(browser, baseURL ?? '')
    await fillLogin(target, PROMOTION.email, PROMOTION.password)

    await expect(homeHeading(target)).toBeVisible()
    expect(new URL(target.url()).pathname).toBe('/my')
    expect(await meStatus(target)).toBe(200)
    await expect(target.getByRole('navigation', { name: 'Console' })).toHaveCount(0)
  })

  test('an operator grants console.access through the real UI, and the existing session does not silently become privileged', async ({
    browser,
    baseURL,
  }) => {
    const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
    await openAccount(admin, PROMOTION.email, PROMOTION.name)
    await expect(admin.getByText('They hold no access.')).toBeVisible()

    // The real management seam: the Account detail page's own "Give them access" / "Add access", the
    // same one administration.spec.ts already proves for an already-enrolled operator — exercised here,
    // for the first time in this suite, against a previously password-only Member Account.
    await admin.getByLabel('Give them access').selectOption({ label: 'Guardian' })
    await admin.getByRole('button', { name: 'Add access' }).click()
    await admin
      .getByRole('dialog', { name: `Give ${PROMOTION.name} the “Guardian” access?` })
      .getByRole('button', { name: 'Give access' })
      .click()
    await expect(admin.getByText(/now has “Guardian” access/)).toBeVisible()

    // Back on the ALREADY-OPEN password-only session, still mounted at /my (a reload would just remount
    // AuthProvider fresh and never show the notice below; the backend policy fires on the session's next
    // REQUEST, so a live in-page navigation is what proves it, exactly as console.spec.ts's own stale-
    // cookie journey does). The backend's own second-factor policy ends it on that very next request
    // (EnforceSecondFactorWhereDue / EndSessionWithoutSecondFactor), load-bearing, not a client-side
    // capability change this test merely asserts away.
    await target
      .getByRole('navigation', { name: 'Member' })
      .getByRole('link', { name: 'Membership' })
      .click()
    await expect(loginHeading(target)).toBeVisible()
    await expect(
      target.getByText('Your session has ended. Sign in again to continue.'),
    ).toBeVisible()
    expect(await meStatus(target)).toBe(401) // the backend's own truth, not merely what the page shows
    await expect(target.getByRole('navigation', { name: 'Console' })).toHaveCount(0)
    await expect(consoleHeading(target)).toHaveCount(0)

    await admin.context().close()
    await target.context().close()
  })

  test('signing in again requires MFA enrolment before the Console, not before', async ({
    browser,
    baseURL,
  }) => {
    test.setTimeout(90_000)
    const back = await freshPage(browser, baseURL ?? '')
    const logged = captureConsole(back)

    await fillLogin(back, PROMOTION.email, PROMOTION.password)
    await expect(
      back.getByRole('heading', { level: 1, name: 'Set up two-step verification' }),
    ).toBeVisible()
    expect(await meStatus(back)).toBe(401) // the password alone is not a session: enrolment first

    await back.getByRole('button', { name: 'Set up authenticator' }).click()
    secret = ((await back.locator('code').first().textContent()) ?? '').replace(/\s/g, '')
    expect(secret).toMatch(/^[A-Z2-7]{32}$/)
    await back.getByLabel('Authentication code').fill(await nextCode(secret))
    await back.getByRole('button', { name: 'Verify and continue' }).click()
    await expect(back.getByRole('list', { name: 'Recovery codes' })).toBeVisible()
    await back.getByLabel('I have saved these recovery codes somewhere safe.').check()
    await back.getByRole('button', { name: 'Continue to the Console' }).click()

    await expect(consoleHeading(back)).toBeVisible()
    expect(new URL(back.url()).pathname).toBe('/') // the Guardian default, not /my
    await expect(back.getByRole('navigation', { name: 'Console' })).toBeVisible()
    await expect(back.getByRole('navigation', { name: 'Member' })).toHaveCount(0)

    const me = (await apiFrom(back, 'GET', '/api/v1/me')).body as { capabilities: string[] }
    expect(me.capabilities).toContain('console.access')

    await expectOnlyUiPreferences(back)
    const output = logged.join('\n')
    for (const forbidden of [secret, PROMOTION.password]) expect(output).not.toContain(forbidden)

    await back.context().close()
  })
})
