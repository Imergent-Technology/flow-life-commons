import { expect, test, type Browser, type Page } from '@playwright/test'

import { axeViolations, inTheme, THEMES, type Theme } from './axe.ts'
import {
  LIBRARY,
  makeBasicCard,
  makeCategory,
  makeLinkCard,
  makePack,
  makeUploadCard,
  narrowCard,
  pdfBytes,
  PNG_BASE64,
  publishPack,
  RemoveAfter,
  ROOT,
  unique,
} from './resources.ts'
import { apiFrom, signedInAs } from './support.ts'

// The Resource Library (ADR 0037, G5 Work Package 5), in real Chromium through the real gateway and the real platform. A Guardian
// reads what the platform's delivery projection gives them: Packs by Category, a single Card plainly, several by their titles, a
// Series with Previous and Next, a Card narrowed away from Guardians as if it did not exist, links that open safely, images shown
// in the Card, and PDFs and other files through the library's own authorized route. Every name is random and what a journey makes
// it removes afterwards. The platform's data is made through its API from the signed-in page, exactly as the Console would, and
// nothing in the journeys below is intercepted except where a test says so (a view-only persona, an image that cannot be loaded).

test.describe.configure({ timeout: 240_000 })

const removal = new RemoveAfter()

interface Dom {
  document: { documentElement: { scrollWidth: number; clientWidth: number } }
}

const horizontalOverflow = (page: Page): Promise<number> =>
  page.evaluate(() => {
    const root = (globalThis as unknown as Dom).document.documentElement
    return root.scrollWidth - root.clientWidth
  })

const heading = (text: string): unknown => ({
  type: 'heading',
  attrs: { level: 2 },
  content: [{ type: 'text', text }],
})
const cell = (kind: 'tableHeader' | 'tableCell', text: string): unknown => ({
  type: kind,
  content: [{ type: 'paragraph', content: [{ type: 'text', text }] }],
})

/** Rich content that matters to a reader: a heading, a safe link, and a table with a very long word in it. */
const article = {
  type: 'doc',
  content: [
    heading('Before you arrive'),
    {
      type: 'paragraph',
      content: [
        { type: 'text', text: 'Read the ' },
        {
          type: 'text',
          text: 'venue notes',
          marks: [{ type: 'link', attrs: { href: 'https://example.org/venue-notes' } }],
        },
        { type: 'text', text: ' first.' },
      ],
    },
    {
      type: 'table',
      content: [
        {
          type: 'tableRow',
          content: [
            cell('tableHeader', 'Name'),
            cell('tableHeader', 'Where'),
            cell('tableHeader', `Long${'x'.repeat(120)}`),
          ],
        },
        {
          type: 'tableRow',
          content: [
            cell('tableCell', 'Front desk'),
            cell('tableCell', 'Ground floor'),
            cell('tableCell', 'Ask for the key'),
          ],
        },
      ],
    },
  ],
}

interface Fixtures {
  category: { id: string; name: string }
  single: { id: string; title: string; cardTitle: string }
  series: {
    id: string
    title: string
    start: { id: string; title: string }
    hidden: { id: string; title: string }
    link: { id: string; title: string }
    image: { id: string; title: string }
    pdf: { id: string; title: string }
    text: { id: string; title: string }
  }
  several: { id: string; title: string; first: string; second: string }
  draft: { id: string; title: string }
  long: {
    id: string
    title: string
    categoryName: string
    link: string
    file: string
    basic: string
  }
}

let fixtures: Fixtures

const signedIn = async (
  browser: Browser,
  baseURL: string | undefined,
  theme?: Theme,
): Promise<Page> => {
  const page = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
  if (theme !== undefined) await inTheme(page, theme)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  return page
}

