import { expect, test, type Browser, type Page } from '@playwright/test'

import {
  LIBRARY,
  makeBasicCard,
  makeCategory,
  makePack,
  makeUploadCard,
  packOf,
  PNG_BASE64,
  pdfBytes,
  publishPack,
  RemoveAfter,
  ROOT,
  unique,
  signedInAsReader,
  VIEWER_LIBRARY,
} from './resources.ts'
import { cardAddress } from './resourcesDemo.ts'
import { apiFrom, recoveryCodesFor, signedInAs, type FixtureSession } from './support.ts'

// Resources, closed out (ADR 0037, G5 Work Package 6): the whole product as one thing, in real Chromium through the real gateway and the
// real platform. A Guardian AUTHORS (management), the platform PUBLISHES, and a Guardian READS (the library), and each journey here goes
// round that loop with nothing in between intercepted: what is written in management is what the library delivers, and when it changes
// there, it changes here. The seeded dataset is read in resources-demo.spec.ts. Every name is random and what a journey makes it removes.

test.describe.configure({ timeout: 240_000 })

const removal = new RemoveAfter()

async function guardian(
  browser: Browser,
  baseURL: string | undefined,
  as: FixtureSession = 'plain-guardian',
): Promise<Page> {
  const page = await signedInAs(browser, baseURL ?? '', as)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  return page
}

/**
 * Searches the library the way a reader does, and waits for the platform's answer to it. `family` is the route that answers: the audience
 * reader's `my-resources` for someone without `resources.view` (the default), or the privileged viewer's `resource-library`.
 */
async function searchLibrary(
  page: Page,
  text: string,
  family: 'my-resources' | 'resource-library' = 'my-resources',
): Promise<void> {
  await page.goto('/resource-library')
  await expect(page.getByRole('heading', { level: 1, name: 'Resource Library' })).toBeVisible()
  await page.getByLabel('Search Resources').fill(text)
  const answered = page.waitForResponse(
    (response) =>
      response.url().includes(`/api/v1/admin/${family}?`) && response.url().includes('q='),
  )
  await page
    .getByRole('search', { name: 'Find Resources' })
    .getByRole('button', { name: 'Search' })
    .click()
  await answered
}

/** Finds a Pack in the library the way a reader does: by searching for it, then following its link. */
async function openFromLibrary(
  page: Page,
  title: string,
  family: 'my-resources' | 'resource-library' = 'my-resources',
): Promise<void> {
  await searchLibrary(page, title, family)
  await page.getByRole('link', { name: title, exact: true }).click()
  await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible()
}

const cardNav = (page: Page) => page.getByRole('navigation', { name: 'Cards in this Resource' })

interface Fetching {
  fetch: (
    path: string,
  ) => Promise<{ status: number; headers: { get: (name: string) => string | null } }>
}

async function fetchedFrom(
  page: Page,
  path: string,
): Promise<{ status: number; type: string; disposition: string }> {
  return page.evaluate(async (path) => {
    const response = await (globalThis as unknown as Fetching).fetch(path)
    return {
      status: response.status,
      type: response.headers.get('content-type') ?? '',
      disposition: response.headers.get('content-disposition') ?? '',
    }
  }, path)
}

async function idOfCategory(page: Page, name: string): Promise<string> {
  const listed = (await apiFrom(page, 'GET', `${ROOT}/categories`)).body as {
    data: { id: string; name: string }[]
  }
  return listed.data.find((c) => c.name === name)?.id ?? ''
}

// --- Management to the library, and back -----------------------------------------------------------------------------------------

