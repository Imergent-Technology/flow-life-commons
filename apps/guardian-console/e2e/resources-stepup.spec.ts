import { expect, test, type Browser, type Page } from '@playwright/test'

import {
  makeBasicCard,
  makeCategory,
  makeFileCard,
  makePack,
  packOf,
  pdfBytes,
  RemoveAfter,
  ROOT,
  unique,
} from './resources.ts'
import { apiFrom, recoveryCodesFor, signedInAs, type FixtureSession } from './support.ts'

// Permanent deletion of a Card or a Pack needs `resources.manage` AND a recent proof of who is asking (ADR 0037, decision 53);
// everything routine does not. These journeys use sessions whose last proof is OLDER than the 15 minutes the platform allows
// (the platform minted them so, by its own sign-in with the clock moved back), so the proof is genuinely due: the platform refuses
// the deletion, the Console opens the established "Confirm it is you" prompt, and a right proof lets a second, deliberate press
// through. Meanwhile the same stale session does every routine thing without being asked for anything.
//
// Each journey has a stale session of its own, because proving freshens a session. They prove with RECOVERY CODES (single use, no
// authenticator time step to wait for or share with another journey).

test.describe.configure({ timeout: 180_000 })

const removal = new RemoveAfter()

async function staleOn(
  browser: Browser,
  baseURL: string | undefined,
  as: FixtureSession,
): Promise<Page> {
  const page = await signedInAs(browser, baseURL ?? '', as)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  return page
}

/** The prompt's proof form, filled in with a recovery code. `password` is wrong on purpose for the refusal. */
async function prove(page: Page, password: string, recoveryCode: string): Promise<void> {
  const prompt = page.getByRole('dialog', { name: 'Confirm it is you' })
  await expect(prompt).toBeVisible()
  await prompt.getByLabel('Current password').fill(password)
  if ((await prompt.getByLabel('Recovery code').count()) === 0) {
    await prompt.getByRole('button', { name: 'Use a recovery code instead' }).click()
  }
  await prompt.getByLabel('Recovery code').fill(recoveryCode)
  await prompt.getByRole('button', { name: 'Confirm' }).click()
}

const PASSWORD = 'e2e-admin-guardian-password-not-a-secret'
const CODES = recoveryCodesFor('S')

/** What `GET /me` says the session's last proof was: null while it is due. */
async function verifiedUntil(page: Page): Promise<string | null> {
  const me = (await apiFrom(page, 'GET', '/api/v1/me')).body as {
    mfa: { security_verified_until: string | null }
  }
  return me.mfa.security_verified_until
}

async function cleanUp(browser: Browser, baseURL: string | undefined): Promise<void> {
  // A fresh session (proved at the start of the run) can delete what the stale ones made.
  const page = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
  await page.goto('/')
  await removal.run(page)
  await page.context().close()
}

test.describe.serial('deleting a Card needs a recent proof; nothing routine does', () => {
  let page: Page
  let packId = ''
  let cardId = ''
  let fileCardId = ''
  const names = { card: unique('E2E Doomed Card'), file: unique('E2E File Card') }

  test.beforeAll(async ({ browser, baseURL }) => {
    page = await staleOn(browser, baseURL, 'resources-stale-card')
    // Everything below is routine, so a session whose proof is due may do all of it.
    const category = await makeCategory(page)
    removal.category(category.id)
    const pack = await makePack(page, { categoryId: category.id, audiences: ['guardian'] })
    removal.pack(pack.id)
    packId = pack.id
    cardId = (await makeBasicCard(page, packId, { title: names.card })).id
    fileCardId = (await makeFileCard(page, packId, { title: names.file })).id
  })

  test.afterAll(async ({ browser, baseURL }) => {
    await page.context().close()
    await cleanUp(browser, baseURL)
  })

  test('the session’s proof is due, and the platform refuses a deletion outright', async () => {
    expect(await verifiedUntil(page)).toBeNull()
    const refused = await apiFrom(page, 'DELETE', `${ROOT}/packs/${packId}/cards/${cardId}`)
    expect(refused.status).toBe(403)
    expect(refused.body).toMatchObject({ verification_required: true })
    expect((await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${cardId}`)).status).toBe(200)
  })

  test('editing, publishing, reordering and replacing a file are routine: no prompt, and the platform accepts them', async () => {
    await page.goto(`/resources/packs/${packId}/cards/${cardId}`)
    await page.getByLabel('Title').fill(`${names.card} (edited)`)
    await page.getByRole('button', { name: 'Save Card' }).click()
    await expect(page.getByText('The Card was saved.')).toBeVisible()
    await page.getByRole('button', { name: 'Publish Card' }).click()
    await expect(page.getByText('The Card is now Published.')).toBeVisible()

    await page.goto(`/resources/packs/${packId}`)
    await page.getByRole('button', { name: `Move ${names.file} up` }).click()
    await expect(page.getByText(`Moved ${names.file} to position 1 of 2.`)).toBeAttached()

    await page.goto(`/resources/packs/${packId}/cards/${fileCardId}`)
    await page
      .getByLabel('Replacement file')
      .setInputFiles({ name: 'second.pdf', mimeType: 'application/pdf', buffer: pdfBytes('two') })
    await page.getByRole('button', { name: 'Replace file' }).click()
    await expect(
      page.getByText('The file was replaced. The Card now offers the new file.'),
    ).toBeVisible()

    await page.goto('/resources/categories')
    const another = unique('E2E Another')
    await page.getByLabel('New Category').fill(another)
    await page.getByRole('button', { name: 'Add Category' }).click()
    await expect(page.getByText(`The Category “${another}” was created.`)).toBeVisible()
    const listed = (await apiFrom(page, 'GET', `${ROOT}/categories`)).body as {
      data: { id: string; name: string }[]
    }
    removal.category(listed.data.find((c) => c.name === another)?.id ?? '')

    await expect(page.getByRole('dialog', { name: 'Confirm it is you' })).toHaveCount(0)
    expect(await verifiedUntil(page)).toBeNull() // still due: none of that needed, or gave, a proof
  })

  test('deleting the Card opens the proof prompt; a wrong password is refused; a right proof closes it WITHOUT deleting; a second press deletes', async () => {
    await page.goto(`/resources/packs/${packId}/cards/${cardId}`)
    await page.getByRole('button', { name: 'Delete Card…' }).click()
    const confirm = page.getByRole('dialog', { name: 'Permanently delete this Card?' })
    await expect(confirm).toContainText('It cannot be restored.')
    await confirm.getByRole('button', { name: 'Delete permanently' }).click()

    await prove(page, 'not the password', CODES[3] ?? '')
    const prompt = page.getByRole('dialog', { name: 'Confirm it is you' })
    await expect(prompt.getByText('The current password is incorrect.')).toBeVisible()
    expect((await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${cardId}`)).status).toBe(200)

    await prove(page, PASSWORD, CODES[5] ?? '')
    await expect(prompt).toHaveCount(0)
    // Verified, and STILL nothing has been deleted: the person is told to confirm again.
    await expect(confirm.getByText(/confirm again to continue/)).toBeVisible()
    expect(await verifiedUntil(page)).not.toBeNull()
    expect((await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${cardId}`)).status).toBe(200)

    await confirm.getByRole('button', { name: 'Delete permanently' }).click()
    await expect(
      page.getByText(`The Card “${names.card} (edited)” was permanently deleted.`),
    ).toBeVisible()
    await expect(page).toHaveURL(new RegExp(`/resources/packs/${packId}$`))
    expect((await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${cardId}`)).status).toBe(404)
    expect((await packOf(page, packId)).cards.map((c) => c.id)).toEqual([fileCardId])
  })

  test('once verified, the next deletion goes straight through, with no prompt', async () => {
    await page.goto(`/resources/packs/${packId}/cards/${fileCardId}`)
    await page.getByRole('button', { name: 'Delete Card…' }).click()
    const confirm = page.getByRole('dialog', { name: 'Permanently delete this Card?' })
    await expect(confirm).toContainText(
      'Its managed file is removed from the store once the deletion succeeds.',
    )
    await confirm.getByRole('button', { name: 'Delete permanently' }).click()

    await expect(page.getByText(`The Card “${names.file}” was permanently deleted.`)).toBeVisible()
    await expect(page.getByRole('dialog', { name: 'Confirm it is you' })).toHaveCount(0)
    expect((await packOf(page, packId)).cards).toEqual([])
  })
})