test.beforeAll(async ({ browser, baseURL }) => {
  const page = await signedIn(browser, baseURL)
  const category = await makeCategory(page)
  removal.category(category.id)
  const both = ['guardian', 'member']

  // One Card: a simple Resource.
  const singlePack = await makePack(page, { categoryId: category.id, audiences: ['guardian'] })
  removal.pack(singlePack.id)
  const singleCard = await makeBasicCard(page, singlePack.id, {
    title: unique('Single note'),
    text: 'A short note for Guardians.',
    publish: true,
  })
  await publishPack(page, singlePack.id)

  // A Series of five visible Cards, with a sixth between the first and the second that Guardians cannot see.
  const seriesPack = await makePack(page, {
    title: unique('The course'),
    categoryId: category.id,
    audiences: both,
    isSeries: true,
  })
  removal.pack(seriesPack.id)
  const start = await makeBasicCard(page, seriesPack.id, {
    title: unique('Start here'),
    document: article,
    publish: true,
  })
  const hidden = await makeBasicCard(page, seriesPack.id, {
    title: unique('Members only'),
    text: 'Only Members read this.',
    publish: true,
  })
  await narrowCard(page, seriesPack.id, hidden.id, ['member'])
  const link = await makeLinkCard(page, seriesPack.id, {
    title: unique('The venue'),
    uri: 'https://example.org/venue',
    summary: 'Where we meet.',
    publish: true,
  })
  const image = await makeUploadCard(page, seriesPack.id, {
    title: unique('Floor plan'),
    name: 'floor-plan.png',
    type: 'image/png',
    base64: PNG_BASE64,
    publish: true,
  })
  const pdf = await makeUploadCard(page, seriesPack.id, {
    title: unique('Handbook'),
    name: 'handbook.pdf',
    type: 'application/pdf',
    text: pdfBytes().toString('latin1'),
    publish: true,
  })
  const text = await makeUploadCard(page, seriesPack.id, {
    title: unique('Notes'),
    name: 'notes.txt',
    type: 'text/plain',
    text: 'Plain notes, nothing more.\n',
    publish: true,
  })
  await publishPack(page, seriesPack.id)

  // Several Cards, but not a Series.
  const severalPack = await makePack(page, {
    title: unique('Two things'),
    categoryId: category.id,
    audiences: ['guardian'],
  })
  removal.pack(severalPack.id)
  const first = await makeBasicCard(page, severalPack.id, {
    title: unique('First thing'),
    text: 'The first thing.',
    publish: true,
  })
  const second = await makeBasicCard(page, severalPack.id, {
    title: unique('Second thing'),
    text: 'The second thing.',
    publish: true,
  })
  await publishPack(page, severalPack.id)

  // A Pack that is still a Draft: from a reader's side it is simply not there.
  const draftPack = await makePack(page, {
    title: unique('Not yet'),
    categoryId: category.id,
    audiences: ['guardian'],
  })
  removal.pack(draftPack.id)
  await makeBasicCard(page, draftPack.id, { text: 'Not for reading yet.', publish: true })

  // Long, unbroken strings where a Category, a Pack, a Card, a file name and an address can each force a page wider than the screen.
  const longCategory = await makeCategory(
    page,
    `C${'a'.repeat(60)}${crypto.randomUUID().slice(0, 8)}t`,
  )
  removal.category(longCategory.id)
  const longTitle = `L${'o'.repeat(150)}ng`
  const longPack = await makePack(page, {
    title: longTitle,
    categoryId: longCategory.id,
    audiences: ['guardian'],
    isSeries: true,
  })
  removal.pack(longPack.id)
  const longBasic = await makeBasicCard(page, longPack.id, {
    title: `B${'a'.repeat(150)}sic`,
    text: `W${'w'.repeat(200)}ord`,
    publish: true,
  })
  const longLink = await makeLinkCard(page, longPack.id, {
    title: `K${'e'.repeat(150)}y`,
    uri: `https://example.org/${'u'.repeat(400)}`,
    publish: true,
  })
  const longFile = await makeUploadCard(page, longPack.id, {
    title: 'A file with a long name',
    name: `${'f'.repeat(200)}.pdf`,
    type: 'application/pdf',
    text: pdfBytes('long').toString('latin1'),
    publish: true,
  })
  await publishPack(page, longPack.id)

  fixtures = {
    category,
    single: { id: singlePack.id, title: singlePack.title, cardTitle: singleCard.title },
    series: {
      id: seriesPack.id,
      title: seriesPack.title,
      start,
      hidden,
      link,
      image,
      pdf,
      text,
    },
    several: {
      id: severalPack.id,
      title: severalPack.title,
      first: first.title,
      second: second.title,
    },
    draft: draftPack,
    long: {
      id: longPack.id,
      title: longTitle,
      categoryName: longCategory.name,
      link: longLink.id,
      file: longFile.id,
      basic: longBasic.id,
    },
  }
  await page.context().close()
})

test.afterAll(async ({ browser, baseURL }) => {
  const page = await signedIn(browser, baseURL)
  await removal.run(page)
  await page.context().close()
})

interface Fetching {
  fetch: (
    path: string,
  ) => Promise<{ status: number; headers: { get: (name: string) => string | null } }>
}