test.describe.serial('a Resource, from the first word to a reader and back again', () => {
  const names = {
    category: unique('E2E Closeout Category'),
    pack: unique('E2E Closeout Pack'),
    card: unique('E2E Opening times'),
    summary: 'Written in the browser, for Guardians.',
    editedSummary: 'Corrected after a reader asked.',
  }
  // `page` authors (and, holding `resources.view`, would read everything); `guardianPage` reads as a Guardian is served (policy C).
  let page: Page
  let guardianPage: Page
  let packId = ''
  let cardId = ''
  const asked: string[] = []

  test.beforeAll(async ({ browser, baseURL }) => {
    page = await guardian(browser, baseURL)
    guardianPage = await signedInAsReader(browser, baseURL)
    guardianPage.on('request', (request) => asked.push(request.url()))
  })

  test.afterAll(async () => {
    await removal.run(page)
    await page.context().close()
    await guardianPage.context().close()
  })

  test('before anything is written, the library does not have it', async () => {
    for (const reading of [guardianPage, page]) {
      await reading.goto('/resource-library')
      await expect(
        reading.getByRole('heading', { level: 1, name: 'Resource Library' }),
      ).toBeVisible()
      await expect(reading.getByRole('link', { name: names.pack })).toHaveCount(0)
    }
  })

  test('a Category, a Pack aimed at Guardians, and a Card with rich content are authored in management', async () => {
    await page.goto('/resources/categories')
    await page.getByLabel('New Category').fill(names.category)
    await page.getByRole('button', { name: 'Add Category' }).click()
    await expect(page.getByText(`The Category “${names.category}” was created.`)).toBeVisible()
    removal.category(await idOfCategory(page, names.category))

    await page.goto('/resources/new')
    await page.getByLabel('Title').fill(names.pack)
    await page.getByLabel('Summary').fill(names.summary)
    await page.getByLabel('Category').selectOption({ label: names.category })
    await page.getByRole('button', { name: 'Create Resource Pack' }).click()
    await expect(page.getByRole('heading', { level: 1, name: names.pack })).toBeVisible()
    packId = /\/packs\/([^/]+)$/.exec(page.url())?.[1] ?? ''
    expect(packId).not.toBe('')
    removal.pack(packId)

    const audiences = page.getByRole('form', { name: 'Pack audiences' })
    await audiences.getByRole('checkbox', { name: 'Guardians' }).check()
    await audiences.getByRole('button', { name: 'Save audiences' }).click()
    await expect(page.getByText('The Pack’s audiences were saved.')).toBeVisible()

    // A heading and a bulleted list, made with the editor's own toolbar (loaded on demand), not typed as markup.
    await page.getByRole('link', { name: 'Add a Card' }).click()
    await page.getByLabel('Title').fill(names.card)
    await page.getByRole('textbox', { name: 'Content' }).click()
    await page.getByRole('button', { name: 'Heading 2' }).click()
    await page.keyboard.type('Opening times')
    await page.keyboard.press('Enter')
    await page.getByRole('button', { name: 'Bulleted list' }).click()
    await page.keyboard.type('Doors open at nine')
    await page.keyboard.press('Enter')
    await page.keyboard.type('Tea is at ten')
    await page.getByRole('button', { name: 'Create Card' }).click()
    await expect(page.getByRole('heading', { level: 1, name: names.card })).toBeVisible()
    cardId = /\/cards\/([^/]+)$/.exec(page.url())?.[1] ?? ''

    const saved = (await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${cardId}`)).body as {
      content: { document: { content: { type: string }[] } }
    }
    expect(saved.content.document.content.map((node) => node.type)).toEqual([
      'heading',
      'bulletList',
    ])
  })

  test('it is still not in the library while it is a Draft, and is once the Card and then the Pack are published', async () => {
    // A Draft Pack is simply not there for a reader.
    await searchLibrary(guardianPage, names.pack)
    await expect(guardianPage.getByText('No Resources match.')).toBeVisible()
    expect((await apiFrom(guardianPage, 'GET', `${LIBRARY}/packs/${packId}`)).status).toBe(404)

    // And it is there, marked as a Draft, for someone who holds `resources.view`: a different policy answers them.
    await searchLibrary(page, names.pack, 'resource-library')
    const entry = page.getByRole('listitem').filter({
      has: page.getByRole('link', { name: names.pack, exact: true }),
    })
    await expect(entry).toContainText('Draft')
    await expect(entry).toContainText('For Guardians')
    expect((await apiFrom(page, 'GET', `${VIEWER_LIBRARY}/packs/${packId}`)).status).toBe(200)

    await page.goto(`/resources/packs/${packId}/cards/${cardId}`)
    await page.getByRole('button', { name: 'Publish Card' }).click()
    await expect(page.getByText('The Card is now Published.')).toBeVisible()
    await page.goto(`/resources/packs/${packId}`)
    await page.getByRole('button', { name: 'Publish Pack' }).click()
    await expect(page.getByText('The Pack is now Published.')).toBeVisible()
  })

  test('a Guardian finds it in the library under its Category, opens it, and reads what was authored', async () => {
    asked.length = 0
    await openFromLibrary(guardianPage, names.pack)

    await expect(guardianPage.getByText(names.summary)).toBeVisible()
    await expect(guardianPage.getByText(`In ${names.category}`)).toBeVisible()
    // One Card: read plainly, its title under the Pack's, with the authored heading and list.
    await expect(guardianPage.getByRole('heading', { level: 2, name: names.card })).toBeVisible()
    await expect(
      guardianPage.getByRole('heading', { level: 2, name: 'Opening times', exact: true }),
    ).toBeVisible()
    await expect(
      guardianPage.getByRole('listitem').filter({ hasText: 'Doors open at nine' }),
    ).toBeVisible()
    await expect(
      guardianPage.getByRole('listitem').filter({ hasText: 'Tea is at ten' }),
    ).toBeVisible()
    await expect(cardNav(guardianPage)).toHaveCount(0)

    const library = (await apiFrom(guardianPage, 'GET', LIBRARY)).body as {
      data: { category: { name: string }; packs: { title: string; card_count: number }[] }[]
    }
    const entry = library.data.find((group) => group.category.name === names.category)
    expect(entry?.packs).toEqual([expect.objectContaining({ title: names.pack, card_count: 1 })])
    // Reading asked the audience reader and never management, and never the privileged viewer.
    expect(asked.filter((url) => url.includes('/api/v1/admin/resources'))).toEqual([])
    expect(asked.filter((url) => url.includes('/api/v1/admin/resource-library'))).toEqual([])
  })

  test('the Pack and the Card are edited in management, and the library delivers the change', async () => {
    await page.goto(`/resources/packs/${packId}`)
    const details = page.getByRole('form', { name: 'Pack details' })
    await details.getByLabel('Summary').fill(names.editedSummary)
    await details.getByRole('button', { name: 'Save details' }).click()
    await expect(page.getByText('The Pack’s details were saved.')).toBeVisible()

    await page.goto(`/resources/packs/${packId}/cards/${cardId}`)
    await page.getByLabel('Title').fill(`${names.card} (updated)`)
    await page.getByRole('textbox', { name: 'Content' }).click()
    await page.keyboard.press('Control+End')
    await page.keyboard.type(', with biscuits')
    await page.getByRole('button', { name: 'Save Card' }).click()
    await expect(page.getByText('The Card was saved.')).toBeVisible()

    await guardianPage.goto(`/resource-library/${packId}`)
    await expect(guardianPage.getByText(names.editedSummary)).toBeVisible()
    await expect(guardianPage.getByText(names.summary)).toHaveCount(0)
    await expect(
      guardianPage.getByRole('heading', { level: 2, name: `${names.card} (updated)` }),
    ).toBeVisible()
    await expect(
      guardianPage.getByRole('listitem').filter({ hasText: 'Tea is at ten, with biscuits' }),
    ).toBeVisible()
    await expect(
      guardianPage.getByRole('listitem').filter({ hasText: 'Doors open at nine' }),
    ).toBeVisible()
  })

  test('unpublishing it takes it out of the library, and publishing it puts it back', async () => {
    await page.goto(`/resources/packs/${packId}`)
    await page.getByRole('button', { name: 'Unpublish Pack' }).click()
    await expect(page.getByText('The Pack is now a Draft again.')).toBeVisible()

    // Gone, and indistinguishable from a Pack that was never there.
    await guardianPage.goto(`/resource-library/${packId}`)
    await expect(
      guardianPage.getByRole('heading', { level: 1, name: 'Resource not found' }),
    ).toBeVisible()
    await searchLibrary(guardianPage, names.pack)
    await expect(guardianPage.getByText('No Resources match.')).toBeVisible()

    await page.goto(`/resources/packs/${packId}`)
    await page.getByRole('button', { name: 'Publish Pack' }).click()
    await expect(page.getByText('The Pack is now Published.')).toBeVisible()
    await guardianPage.goto(`/resource-library/${packId}`)
    await expect(guardianPage.getByRole('heading', { level: 1, name: names.pack })).toBeVisible()
  })
})

// --- A file, from upload to replacement -----------------------------------------------------------------------------------------

test.describe.serial('a File Card, uploaded in management and presented by the library', () => {
  const title = unique('E2E Closeout File')
  let page: Page
  let guardianPage: Page
  let packId = ''
  let packTitle = ''
  let cardId = ''
  const asked: string[] = []

  test.beforeAll(async ({ browser, baseURL }) => {
    page = await guardian(browser, baseURL)
    guardianPage = await signedInAsReader(browser, baseURL)
    const category = await makeCategory(page)
    removal.category(category.id)
    const pack = await makePack(page, { categoryId: category.id, audiences: ['guardian'] })
    removal.pack(pack.id)
    packId = pack.id
    packTitle = pack.title
    await makeBasicCard(page, packId, { title: unique('E2E Intro'), publish: true })
    await publishPack(page, packId)
    guardianPage.on('request', (request) => asked.push(request.url()))
  })

  test.afterAll(async () => {
    await removal.run(page)
    await page.context().close()
    await guardianPage.context().close()
  })

  test('an image File Card is created and published in management', async () => {
    await page.goto(`/resources/packs/${packId}/cards/new`)
    await page.getByRole('radio', { name: /^File/ }).check()
    await page.getByLabel('Title').fill(title)
    await page.getByLabel('File', { exact: true }).setInputFiles({
      name: 'Closeout plan.png',
      mimeType: 'image/png',
      buffer: Buffer.from(PNG_BASE64, 'base64'),
    })
    await page.getByRole('button', { name: 'Create Card' }).click()
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible()
    cardId = /\/cards\/([^/]+)$/.exec(page.url())?.[1] ?? ''
    await page.getByRole('button', { name: 'Publish Card' }).click()
    await expect(page.getByText('The Card is now Published.')).toBeVisible()
  })

  test('the library shows the image in the Card, through its own authorized route', async () => {
    asked.length = 0
    await guardianPage.goto(cardAddress(packId, cardId))

    await expect(cardNav(guardianPage).getByRole('link', { name: title })).toHaveAttribute(
      'aria-current',
      'true',
    )
    const image = guardianPage.getByRole('img', { name: title })
    await expect(image).toBeVisible()
    await expect(image).toHaveJSProperty('complete', true)
    await expect(image).toHaveJSProperty('naturalWidth', 1)
    expect(await image.getAttribute('src')).toBe(
      `${LIBRARY}/packs/${packId}/cards/${cardId}/file?disposition=inline`,
    )
    await expect(guardianPage.locator('dd', { hasText: 'Closeout plan.png' })).toBeVisible()
    // The file came from the audience reader's route; neither the management route nor the privileged viewer's was asked.
    expect(asked.some((url) => url.includes(`/my-resources/packs/${packId}/`))).toBe(true)
    expect(asked.filter((url) => url.includes('/api/v1/admin/resources'))).toEqual([])
    expect(asked.filter((url) => url.includes('/api/v1/admin/resource-library'))).toEqual([])
    expect(await guardianPage.locator('body').innerText()).not.toMatch(
      /storage|digest|sha-?256|private/i,
    )
  })

  test('the file is replaced in management, and the library presents the replacement', async () => {
    await page.goto(`/resources/packs/${packId}/cards/${cardId}`)
    await page.getByLabel('Replacement file').setInputFiles({
      name: 'Closeout guide.pdf',
      mimeType: 'application/pdf',
      buffer: pdfBytes('replacement'),
    })
    await page.getByRole('button', { name: 'Replace file' }).click()
    await expect(
      page.getByText('The file was replaced. The Card now offers the new file.'),
    ).toBeVisible()

    await guardianPage.goto(cardAddress(packId, cardId))
    // The image is gone and a PDF is offered in its place, on the same Card and the same address.
    await expect(guardianPage.getByRole('img', { name: title })).toHaveCount(0)
    const view = guardianPage.getByRole('link', { name: /^View PDF/ })
    const href = (await view.getAttribute('href')) ?? ''
    expect(href).toBe(`${LIBRARY}/packs/${packId}/cards/${cardId}/file?disposition=inline`)
    await expect(view).toHaveAttribute('target', '_blank')
    await expect(view).toHaveAttribute('rel', 'noopener noreferrer')
    await expect(guardianPage.locator('dd', { hasText: 'Closeout guide.pdf' })).toBeVisible()
    await expect(guardianPage.locator('dd', { hasText: 'Closeout plan.png' })).toHaveCount(0)

    const served = await fetchedFrom(guardianPage, href)
    expect(served.status).toBe(200)
    expect(served.type).toContain('application/pdf')
    expect(served.disposition).toMatch(/^inline/)

    const [download] = await Promise.all([
      guardianPage.waitForEvent('download'),
      guardianPage.getByRole('link', { name: /^Download/ }).click(),
    ])
    expect(download.suggestedFilename()).toBe('Closeout guide.pdf')
    const chunks: Buffer[] = []
    for await (const chunk of await download.createReadStream()) chunks.push(chunk as Buffer)
    expect(Buffer.concat(chunks).equals(pdfBytes('replacement'))).toBe(true)
  })

  test('an unpublished File Card is not served by the library, whatever address is tried', async () => {
    await page.goto(`/resources/packs/${packId}/cards/${cardId}`)
    await page.getByRole('button', { name: 'Unpublish Card' }).click()
    await expect(page.getByText('The Card is now a Draft again.')).toBeVisible()

    // A reader is not served the unpublished Card's file, by any address: the one ordinary "not found".
    const refused = await apiFrom(
      guardianPage,
      'GET',
      `${LIBRARY}/packs/${packId}/cards/${cardId}/file`,
    )
    expect(refused.status).toBe(404)
    expect(refused.body).toMatchObject({ code: 'resource_pack_not_found' })

    // A holder of `resources.view` IS served it: the privileged viewer reads every Card, Drafts included, and its files.
    const viewed = await fetchedFrom(page, `${VIEWER_LIBRARY}/packs/${packId}/cards/${cardId}/file`)
    expect(viewed.status).toBe(200)
    expect(viewed.type).toContain('application/pdf')

    // And the Pack, now with one Card for a reader, is read plainly: the unpublished Card is not a hint of anything.
    await openFromLibrary(guardianPage, packTitle)
    await expect(cardNav(guardianPage)).toHaveCount(0)
    await expect(guardianPage.locator('body')).not.toContainText(title)
    expect((await packOf(page, packId)).cards.map((c) => c.state)).toContain('draft')
  })
})

// --- Deleting for good ----------------------------------------------------------------------------------------------------------

const PASSWORD = 'e2e-admin-guardian-password-not-a-secret'
const CODES = recoveryCodesFor('S')

test.describe
  .serial('deleting a File Card for good takes it out of the library, and needs a recent proof', () => {
  const doomed = unique('E2E Doomed File')
  const kept = unique('E2E Kept Card')
  let stale: Page
  let reader: Page
  let cleaner: Page
  let packId = ''
  let cardId = ''
  let keptId = ''

  test.beforeAll(async ({ browser, baseURL }) => {
    // The session's last proof is OLDER than the 15 minutes the platform allows: everything routine is fine, deletion is not.
    stale = await guardian(browser, baseURL, 'resources-stale-library')
    reader = await signedInAsReader(browser, baseURL)
    cleaner = await guardian(browser, baseURL)
    const category = await makeCategory(stale)
    removal.category(category.id)
    const pack = await makePack(stale, { categoryId: category.id, audiences: ['guardian'] })
    removal.pack(pack.id)
    packId = pack.id
    keptId = (await makeBasicCard(stale, packId, { title: kept, publish: true })).id
    cardId = (
      await makeUploadCard(stale, packId, {
        title: doomed,
        name: 'doomed.pdf',
        type: 'application/pdf',
        text: pdfBytes('doomed').toString('latin1'),
        publish: true,
      })
    ).id
    await publishPack(stale, packId)
  })

  test.afterAll(async () => {
    await stale.context().close()
    await removal.run(cleaner)
    await cleaner.context().close()
    await reader.context().close()
  })

  test('while the Card exists, a reader is served its file', async () => {
    const served = await fetchedFrom(reader, `${LIBRARY}/packs/${packId}/cards/${cardId}/file`)
    expect(served.status).toBe(200)
    expect(served.type).toContain('application/pdf')
    await reader.goto(cardAddress(packId, cardId))
    await expect(reader.getByRole('link', { name: /^View PDF/ })).toBeVisible()
  })

  test('a session whose proof is due is refused the deletion by the platform, and the Card stays', async () => {
    const refused = await apiFrom(stale, 'DELETE', `${ROOT}/packs/${packId}/cards/${cardId}`)
    expect(refused.status).toBe(403)
    expect(refused.body).toMatchObject({ verification_required: true })
    expect((await apiFrom(stale, 'GET', `${ROOT}/packs/${packId}/cards/${cardId}`)).status).toBe(
      200,
    )
    expect(
      (await fetchedFrom(reader, `${LIBRARY}/packs/${packId}/cards/${cardId}/file`)).status,
    ).toBe(200)
  })

  test('the Console asks for the proof, deletes on the second deliberate press, and the library stops serving the file at once', async () => {
    await stale.goto(`/resources/packs/${packId}/cards/${cardId}`)
    await stale.getByRole('button', { name: 'Delete Card…' }).click()
    const confirm = stale.getByRole('dialog', { name: 'Permanently delete this Card?' })
    await confirm.getByRole('button', { name: 'Delete permanently' }).click()

    const prompt = stale.getByRole('dialog', { name: 'Confirm it is you' })
    await expect(prompt).toBeVisible()
    await prompt.getByLabel('Current password').fill(PASSWORD)
    await prompt.getByRole('button', { name: 'Use a recovery code instead' }).click()
    await prompt.getByLabel('Recovery code').fill(CODES[8] ?? '')
    await prompt.getByRole('button', { name: 'Confirm' }).click()
    await expect(prompt).toHaveCount(0)
    // Proved, and still not deleted: the person confirms again.
    expect((await apiFrom(stale, 'GET', `${ROOT}/packs/${packId}/cards/${cardId}`)).status).toBe(
      200,
    )
    await confirm.getByRole('button', { name: 'Delete permanently' }).click()
    await expect(stale.getByText(`The Card “${doomed}” was permanently deleted.`)).toBeVisible()

    // Gone from management, and from the library: its file route is the ordinary "not found", and so is any address that names it.
    expect((await apiFrom(stale, 'GET', `${ROOT}/packs/${packId}/cards/${cardId}`)).status).toBe(
      404,
    )
    const gone = await apiFrom(reader, 'GET', `${LIBRARY}/packs/${packId}/cards/${cardId}/file`)
    expect(gone.status).toBe(404)
    expect(gone.body).toMatchObject({ code: 'resource_pack_not_found' })
  })

  test('the stale address of the deleted Card reads what is left, saying nothing', async () => {
    await reader.goto(cardAddress(packId, cardId))

    // One Card is left for a reader: read plainly, with nothing of the one that was deleted.
    await expect(reader.getByText(kept)).toBeVisible()
    await expect(reader.getByRole('alert')).toHaveCount(0)
    await expect(reader.getByRole('link', { name: /View PDF|Download/ })).toHaveCount(0)
    await expect(reader.locator('body')).not.toContainText(doomed)
    await expect(reader.getByRole('navigation', { name: 'Cards in this Resource' })).toHaveCount(0)
    expect(
      (await packOf(cleaner, packId)).cards.map((c) => c.id),
      'management agrees',
    ).toEqual([keptId])
  })
})