test.describe.serial('deleting a Pack needs a recent proof too', () => {
  let page: Page
  let packId = ''
  let fileCardId = ''
  const title = unique('E2E Doomed Pack')

  test.beforeAll(async ({ browser, baseURL }) => {
    page = await staleOn(browser, baseURL, 'resources-stale-pack')
    const category = await makeCategory(page)
    removal.category(category.id)
    const pack = await makePack(page, { title, categoryId: category.id, audiences: ['guardian'] })
    removal.pack(pack.id)
    packId = pack.id
    await makeBasicCard(page, packId)
    fileCardId = (await makeFileCard(page, packId)).id
  })

  test.afterAll(async ({ browser, baseURL }) => {
    await page.context().close()
    await cleanUp(browser, baseURL)
  })

  test('the dialog says exactly what goes; the platform refuses without a proof; the Console proves, then deletes on the second press', async () => {
    expect(await verifiedUntil(page)).toBeNull()
    const refused = await apiFrom(page, 'DELETE', `${ROOT}/packs/${packId}`)
    expect(refused.status).toBe(403)
    expect(refused.body).toMatchObject({ verification_required: true })

    await page.goto(`/resources/packs/${packId}`)
    await page.getByRole('button', { name: 'Delete Resource Pack…' }).click()
    const confirm = page.getByRole('dialog', { name: 'Permanently delete this Pack?' })
    await expect(confirm).toContainText('all 2 Cards in it')
    await expect(confirm).toContainText(
      'The managed file of its File Card is removed from the store once the deletion succeeds.',
    )
    await expect(confirm).toContainText('It cannot be restored.')
    await confirm.getByRole('button', { name: 'Delete permanently' }).click()

    await prove(page, 'not the password', CODES[2] ?? '')
    await expect(
      page
        .getByRole('dialog', { name: 'Confirm it is you' })
        .getByText('The current password is incorrect.'),
    ).toBeVisible()
    expect((await apiFrom(page, 'GET', `${ROOT}/packs/${packId}`)).status).toBe(200)

    await prove(page, PASSWORD, CODES[1] ?? '')
    await expect(page.getByRole('dialog', { name: 'Confirm it is you' })).toHaveCount(0)
    await expect(confirm.getByText(/confirm again to continue/)).toBeVisible()
    expect((await apiFrom(page, 'GET', `${ROOT}/packs/${packId}`)).status).toBe(200)

    await confirm.getByRole('button', { name: 'Delete permanently' }).click()
    await expect(
      page.getByText(`The Resource Pack “${title}” was permanently deleted.`),
    ).toBeVisible()
    await expect(page).toHaveURL(/\/resources$/)
    expect((await apiFrom(page, 'GET', `${ROOT}/packs/${packId}`)).status).toBe(404)
    expect((await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${fileCardId}`)).status).toBe(
      404,
    )
    expect(
      (await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${fileCardId}/file`)).status,
    ).toBe(404)
  })
})