/** What the platform answers to a GET from the signed-in page (the browser's own network, session and policy), headers only. */
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

const cardNav = (page: Page) => page.getByRole('navigation', { name: 'Cards in this Resource' })
const seriesNav = (page: Page) => page.getByRole('navigation', { name: 'Series' })
const cardAddress = (packId: string, cardId: string) => `/resource-library/${packId}?card=${cardId}`

async function libraryPackOf(
  page: Page,
  packId: string,
): Promise<{ cards: { id: string; title: string; summary: string; index: number }[] }> {
  const response = await apiFrom(page, 'GET', `${LIBRARY}/packs/${packId}`)
  expect(response.status).toBe(200)
  return response.body as { cards: { id: string; title: string; summary: string; index: number }[] }
}

// --- Reading ---------------------------------------------------------------------------------------------------------------

test.describe('reading the library', () => {
  test('lists Packs under their Category, and opens one to read its content', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const asked: string[] = []
    page.on('request', (request) => asked.push(request.url()))
    await page.goto('/resource-library')

    await expect(page.getByRole('heading', { level: 1, name: 'Resource Library' })).toBeVisible()
    const group = page.getByRole('region', { name: fixtures.category.name })
    await expect(
      group.getByRole('heading', { level: 2, name: fixtures.category.name }),
    ).toBeVisible()
    for (const title of [fixtures.single.title, fixtures.series.title, fixtures.several.title]) {
      await expect(group.getByRole('link', { name: title })).toBeVisible()
    }
    // A Draft is not in the library; nor is a Card or a count of one that is not for this reader.
    await expect(page.getByRole('link', { name: fixtures.draft.title })).toHaveCount(0)
    await expect(group.getByText('Series · 5 Cards')).toBeVisible()

    await group.getByRole('link', { name: fixtures.series.title }).click()
    await expect(page.getByRole('heading', { level: 1, name: fixtures.series.title })).toBeVisible()
    await expect(
      page.getByRole('heading', { level: 2, name: fixtures.series.start.title }),
    ).toBeVisible()
    // Rich content, drawn by the renderer: a heading, a safe link and a table.
    await expect(page.getByRole('heading', { level: 2, name: 'Before you arrive' })).toBeVisible()
    const link = page.getByRole('link', { name: 'venue notes' })
    await expect(link).toHaveAttribute('href', 'https://example.org/venue-notes')
    await expect(link).toHaveAttribute('target', '_blank')
    await expect(link).toHaveAttribute('rel', 'noopener noreferrer')
    await expect(page.getByRole('table')).toBeVisible()

    // Reading never touches management, and never needs the editor.
    expect(asked.filter((url) => url.includes('/api/v1/admin/resources'))).toEqual([])
    expect(asked.some((url) => url.includes('/api/v1/admin/resource-library'))).toBe(true)
    await page.context().close()
  })

  test('searches through the platform, finding a Pack by a visible Card’s title only', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    await page.goto('/resource-library')
    await page.getByLabel('Search Resources').fill(fixtures.series.hidden.title)
    await page
      .getByRole('search', { name: 'Find Resources' })
      .getByRole('button', { name: 'Search' })
      .click()
    // The only Card with that title is the one Guardians cannot see: it is not found, and nothing says that it exists.
    await expect(page.getByText('No Resources match.')).toBeVisible()
    await expect(page.getByRole('link', { name: fixtures.series.title })).toHaveCount(0)

    await page.getByLabel('Search Resources').fill(fixtures.series.link.title)
    await page
      .getByRole('search', { name: 'Find Resources' })
      .getByRole('button', { name: 'Search' })
      .click()
    await expect(page.getByRole('link', { name: fixtures.series.title })).toBeVisible()

    await page.getByLabel('Search Resources').fill('')
    await page
      .getByRole('search', { name: 'Find Resources' })
      .getByRole('button', { name: 'Search' })
      .click()
    await page.getByLabel('Category').selectOption({ label: fixtures.category.name })
    await expect(page.getByRole('link', { name: fixtures.single.title })).toBeVisible()
    await page.context().close()
  })

  test('shows a single Card plainly, with none of the browsing controls', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    await page.goto(`/resource-library/${fixtures.single.id}`)

    await expect(page.getByRole('heading', { level: 1, name: fixtures.single.title })).toBeVisible()
    await expect(page.getByText('A short note for Guardians.')).toBeVisible()
    await expect(cardNav(page)).toHaveCount(0)
    await expect(seriesNav(page)).toHaveCount(0)
    await expect(page.getByText(/Card \d+ of \d+/)).toHaveCount(0)
    await expect(page.getByRole('link', { name: /Previous|Next/ })).toHaveCount(0)
    await page.context().close()
  })

  test('offers several Cards by title, and adds Previous and Next only to a Series', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    await page.goto(`/resource-library/${fixtures.several.id}`)

    await expect(cardNav(page).getByRole('link')).toHaveText([
      fixtures.several.first,
      fixtures.several.second,
    ])
    await expect(seriesNav(page)).toHaveCount(0)
    await cardNav(page).getByRole('link', { name: fixtures.several.second }).click()
    // (The Cards' summaries are help text in the navigation; what is READ is in the article.)
    await expect(page.getByRole('article')).toContainText('The second thing.')
    await expect(page.getByRole('article')).not.toContainText('The first thing.')
    await page.context().close()
  })
})

