import { expect, test, type Browser, type Page } from '@playwright/test'

import {
  documentOf,
  LIBRARY,
  makeBasicCard,
  makeCategory,
  makeLinkCard,
  makePack,
  packOf,
  pdfBytes,
  RemoveAfter,
  ROOT,
  unique,
} from './resources.ts'
import { apiFrom, signedInAs } from './support.ts'

// Resources management (ADR 0037, G5 Work Package 4), in real Chromium through the real gateway: a Guardian authors a Category, a
// Pack and its Cards, aims them, publishes, edits, reorders and replaces a file, and the platform's own projection (the library, the
// preview) agrees with what the screens said. The step-up and the accessibility passes are in resources-stepup.spec.ts and
// resources-accessibility.spec.ts. Every name is random and what a journey makes it removes afterwards.

test.describe.configure({ timeout: 180_000 })

const removal = new RemoveAfter()

async function guardian(browser: Browser, baseURL: string | undefined): Promise<Page> {
  const page = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  return page
}

const titles = (page: Page) =>
  page.getByRole('list', { name: 'Cards' }).getByRole('listitem').locator('a')

test.describe.serial('authoring a Resource Pack, start to finish', () => {
  const names = {
    category: unique('E2E Category'),
    pack: unique('E2E Pack'),
    renamed: unique('E2E Renamed Pack'),
    first: unique('E2E Opening'),
    second: unique('E2E Link'),
  }
  let page: Page
  let packId = ''
  let firstId = ''
  let secondId = ''

  test.beforeAll(async ({ browser, baseURL }) => {
    page = await guardian(browser, baseURL)
  })

  test.afterAll(async () => {
    await removal.run(page)
    await page.context().close()
  })

  test('the Resources entry is in the navigation and leads to the management list', async () => {
    await page.getByRole('link', { name: 'Resources', exact: true }).first().click()
    await expect(page).toHaveURL(/\/resources$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Resources' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Add a Resource Pack' }).first()).toBeVisible()
    await expect(page.getByRole('navigation', { name: 'Resources' })).toBeVisible()
  })

  test('a Category is created, renamed, and listed', async () => {
    await page.goto('/resources/categories')
    await expect(page.getByRole('heading', { level: 1, name: 'Categories' })).toBeVisible()

    await page.getByLabel('New Category').fill(names.category)
    await page.getByRole('button', { name: 'Add Category' }).click()
    await expect(page.getByText(`The Category “${names.category}” was created.`)).toBeVisible()
    const row = page
      .getByRole('list', { name: 'Categories' })
      .getByRole('listitem')
      .filter({ hasText: names.category })
    await expect(row).toBeVisible()
    await expect(row).toContainText('0 Packs')
    const created = (await apiFrom(page, 'GET', `${ROOT}/categories`)).body as {
      data: { id: string; name: string }[]
    }
    const id = created.data.find((c) => c.name === names.category)?.id
    expect(id).toBeDefined()
    removal.category(id ?? '')

    // A duplicate is refused in the Console's own words, whatever its case.
    await page.getByLabel('New Category').fill(names.category.toUpperCase())
    await page.getByRole('button', { name: 'Add Category' }).click()
    await expect(page.getByRole('alert')).toHaveText(/A Category with that name already exists\./)
  })

  test('a Pack is created as a Draft, then aimed at Guardians and given a first Card', async () => {
    await page.goto('/resources/new')
    await page.getByLabel('Title').fill(names.pack)
    await page.getByLabel('Summary').fill('Written in the browser.')
    await page.getByLabel('Category').selectOption({ label: names.category })
    await page.getByRole('button', { name: 'Create Resource Pack' }).click()

    await expect(page.getByRole('heading', { level: 1, name: names.pack })).toBeVisible()
    await expect(page.getByText(/created as a Draft/)).toBeVisible()
    packId = /\/packs\/([^/]+)$/.exec(page.url())?.[1] ?? ''
    expect(packId).not.toBe('')
    removal.pack(packId)

    // Member content is authorable, and the Console says plainly that nothing delivers it to Members yet.
    const audiences = page.getByRole('form', { name: 'Pack audiences' })
    await expect(audiences).toContainText('Nothing delivers it to Members yet')
    await audiences.getByRole('checkbox', { name: 'Guardians' }).check()
    await audiences.getByRole('button', { name: 'Save audiences' }).click()
    await expect(page.getByText('The Pack’s audiences were saved.')).toBeVisible()
    expect((await packOf(page, packId)).audiences).toEqual(['guardian'])

    // A Basic Card, with content typed into the editor (loaded on demand).
    await page.getByRole('link', { name: 'Add a Card' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Add a Card' })).toBeVisible()
    await page.getByLabel('Title').fill(names.first)
    const editor = page.getByRole('textbox', { name: 'Content' })
    await editor.click()
    await page.keyboard.type('We are open every day from nine.')
    await page.getByRole('button', { name: 'Create Card' }).click()

    await expect(page.getByRole('heading', { level: 1, name: names.first })).toBeVisible()
    await expect(
      page.getByText('The Card was created as a Draft. Publish it when it is ready.'),
    ).toBeVisible()
    firstId = /\/cards\/([^/]+)$/.exec(page.url())?.[1] ?? ''
    const saved = (await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${firstId}`)).body as {
      content: { document: unknown }
      summary: string
      type: string
      state: string
    }
    expect(saved.type).toBe('basic')
    expect(saved.state).toBe('draft')
    expect(saved.content.document).toEqual(documentOf('We are open every day from nine.'))
    // The summary is the server's: derived from the content, not a browser's guess.
    expect(saved.summary).toBe('We are open every day from nine.')
  })

  test('the Pack cannot be published yet, and says what it lacks; publishing the Card, then the Pack, works', async () => {
    await page.goto(`/resources/packs/${packId}`)
    await page.getByRole('button', { name: 'Publish Pack' }).click()
    const refusal = page.getByRole('alert')
    await expect(refusal).toContainText('The Pack cannot be published yet. It still needs:')
    await expect(refusal).toContainText('Publish at least one Card.')
    expect((await packOf(page, packId)).state).toBe('draft')

    await page.getByRole('link', { name: names.first }).click()
    await page.getByRole('button', { name: 'Publish Card' }).click()
    await expect(page.getByText('The Card is now Published.')).toBeVisible()

    await page.goto(`/resources/packs/${packId}`)
    await page.getByRole('button', { name: 'Publish Pack' }).click()
    await expect(page.getByText('The Pack is now Published.')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Unpublish Pack' })).toBeVisible()

    // The library — the platform's own projection — now has it, under its Category.
    const library = (await apiFrom(page, 'GET', LIBRARY)).body as {
      data: { category: { name: string }; packs: { title: string }[] }[]
    }
    const found = library.data.find((entry) => entry.category.name === names.category)
    expect(found?.packs.map((p) => p.title)).toContain(names.pack)
  })

  test('a Pack’s details are edited, and a Card’s content is edited', async () => {
    await page.goto(`/resources/packs/${packId}`)
    const details = page.getByRole('form', { name: 'Pack details' })
    await details.getByLabel('Title').fill(names.renamed)
    await details.getByRole('button', { name: 'Save details' }).click()
    await expect(page.getByText('The Pack’s details were saved.')).toBeVisible()
    await expect(page.getByRole('heading', { level: 1, name: names.renamed })).toBeVisible()
    expect((await packOf(page, packId)).title).toBe(names.renamed)

    await page.getByRole('link', { name: names.first }).click()
    const editor = page.getByRole('textbox', { name: 'Content' })
    await editor.click()
    await page.keyboard.press('Control+End')
    await page.keyboard.type(' Closed on public holidays.')
    await page.getByRole('button', { name: 'Save Card' }).click()
    await expect(page.getByText('The Card was saved.')).toBeVisible()
    const saved = (await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${firstId}`)).body as {
      content: { document: unknown }
      revision: number
    }
    expect(saved.content.document).toEqual(
      documentOf('We are open every day from nine. Closed on public holidays.'),
    )
    expect(saved.revision).toBe(2)
  })

  test('an External link Card is added, and the Cards are reordered from the keyboard', async () => {
    await page.goto(`/resources/packs/${packId}/cards/new`)
    await page.getByRole('radio', { name: /External link/ }).check()
    await page.getByLabel('Title').fill(names.second)
    await page.getByLabel('Web address').fill('https://example.org/venue')
    await page.getByRole('button', { name: 'Create Card' }).click()
    await expect(page.getByRole('heading', { level: 1, name: names.second })).toBeVisible()
    secondId = /\/cards\/([^/]+)$/.exec(page.url())?.[1] ?? ''

    await page.goto(`/resources/packs/${packId}`)
    await expect(titles(page)).toHaveText([names.first, names.second])

    // Without a pointer: focus the control, press Enter.
    const up = page.getByRole('button', { name: `Move ${names.second} up` })
    await up.focus()
    await page.keyboard.press('Enter')
    await expect(titles(page)).toHaveText([names.second, names.first])
    // Focus is kept (on the opposite control, since this one is now at the top), not dropped at the top of the page.
    await expect(page.getByRole('button', { name: `Move ${names.second} down` })).toBeFocused()
    await expect(page.getByText(`Moved ${names.second} to position 1 of 2.`)).toBeAttached()
    expect((await packOf(page, packId)).cards.map((c) => c.id)).toEqual([secondId, firstId])
  })

  test('a Card is unpublished and published again, and so is its Pack', async () => {
    await page.goto(`/resources/packs/${packId}/cards/${secondId}`)
    await page.getByRole('button', { name: 'Publish Card' }).click()
    await expect(page.getByText('The Card is now Published.')).toBeVisible()
    await page.getByRole('button', { name: 'Unpublish Card' }).click()
    await expect(page.getByText('The Card is now a Draft again.')).toBeVisible()

    await page.goto(`/resources/packs/${packId}`)
    await page.getByRole('button', { name: 'Unpublish Pack' }).click()
    await expect(page.getByText('The Pack is now a Draft again.')).toBeVisible()
    await page.getByRole('button', { name: 'Publish Pack' }).click()
    await expect(page.getByText('The Pack is now Published.')).toBeVisible()
  })

  test('narrowing a Card changes what the platform’s own preview shows each audience', async () => {
    await page.goto(`/resources/packs/${packId}`)
    const audiences = page.getByRole('form', { name: 'Pack audiences' })
    await audiences.getByRole('checkbox', { name: 'Members' }).check()
    await audiences.getByRole('button', { name: 'Save audiences' }).click()
    await expect(page.getByText('The Pack’s audiences were saved.')).toBeVisible()

    await page.goto(`/resources/packs/${packId}/cards/${firstId}`)
    const card = page.getByRole('form', { name: 'Card audience' })
    await card.getByRole('radio', { name: /Only part of that audience/ }).check()
    await card.getByRole('checkbox', { name: 'Members' }).check()
    await card.getByRole('button', { name: 'Save audience' }).click()
    await expect(page.getByText('The Card’s audience was saved.')).toBeVisible()

    await page.goto(`/resources/packs/${packId}`)
    const preview = page.getByRole('form', { name: 'Preview the Pack' })
    await preview.getByLabel('Preview as').selectOption('guardian')
    await preview.getByRole('button', { name: 'Preview' }).click()
    const result = page.getByTestId('pack-preview')
    // The Card narrowed to Members is simply absent for Guardians: the server's projection, not the browser's.
    await expect(result).toContainText('Guardians would see')
    await expect(result).not.toContainText(names.first)

    await preview.getByLabel('Preview as').selectOption('member')
    await preview.getByRole('button', { name: 'Preview' }).click()
    await expect(result).toContainText('Members would see')
    await expect(result).toContainText(names.first)
  })

  test('saving over someone else’s change is a deliberate act, and keeps their change to a field this person left alone', async ({
    browser,
    baseURL,
  }) => {
    await page.goto(`/resources/packs/${packId}`)
    const details = page.getByRole('form', { name: 'Pack details' })
    await expect(details.getByLabel('Title')).toHaveValue(names.renamed)
    const series = details.getByRole('checkbox', { name: 'This Pack is a Series' })
    await expect(series).not.toBeChecked()

    // Someone else, signed in separately, makes the Pack a Series while this one is open.
    const other = await signedInAs(browser, baseURL ?? '', 'admin-read')
    try {
      await other.goto('/')
      const base = (await packOf(other, packId)).revision
      const theirs = await apiFrom(other, 'PATCH', `${ROOT}/packs/${packId}`, {
        revision: base,
        is_series: true,
      })
      expect(theirs.status).toBe(200)
    } finally {
      await other.context().close()
    }

    // This person changes only the summary. The server refuses the stale save and changes nothing.
    await details.getByLabel('Summary').fill('My summary')
    await details.getByRole('button', { name: 'Save details' }).click()
    const conflict = page.getByText('Someone else saved changes to this Pack first.')
    await expect(conflict).toBeVisible()
    expect((await packOf(page, packId)).summary).not.toBe('My summary')

    // Their change shows in the field this person left alone; this person's edit is still in the form.
    await expect(series).toBeChecked()
    await expect(details.getByLabel('Summary')).toHaveValue('My summary')
    await expect(details.getByLabel('Title')).toHaveValue(names.renamed)

    await details.getByRole('button', { name: 'Save details' }).click()
    await expect(page.getByText('The Pack’s details were saved.')).toBeVisible()
    await expect(conflict).toHaveCount(0)

    // Both changes are on the server: theirs was not reverted by this save.
    const saved = await packOf(page, packId)
    expect(saved.summary).toBe('My summary')
    expect(saved.is_series).toBe(true)
    expect(saved.title).toBe(names.renamed)
  })

  test('Packs are listed, filtered and found by the server', async () => {
    await page.goto('/resources')
    await page.getByLabel('Search titles').fill(names.renamed)
    await page.getByRole('button', { name: 'Search' }).click()
    const table = page.getByRole('table', { name: 'Resource Packs' })
    await expect(table.getByRole('row')).toHaveCount(2) // the header and the one Pack
    await expect(table).toContainText(names.renamed)
    await expect(table).toContainText('Published')
    await expect(table).toContainText('Guardians, Members')

    await page.getByLabel('State').selectOption('draft')
    await expect(page.getByText('No Resource Packs match.')).toBeVisible()
  })
})