// --- A Series, and a Card that is not for Guardians ---------------------------------------------------------------------------

test.describe('a Series with a Card Guardians cannot see', () => {
  test('is numbered and navigated without a gap, and never mentions the hidden Card', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const s = fixtures.series
    await page.goto(`/resource-library/${s.id}`)

    await expect(cardNav(page).getByRole('link')).toHaveText([
      s.start.title,
      s.link.title,
      s.image.title,
      s.pdf.title,
      s.text.title,
    ])
    await expect(seriesNav(page).getByText('Card 1 of 5')).toBeVisible()
    await expect(seriesNav(page).getByRole('link', { name: /Previous/ })).toHaveCount(0)

    // Next goes straight from the first Card to the venue: the Members-only Card between them is not a step.
    await seriesNav(page)
      .getByRole('link', { name: `Next Card: ${s.link.title}` })
      .click()
    await expect(page.getByRole('heading', { level: 2, name: s.link.title })).toBeVisible()
    await expect(seriesNav(page).getByText('Card 2 of 5')).toBeVisible()
    await expect(
      seriesNav(page).getByRole('link', { name: `Previous Card: ${s.start.title}` }),
    ).toBeVisible()

    // The platform agrees: the delivered Pack has five Cards numbered 1..5, and not the hidden one.
    const delivered = await libraryPackOf(page, s.id)
    expect(delivered.cards.map((c) => c.index)).toEqual([1, 2, 3, 4, 5])
    expect(delivered.cards.map((c) => c.id)).not.toContain(s.hidden.id)
    expect(JSON.stringify(delivered)).not.toContain(s.hidden.title)
    await expect(page.locator('body')).not.toContainText(s.hidden.title)
    await page.context().close()
  })

  test('reads the first Card, saying nothing, when the address names the hidden one', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const s = fixtures.series
    await page.goto(cardAddress(s.id, s.hidden.id))

    await expect(page.getByRole('heading', { level: 2, name: s.start.title })).toBeVisible()
    await expect(cardNav(page).getByRole('link', { name: s.start.title })).toHaveAttribute(
      'aria-current',
      'true',
    )
    await expect(page.getByRole('alert')).toHaveCount(0)
    await expect(page.locator('body')).not.toContainText('Only Members read this.')
    await page.context().close()
  })

  test('is just as absent as a Pack that does not exist, whether it is a Draft or was never there', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const shown: string[] = []
    for (const path of [
      `/resource-library/${fixtures.draft.id}`,
      '/resource-library/01j00000000000000000000000',
    ]) {
      await page.goto(path)
      await expect(
        page.getByRole('heading', { level: 1, name: 'Resource not found' }),
      ).toBeVisible()
      shown.push((await page.locator('[data-page-width]').textContent()) ?? '')
    }
    expect(shown[0]).toBe(shown[1])
    expect(shown[0]).not.toMatch(/draft|unpublished|member|audience|hidden/i)
    await expect(page.getByRole('link', { name: 'Back to the Resource Library' })).toBeVisible()
    await page.context().close()
  })

  test('keeps its place with the address, and with the browser’s Back and Forward', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const s = fixtures.series
    await page.goto(`/resource-library/${s.id}`)

    await cardNav(page).getByRole('link', { name: s.pdf.title }).click()
    await expect(page.getByRole('heading', { level: 2, name: s.pdf.title })).toBeVisible()
    await expect(page).toHaveURL(new RegExp(`card=${s.pdf.id}$`))
    await seriesNav(page)
      .getByRole('link', { name: /Next Card/ })
      .click()
    await expect(page.getByRole('heading', { level: 2, name: s.text.title })).toBeVisible()

    await page.goBack()
    await expect(page.getByRole('heading', { level: 2, name: s.pdf.title })).toBeVisible()
    await page.goBack()
    await expect(page.getByRole('heading', { level: 2, name: s.start.title })).toBeVisible()
    await page.goForward()
    await expect(page.getByRole('heading', { level: 2, name: s.pdf.title })).toBeVisible()

    // A reload, and a link shared with someone else, land on the same Card.
    await page.reload()
    await expect(page.getByRole('heading', { level: 2, name: s.pdf.title })).toBeVisible()
    await page.context().close()
  })

  test('is read by keyboard: Cards by title, their summaries on focus, and focus following the choice', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const s = fixtures.series
    const delivered = await libraryPackOf(page, s.id)
    const startSummary = delivered.cards.find((c) => c.id === s.start.id)?.summary ?? ''
    expect(startSummary).not.toBe('')
    await page.goto(`/resource-library/${s.id}`)
    await expect(page.getByRole('heading', { level: 1 })).toBeFocused()

    // The first Card's link, reached by Tab, shows its summary, and Escape puts the summary away without leaving the link.
    const first = cardNav(page).getByRole('link', { name: s.start.title })
    await first.focus()
    await expect(page.getByRole('tooltip')).toHaveText(startSummary)
    await page.keyboard.press('Escape')
    await expect(page.getByRole('tooltip')).toHaveCount(0)
    await expect(first).toBeFocused()

    // Enter on another Card moves to it, and focus lands on its title.
    const third = cardNav(page).getByRole('link', { name: s.image.title })
    await third.focus()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('heading', { level: 2, name: s.image.title })).toBeFocused()

    // Next, from the keyboard, does the same.
    await seriesNav(page)
      .getByRole('link', { name: /Next Card/ })
      .focus()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('heading', { level: 2, name: s.pdf.title })).toBeFocused()
    await page.context().close()
  })

  test('shows a Card’s summary on hover too', async ({ browser, baseURL }) => {
    const page = await signedIn(browser, baseURL)
    const s = fixtures.series
    await page.goto(`/resource-library/${s.id}`)

    await cardNav(page).getByRole('link', { name: s.link.title }).hover()
    await expect(page.getByRole('tooltip')).toHaveText('Where we meet.')
    await page.context().close()
  })
})

// --- Cards of each Type ---------------------------------------------------------------------------------------------------------

test.describe('Cards of each Type', () => {
  test('an External link Card offers a safe link to the address, and the address is never fetched', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const s = fixtures.series
    const asked: string[] = []
    page.on('request', (request) => asked.push(request.url()))
    await page.goto(cardAddress(s.id, s.link.id))

    const action = page.getByRole('link', { name: /^Open link/ })
    await expect(action).toHaveAttribute('href', 'https://example.org/venue')
    await expect(action).toHaveAttribute('target', '_blank')
    await expect(action).toHaveAttribute('rel', 'noopener noreferrer')
    await expect(page.getByText('Opens example.org in a new tab.')).toBeVisible()
    await page.waitForLoadState('networkidle')
    expect(asked.filter((url) => url.includes('example.org'))).toEqual([])
    await page.context().close()
  })

  test('an image File Card shows the image in the Card, through the library’s own route', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const s = fixtures.series
    const asked: string[] = []
    page.on('request', (request) => asked.push(request.url()))
    await page.goto(cardAddress(s.id, s.image.id))

    const image = page.getByRole('img', { name: s.image.title })
    await expect(image).toBeVisible()
    await expect(image).toHaveJSProperty('complete', true)
    await expect(image).toHaveJSProperty('naturalWidth', 1)
    const src = (await image.getAttribute('src')) ?? ''
    expect(src).toBe(`${LIBRARY}/packs/${s.id}/cards/${s.image.id}/file?disposition=inline`)
    await expect(page.locator('dd', { hasText: 'floor-plan.png' })).toBeVisible()
    await expect(page.getByText('PNG image')).toBeVisible()
    // The file came from the library's route and from no management one.
    expect(
      asked.some((url) => url.includes(`/resource-library/packs/${s.id}/cards/${s.image.id}/file`)),
    ).toBe(true)
    expect(asked.filter((url) => url.includes('/api/v1/admin/resources'))).toEqual([])
    await page.context().close()
  })

  test('an image that cannot be loaded says so, offers to try again, and offers no download of it', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const s = fixtures.series
    // Not a real missing file (the store cannot be emptied from a journey): the platform's own answer for one, `asset_unavailable`,
    // given to the image request, to show what the reader meets.
    await page.route('**/resource-library/packs/*/cards/*/file?disposition=inline', (route) =>
      route.fulfill({
        status: 404,
        json: { message: 'x', code: 'asset_unavailable' },
      }),
    )
    await page.goto(cardAddress(s.id, s.image.id))

    await expect(page.getByText('This image cannot be shown right now.')).toBeVisible()
    await expect(page.getByRole('link', { name: /Download/ })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible()
    await page.context().close()
  })

  test('a PDF is viewed in a new tab or downloaded, through the library’s route, and is not embedded', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const s = fixtures.series
    await page.goto(cardAddress(s.id, s.pdf.id))

    const view = page.getByRole('link', { name: /^View PDF/ })
    const href = (await view.getAttribute('href')) ?? ''
    expect(href).toBe(`${LIBRARY}/packs/${s.id}/cards/${s.pdf.id}/file?disposition=inline`)
    await expect(view).toHaveAttribute('target', '_blank')
    await expect(view).toHaveAttribute('rel', 'noopener noreferrer')
    await expect(page.locator('iframe, embed, object')).toHaveCount(0)

    const inline = await fetchedFrom(page, href)
    expect(inline.status).toBe(200)
    expect(inline.type).toContain('application/pdf')
    expect(inline.disposition).toMatch(/^inline/)

    const download = page.waitForEvent('download')
    await page.getByRole('link', { name: /^Download/ }).click()
    expect((await download).suggestedFilename()).toBe('handbook.pdf')
    await page.context().close()
  })

  test('another kind of file is a download, with what it is and how big', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const s = fixtures.series
    await page.goto(cardAddress(s.id, s.text.id))

    await expect(page.locator('dd', { hasText: 'notes.txt' })).toBeVisible()
    await expect(page.getByText('Text file')).toBeVisible()
    await expect(page.getByText(/^\d+ bytes$/)).toBeVisible()
    await expect(page.getByRole('link', { name: /View PDF/ })).toHaveCount(0)
    await expect(page.getByRole('img', { name: s.text.title })).toHaveCount(0)
    // Nothing of where it is kept.
    await expect(page.locator('body')).not.toContainText(/storage|digest|sha-?256|\/private\//i)

    const download = page.waitForEvent('download')
    await page.getByRole('link', { name: /^Download/ }).click()
    expect((await download).suggestedFilename()).toBe('notes.txt')
    await page.context().close()
  })
})