test.describe.serial('a File Card, from upload to replacement', () => {
  let page: Page
  let packId = ''
  let cardId = ''
  const title = unique('E2E Handbook')

  test.beforeAll(async ({ browser, baseURL }) => {
    page = await guardian(browser, baseURL)
    const category = await makeCategory(page)
    removal.category(category.id)
    const pack = await makePack(page, { categoryId: category.id, audiences: ['guardian'] })
    removal.pack(pack.id)
    packId = pack.id
    // A Published Card in it, so the Pack can be published and a Draft File Card sits beside it.
    await makeBasicCard(page, packId, { publish: true })
  })

  test.afterAll(async () => {
    await removal.run(page)
    await page.context().close()
  })

  test('a File Card is created with an allowed file, and shows safe metadata', async () => {
    await page.goto(`/resources/packs/${packId}/cards/new`)
    await page.getByRole('radio', { name: /^File/ }).check()
    await page.getByLabel('Title').fill(title)
    const input = page.getByLabel('File', { exact: true })
    await expect(input).toHaveAccessibleDescription(/up to 20 MB/)
    await input.setInputFiles({
      name: 'Welcome handbook.pdf',
      mimeType: 'application/pdf',
      buffer: pdfBytes('v1'),
    })
    await page.getByRole('button', { name: 'Create Card' }).click()

    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible()
    cardId = /\/cards\/([^/]+)$/.exec(page.url())?.[1] ?? ''
    const section = page.getByRole('region', { name: 'File' })
    await expect(section).toContainText('Welcome handbook.pdf')
    await expect(section).toContainText('PDF')
    await expect(section).toContainText('E2E Plain Guardian')
    // Where the file is kept is not the Console's business and is not in the page at all.
    const html = await page.content()
    expect(html).not.toMatch(/storage_key|app\/private|sha256/i)
  })

  test('the file downloads through the MANAGEMENT route, even though the Card is a Draft the library will not serve', async () => {
    const link = page.getByRole('link', { name: /Download Welcome handbook\.pdf/ })
    await expect(link).toHaveAttribute('href', `${ROOT}/packs/${packId}/cards/${cardId}/file`)
    const [download] = await Promise.all([page.waitForEvent('download'), link.click()])
    expect(download.suggestedFilename()).toBe('Welcome handbook.pdf')
    const chunks: Buffer[] = []
    for await (const chunk of await download.createReadStream()) chunks.push(chunk as Buffer)
    expect(Buffer.concat(chunks).equals(pdfBytes('v1'))).toBe(true)

    // The library route answers a Draft Card exactly as it answers a Card that does not exist.
    const library = await apiFrom(page, 'GET', `${LIBRARY}/packs/${packId}/cards/${cardId}/file`)
    expect(library.status).toBe(404)
    expect(library.body).toMatchObject({ code: 'resource_pack_not_found' })
  })

  test('the file is replaced; the Card keeps its revision and no verification is asked', async () => {
    const before = (await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${cardId}`)).body as {
      revision: number
    }
    await page.getByLabel('Replacement file').setInputFiles({
      name: 'Handbook v2.pdf',
      mimeType: 'application/pdf',
      buffer: pdfBytes('v2'),
    })
    await page.getByRole('button', { name: 'Replace file' }).click()

    await expect(
      page.getByText('The file was replaced. The Card now offers the new file.'),
    ).toBeVisible()
    const section = page.getByRole('region', { name: 'File' })
    await expect(section).toContainText('Handbook v2.pdf')
    await expect(section).not.toContainText('Welcome handbook.pdf')
    await expect(page.getByRole('dialog', { name: 'Confirm it is you' })).toHaveCount(0)
    const after = (await apiFrom(page, 'GET', `${ROOT}/packs/${packId}/cards/${cardId}`)).body as {
      revision: number
    }
    expect(after.revision).toBe(before.revision)

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('link', { name: /Download Handbook v2\.pdf/ }).click(),
    ])
    const chunks: Buffer[] = []
    for await (const chunk of await download.createReadStream()) chunks.push(chunk as Buffer)
    expect(Buffer.concat(chunks).equals(pdfBytes('v2'))).toBe(true)
  })

  test('an unsupported file is refused, in words, and the current file stays', async () => {
    await page.getByLabel('Replacement file').setInputFiles({
      name: 'invoice.pdf',
      mimeType: 'application/pdf', // what a browser would claim: the server does not believe it
      buffer: Buffer.from('<?php echo "not a pdf"; ?>\n'),
    })
    await page.getByRole('button', { name: 'Replace file' }).click()

    const alert = page.getByRole('alert')
    await expect(alert).toContainText(
      'That file type is not allowed, or the file is not what its name says.',
    )
    await expect(alert).toContainText('The Card still has its current file.')
    await expect(alert).toBeFocused()
    await expect(page.getByRole('region', { name: 'File' })).toContainText('Handbook v2.pdf')

    // An SVG renamed to look like an image is refused as well.
    await page.getByLabel('Replacement file').setInputFiles({
      name: 'logo.png',
      mimeType: 'image/png',
      buffer: Buffer.from(
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
      ),
    })
    await page.getByRole('button', { name: 'Replace file' }).click()
    await expect(page.getByRole('alert')).toContainText('That file type is not allowed')
  })

  test('a file over the limit is refused as too large, in words, and the current file stays', async () => {
    // Just over 20 MiB of text: PHP refuses it for its size and the platform answers 413, which is all the Console needs.
    const big = Buffer.alloc(20 * 1024 * 1024 + 1, 'a')
    await page
      .getByLabel('Replacement file')
      .setInputFiles({ name: 'big.txt', mimeType: 'text/plain', buffer: big })
    await page.getByRole('button', { name: 'Replace file' }).click()

    await expect(page.getByRole('alert')).toContainText(
      'That file is too large. The limit is 20 MB.',
      { timeout: 60_000 },
    )
    await expect(page.getByRole('region', { name: 'File' })).toContainText('Handbook v2.pdf')
  })

  test('a new File Card is refused the same way, with the form as typed', async () => {
    await page.goto(`/resources/packs/${packId}/cards/new`)
    await page.getByRole('radio', { name: /^File/ }).check()
    await page.getByLabel('Title').fill('Should not exist')
    await page.getByLabel('File', { exact: true }).setInputFiles({
      name: 'run.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('MZ\u0090\u0000 this is not a document'),
    })
    await page.getByRole('button', { name: 'Create Card' }).click()

    await expect(
      page
        .getByText(/That file type is not allowed, or the file is not what its name says/)
        .first(),
    ).toBeVisible()
    await expect(page.getByLabel('File', { exact: true })).toBeFocused()
    await expect(page.getByLabel('Title')).toHaveValue('Should not exist')
    expect(
      (await packOf(page, packId)).cards.find((c) => c.title === 'Should not exist'),
    ).toBeUndefined()
  })
})

// The editor's chunk, however the origin names it: the production build's `/assets/RichTextEditor-<hash>.js`, or the development
// server's `/src/richtext/RichTextEditor.tsx` and the Tiptap packages it pulls in. (`LazyRichTextEditor` is the wrapper, which
// every page that can edit loads, so it is deliberately not matched.)
const EDITOR_REQUEST = /\/RichTextEditor[-.]|@tiptap|tiptap_|prosemirror/i

const PORT = new URL(process.env.E2E_BASE_URL ?? 'http://commons.flowlife.localhost:18080').port
const PRODUCTION_ORIGIN = `http://prod.flowlife.localhost:${PORT}`

test.describe('the editor is loaded on demand', () => {
  let setup: Page
  let packId = ''

  test.beforeAll(async ({ browser, baseURL }) => {
    setup = await guardian(browser, baseURL)
    packId = (await makePack(setup)).id
    removal.pack(packId)
  })

  test.afterAll(async () => {
    await removal.run(setup)
    await setup.context().close()
  })

  const ordinary = (id: string) => [
    '/',
    '/people',
    '/discussions',
    '/resources',
    '/resources/categories',
    '/resources/new',
    `/resources/packs/${id}`,
  ]

  test('on the development server, no ordinary page asks for it; the Card forms do', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
    const asked: string[] = []
    page.on('request', (request) => asked.push(request.url()))

    for (const path of ordinary(packId)) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    }
    await page.waitForLoadState('networkidle')
    expect(asked.filter((url) => EDITOR_REQUEST.test(url))).toEqual([])

    await page.goto(`/resources/packs/${packId}/cards/new`)
    await expect(page.getByRole('textbox', { name: 'Content' })).toBeVisible()
    expect(asked.some((url) => EDITOR_REQUEST.test(url))).toBe(true)
    await page.context().close()
  })

  test('on the PRODUCTION build, under its real policy, the entry bundle is fetched, the editor chunk only when a Card form opens, and nothing is blocked', async ({
    browser,
  }) => {
    const page = await signedInAs(browser, PRODUCTION_ORIGIN, 'plain-guardian')
    const asked: string[] = []
    const blocked: string[] = []
    page.on('request', (request) => asked.push(request.url()))
    page.on('console', (message) => {
      if (/Content Security Policy|Refused to/i.test(message.text())) blocked.push(message.text())
    })

    for (const path of ordinary(packId)) {
      await page.goto(`${PRODUCTION_ORIGIN}${path}`)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    }
    await page.waitForLoadState('networkidle')
    // The real bundle, by name: the entry was fetched (so this is not vacuous) and the editor was not.
    expect(asked.some((url) => /\/assets\/index-[\w-]+\.js/.test(url))).toBe(true)
    expect(asked.filter((url) => /\/assets\/RichTextEditor-[\w-]+\.(js|css)/.test(url))).toEqual([])

    await page.goto(`${PRODUCTION_ORIGIN}/resources/packs/${packId}/cards/new`)
    await expect(page.getByRole('textbox', { name: 'Content' })).toBeVisible()
    expect(asked.filter((url) => /\/assets\/RichTextEditor-[\w-]+\.js/.test(url))).toHaveLength(1)
    expect(asked.filter((url) => /\/assets\/RichTextEditor-[\w-]+\.css/.test(url))).toHaveLength(1)

    // And it works there: typing reaches the editor under `script-src 'self'; style-src 'self'`.
    await page.getByRole('textbox', { name: 'Content' }).click()
    await page.keyboard.type('Typed on the production build.')
    await expect(page.getByRole('textbox', { name: 'Content' })).toContainText(
      'Typed on the production build.',
    )
    expect(blocked).toEqual([])
    await page.context().close()
  })
})

test.describe('who may manage Resources', () => {
  test('someone who may only VIEW Resources is not offered management, and the Console asks for nothing', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
    await page.goto('/')
    await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
    // The platform's role catalog grants the two capabilities together, so no real account holds one without the other. A view-only
    // person is therefore the same session with `resources.manage` taken out of what /me says: this proves the CONSOLE's side (what it
    // shows, and that it asks for nothing). The platform's side is proved below with a real account, and by its own test suite.
    const me = (await apiFrom(page, 'GET', '/api/v1/me')).body as { capabilities: string[] }
    expect(me.capabilities).toContain('resources.manage')
    await page.route('**/api/v1/me', (route) =>
      route.fulfill({
        json: { ...me, capabilities: me.capabilities.filter((c) => c !== 'resources.manage') },
      }),
    )
    const resourceRequests: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('/api/v1/admin/resources')) resourceRequests.push(request.url())
    })

    await page.goto('/')
    await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Resources', exact: true })).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'Categories', exact: true })).toHaveCount(0)

    for (const path of [
      '/resources',
      '/resources/new',
      '/resources/categories',
      '/resources/packs/01j00000000000000000000000',
    ]) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    }
    expect(resourceRequests).toEqual([])
    await page.context().close()
  })

  test('someone with no Console access at all is refused by the screen and by the platform', async ({
    browser,
    baseURL,
  }) => {
    const context = await browser.newContext({ baseURL: baseURL ?? '' })
    const page = await context.newPage()
    await page.goto('/login')
    await page.getByLabel('Email address').fill('e2e.noaccess@example.org')
    await page.getByLabel('Password', { exact: true }).fill('e2e-noaccess-password-not-a-secret')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page).toHaveURL(/\/my$/)

    const resourceRequests: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('/api/v1/admin/resources')) resourceRequests.push(request.url())
    })
    await page.goto('/resources')
    await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()
    expect(resourceRequests).toEqual([])

    // The platform decides on its own account: the same session, asking directly, is refused every way.
    expect((await apiFrom(page, 'GET', `${ROOT}/packs`)).status).toBe(403)
    expect((await apiFrom(page, 'GET', `${ROOT}/categories`)).status).toBe(403)
    expect(
      (await apiFrom(page, 'POST', `${ROOT}/categories`, { name: unique('Nope') })).status,
    ).toBe(403)
    expect((await apiFrom(page, 'GET', LIBRARY)).status).toBe(403)
    await context.close()
  })
})

test.describe('a link Card, for the record', () => {
  test('an External link Card keeps its address and is never fetched', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    const pack = await makePack(page)
    removal.pack(pack.id)
    const requests: string[] = []
    page.on('request', (request) => requests.push(request.url()))
    const card = await makeLinkCard(page, pack.id, { uri: 'https://example.invalid/never-fetched' })

    await page.goto(`/resources/packs/${pack.id}/cards/${card.id}`)
    await expect(page.getByLabel('Web address')).toHaveValue(
      'https://example.invalid/never-fetched',
    )
    expect(requests.some((url) => url.includes('example.invalid'))).toBe(false)
    await removal.run(page)
    await page.context().close()
  })
})