// --- Who may read ---------------------------------------------------------------------------------------------------------------

test.describe('who may read the library', () => {
  async function asWithout(
    browser: Browser,
    baseURL: string | undefined,
    removed: string,
  ): Promise<{ page: Page; asked: string[] }> {
    const page = await signedIn(browser, baseURL)
    // The platform's role catalog grants the two capabilities together, so no real account holds one without the other. These
    // personas are the same session with one capability taken out of what /me says: this proves the CONSOLE's side (what it shows,
    // and that it asks for nothing). The platform's side is proved with a real account below, and by its own test suite.
    const me = (await apiFrom(page, 'GET', '/api/v1/me')).body as { capabilities: string[] }
    expect(me.capabilities).toContain(removed)
    await page.route('**/api/v1/me', (route) =>
      route.fulfill({
        json: { ...me, capabilities: me.capabilities.filter((c) => c !== removed) },
      }),
    )
    const asked: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('/api/v1/admin/resource')) asked.push(request.url())
    })
    return { page, asked }
  }

  test('someone who may only VIEW reads the library and is offered no management', async ({
    browser,
    baseURL,
  }) => {
    const { page, asked } = await asWithout(browser, baseURL, 'resources.manage')

    await page.goto('/resource-library')
    await expect(page.getByRole('heading', { level: 1, name: 'Resource Library' })).toBeVisible()
    await expect(page.getByRole('link', { name: fixtures.single.title })).toBeVisible()
    await expect(
      page.getByRole('link', { name: 'Resource Library', exact: true }).first(),
    ).toBeVisible()
    await expect(page.getByRole('link', { name: 'All Resource Packs' })).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'Categories', exact: true })).toHaveCount(0)
    expect(asked.every((url) => url.includes('/resource-library'))).toBe(true)

    asked.length = 0
    for (const path of ['/resources', '/resources/new', '/resources/categories']) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    }
    expect(asked).toEqual([])
    await page.context().close()
  })

  test('someone who may only MANAGE is not given the library, and it asks for nothing', async ({
    browser,
    baseURL,
  }) => {
    const { page, asked } = await asWithout(browser, baseURL, 'resources.view')

    await page.goto('/resource-library')
    await expect(page.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    await page.goto(`/resource-library/${fixtures.single.id}`)
    await expect(page.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Resource Library', exact: true })).toHaveCount(0)
    expect(asked).toEqual([])
    await page.context().close()
  })

  test('someone with no Console access is refused by the screen and by the platform', async ({
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

    const asked: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('/api/v1/admin/resource')) asked.push(request.url())
    })
    await page.goto('/resource-library')
    await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()
    expect(asked).toEqual([])

    expect((await apiFrom(page, 'GET', LIBRARY)).status).toBe(403)
    expect((await apiFrom(page, 'GET', `${LIBRARY}/packs/${fixtures.single.id}`)).status).toBe(403)
    expect((await apiFrom(page, 'GET', `${ROOT}/packs`)).status).toBe(403)
    await context.close()
  })
})

// --- The editor stays out of the library --------------------------------------------------------------------------------------

// The editor is an application of its own (Tiptap, ProseMirror) and only screens that WRITE load it. Matched by name, not by path
// alone: a request for anything that can only be the editor, in development (the source modules) and in the production build (its chunk).
const EDITOR_REQUEST = /\/RichTextEditor[-.]|@tiptap|tiptap_|prosemirror/i
const PORT = new URL(process.env.E2E_BASE_URL ?? 'http://commons.flowlife.localhost:18080').port
const PRODUCTION_ORIGIN = `http://prod.flowlife.localhost:${PORT}`

test.describe('the library does not load the editor', () => {
  const libraryPaths = (f: Fixtures) => [
    '/resource-library',
    `/resource-library/${f.single.id}`,
    `/resource-library/${f.series.id}`,
    cardAddress(f.series.id, f.series.link.id),
    cardAddress(f.series.id, f.series.image.id),
    cardAddress(f.series.id, f.series.pdf.id),
  ]

  test('on the development server, reading asks for no editor module; writing does', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const asked: string[] = []
    page.on('request', (request) => asked.push(request.url()))
    for (const path of libraryPaths(fixtures)) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    }
    await page.waitForLoadState('networkidle')
    expect(asked.filter((url) => EDITOR_REQUEST.test(url))).toEqual([])

    await page.goto(`/resources/packs/${fixtures.single.id}/cards/new`)
    await expect(page.getByRole('textbox', { name: 'Content' })).toBeVisible()
    expect(asked.some((url) => EDITOR_REQUEST.test(url))).toBe(true)
    await page.context().close()
  })

  test('on the PRODUCTION build, under its real policy, reading loads the entry and no editor chunk, and the image still shows', async ({
    browser,
  }) => {
    const page = await signedInAs(browser, PRODUCTION_ORIGIN, 'plain-guardian')
    const asked: string[] = []
    const blocked: string[] = []
    page.on('request', (request) => asked.push(request.url()))
    page.on('console', (message) => {
      if (/Content Security Policy|Refused to/i.test(message.text())) blocked.push(message.text())
    })

    for (const path of libraryPaths(fixtures)) {
      await page.goto(`${PRODUCTION_ORIGIN}${path}`)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    }
    await page.waitForLoadState('networkidle')
    // The real bundle, by name: the entry was fetched (so this is not vacuous) and the editor was not.
    expect(asked.some((url) => /\/assets\/index-[\w-]+\.js/.test(url))).toBe(true)
    expect(asked.filter((url) => /\/assets\/RichTextEditor-[\w-]+\.(js|css)/.test(url))).toEqual([])

    // Under the production policy the image is allowed (`img-src 'self'`), and nothing was blocked.
    await page.goto(
      `${PRODUCTION_ORIGIN}${cardAddress(fixtures.series.id, fixtures.series.image.id)}`,
    )
    const image = page.getByRole('img', { name: fixtures.series.image.title })
    await expect(image).toHaveJSProperty('naturalWidth', 1)
    expect(blocked).toEqual([])

    // The control: a screen that writes does fetch the editor chunk.
    await page.goto(`${PRODUCTION_ORIGIN}/resources/packs/${fixtures.single.id}/cards/new`)
    await expect(page.getByRole('textbox', { name: 'Content' })).toBeVisible()
    expect(asked.filter((url) => /\/assets\/RichTextEditor-[\w-]+\.js/.test(url))).toHaveLength(1)
    await page.context().close()
  })
})

// --- Accessibility, and the narrow screen ----------------------------------------------------------------------------------------

for (const theme of THEMES) {
  test.describe(`no accessibility violation in the library, ${theme} theme, colour contrast included`, () => {
    test('the home, a single Card, a Series and its Cards of each Type, and a missing Resource', async ({
      browser,
      baseURL,
    }) => {
      const page = await signedIn(browser, baseURL, theme)
      const s = fixtures.series
      const screens: [string, string, () => Promise<void>][] = [
        [
          'the library',
          '/resource-library',
          async () => {
            await expect(page.getByRole('region', { name: fixtures.category.name })).toBeVisible()
          },
        ],
        [
          'a single Card',
          `/resource-library/${fixtures.single.id}`,
          async () => {
            await expect(page.getByText('A short note for Guardians.')).toBeVisible()
          },
        ],
        [
          'a Series, with rich content',
          `/resource-library/${s.id}`,
          async () => {
            await expect(page.getByRole('table')).toBeVisible()
          },
        ],
        [
          'an External link Card',
          cardAddress(s.id, s.link.id),
          async () => {
            await expect(page.getByRole('link', { name: /^Open link/ })).toBeVisible()
          },
        ],
        [
          'an image File Card',
          cardAddress(s.id, s.image.id),
          async () => {
            await expect(page.getByRole('img', { name: s.image.title })).toBeVisible()
          },
        ],
        [
          'a PDF File Card',
          cardAddress(s.id, s.pdf.id),
          async () => {
            await expect(page.getByRole('link', { name: /^View PDF/ })).toBeVisible()
          },
        ],
        [
          'a Resource that is not there',
          '/resource-library/01j00000000000000000000000',
          async () => {
            await expect(
              page.getByRole('heading', { level: 1, name: 'Resource not found' }),
            ).toBeVisible()
          },
        ],
      ]
      for (const [name, path, ready] of screens) {
        await page.goto(path)
        await ready()
        expect(await axeViolations(page), name).toEqual([])
      }

      // And with a Card's summary showing.
      await page.goto(`/resource-library/${s.id}`)
      await cardNav(page).getByRole('link', { name: s.link.title }).focus()
      await expect(page.getByRole('tooltip')).toBeVisible()
      expect(await axeViolations(page), 'a summary showing').toEqual([])
      await page.context().close()
    })
  })
}

const WIDTHS = [320, 375, 1280]

test.describe('no page scrolls sideways, even with long unbroken names, titles and addresses', () => {
  for (const width of WIDTHS) {
    test(`at ${String(width)}px`, async ({ browser, baseURL }) => {
      const page = await signedIn(browser, baseURL)
      await page.setViewportSize({ width, height: 800 })
      const f = fixtures
      const routes: [string, string][] = [
        ['the library', '/resource-library'],
        ['a single Card', `/resource-library/${f.single.id}`],
        ['a Series with a table', `/resource-library/${f.series.id}`],
        ['an image', cardAddress(f.series.id, f.series.image.id)],
        ['a Pack with a very long title and Category', `/resource-library/${f.long.id}`],
        ['a Card with a very long title', cardAddress(f.long.id, f.long.basic)],
        ['a link Card with a very long address', cardAddress(f.long.id, f.long.link)],
        ['a File Card with a very long file name', cardAddress(f.long.id, f.long.file)],
      ]
      for (const [name, path] of routes) {
        await page.goto(path)
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await page.waitForLoadState('networkidle')
        expect(await horizontalOverflow(page), `${name} at ${String(width)}px`).toBeLessThanOrEqual(
          0,
        )
      }
      await page.context().close()
    })
  }

  test('the long names are really on the pages being measured (the check is not vacuous)', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    await page.setViewportSize({ width: 320, height: 800 })
    await page.goto('/resource-library')
    await expect(
      page.getByRole('heading', { level: 2, name: fixtures.long.categoryName }),
    ).toBeVisible()
    await expect(page.getByRole('link', { name: fixtures.long.title })).toBeVisible()
    await page.goto(cardAddress(fixtures.long.id, fixtures.long.file))
    await expect(page.locator('dd', { hasText: 'f'.repeat(200) })).toBeVisible()
    await page.goto(cardAddress(fixtures.long.id, fixtures.long.link))
    await expect(page.getByText(/^Opens example\.org/)).toBeVisible()
    await page.goto(`/resource-library/${fixtures.series.id}`)
    await expect(page.getByRole('columnheader', { name: /^Longx+/ })).toBeVisible()
    await page.context().close()
  })
})
