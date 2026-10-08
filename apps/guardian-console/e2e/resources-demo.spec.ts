import { expect, test, type Browser, type Page } from '@playwright/test'

import { axeViolations, inTheme, THEMES, type Theme } from './axe.ts'
import { LIBRARY, ROOT } from './resources.ts'
import {
  cardAddress,
  cardIdOf,
  CARD,
  CATEGORY,
  deliveredPack,
  demoPacks,
  HIDDEN_WORD,
  LINK_ADDRESS,
  PACK,
  PDF_FILE,
  PNG_FILE,
  ROTA_FILE,
  type DemoPack,
} from './resourcesDemo.ts'
import { apiFrom, signedInAs } from './support.ts'

// The seeded Resources dataset (apps/platform/database/seeders/ResourcesDemoSeeder.php), READ in real Chromium through the real gateway and
// platform, as G5's closing evidence: what a Guardian is shown from authored data, what they are never shown, how a single Card, several
// Cards and a Series behave, how each kind of Card presents, who may read it, and that it is accessible and fits narrow screens. Nothing
// here changes the dataset (journeys that write make their own, in resources-closeout.spec.ts), and nothing in a journey is intercepted
// except where it says so: a persona that lacks one capability, the external site, and an image that cannot be loaded.

test.describe.configure({ timeout: 240_000 })

let demo: Record<keyof typeof PACK, DemoPack>

const guardian = async (
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
  const page = await guardian(browser, baseURL)
  demo = await demoPacks(page)
  await page.context().close()
})

const cardNav = (page: Page) => page.getByRole('navigation', { name: 'Cards in this Resource' })
const seriesNav = (page: Page) => page.getByRole('navigation', { name: 'Series' })
const idOf = (pack: keyof typeof PACK, title: string): string => cardIdOf(demo[pack], title)

interface Dom {
  document: { documentElement: { scrollWidth: number; clientWidth: number } }
  fetch: (
    path: string,
  ) => Promise<{ status: number; headers: { get: (name: string) => string | null } }>
}

const horizontalOverflow = (page: Page): Promise<number> =>
  page.evaluate(() => {
    const root = (globalThis as unknown as Dom).document.documentElement
    return root.scrollWidth - root.clientWidth
  })

async function fetchedFrom(
  page: Page,
  path: string,
): Promise<{ status: number; type: string; disposition: string }> {
  return page.evaluate(async (path) => {
    const response = await (globalThis as unknown as Dom).fetch(path)
    return {
      status: response.status,
      type: response.headers.get('content-type') ?? '',
      disposition: response.headers.get('content-disposition') ?? '',
    }
  }, path)
}

async function search(page: Page, text: string): Promise<void> {
  await page.getByLabel('Search Resources').fill(text)
  const answered = page.waitForResponse(
    (response) =>
      response.url().includes('/api/v1/admin/resource-library?') && response.url().includes('q='),
  )
  await page
    .getByRole('search', { name: 'Find Resources' })
    .getByRole('button', { name: 'Search' })
    .click()
  await answered
}

// --- The library home ------------------------------------------------------------------------------------------------------------

test.describe('the library, from the seeded dataset', () => {
  test('lists the Categories a Guardian may read, in order, with their Packs in order, and none of the rest', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    await page.goto('/resource-library')
    await expect(page.getByRole('region', { name: CATEGORY.running })).toBeVisible()

    // Other journeys' data may share the library, so judge the demo's own Categories, in the order the server sent them.
    const headings = await page.getByRole('heading', { level: 2 }).allTextContents()
    const ours: string[] = Object.values(CATEGORY)
    expect(headings.filter((h) => ours.includes(h))).toEqual([
      CATEGORY.started,
      CATEGORY.running,
      CATEGORY.safety,
    ])

    const running = page.getByRole('region', { name: CATEGORY.running })
    await expect(running.getByRole('listitem').getByRole('link')).toHaveText([
      PACK.single,
      PACK.operations,
      PACK.link,
      PACK.image,
    ])
    await expect(page.getByRole('region', { name: CATEGORY.started }).getByRole('link')).toHaveText(
      [PACK.narrowed],
    )
    await expect(page.getByRole('region', { name: CATEGORY.safety }).getByRole('link')).toHaveText([
      PACK.series,
    ])

    // Not a Draft, not a Pack for Members, and not the Category that holds only that Pack.
    const body = page.locator('body')
    await expect(body).not.toContainText(PACK.draft)
    await expect(body).not.toContainText(PACK.membersOnly)
    await expect(page.getByRole('region', { name: CATEGORY.members })).toHaveCount(0)
    await expect(page.getByRole('option', { name: CATEGORY.members })).toHaveCount(0)
    await page.context().close()
  })

  test('says how many Cards a Guardian can read, never how many exist', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    await page.goto('/resource-library')
    const entry = (title: string) =>
      page
        .getByRole('listitem')
        .filter({ has: page.getByRole('link', { name: title, exact: true }) })

    // Onboarding has four Cards and a Guardian can read three; Operations has four and three are Published.
    await expect(entry(PACK.narrowed)).toContainText('Series · 3 Cards')
    await expect(entry(PACK.operations)).toContainText('3 Cards')
    await expect(entry(PACK.operations)).not.toContainText('Series')
    await expect(entry(PACK.series)).toContainText('Series · 3 Cards')
    // A Pack of one Card needs no note about size.
    await expect(entry(PACK.single)).not.toContainText(/Card/)
    await expect(entry(PACK.single)).not.toContainText('Series')
    await page.context().close()
  })

  test('is searched by the platform: by Pack title, by a visible Card’s title, and never by content or by anything hidden', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    await page.goto('/resource-library')
    const asked: string[] = []
    page.on('request', (request) => asked.push(request.url()))

    await search(page, 'Onboarding')
    await expect(page.getByRole('link', { name: PACK.narrowed, exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: PACK.operations, exact: true })).toHaveCount(0)

    // A visible Card's title finds the Pack that holds it.
    await search(page, CARD.firstShift)
    await expect(page.getByRole('link', { name: PACK.narrowed, exact: true })).toBeVisible()

    // The hidden Card's title and word, the Member-only Pack, a Draft Pack and a Draft Card find nothing, and say only "no match".
    for (const hidden of [
      HIDDEN_WORD,
      CARD.hidden,
      PACK.membersOnly,
      'etiquette',
      PACK.draft,
      CARD.draft,
    ]) {
      await search(page, hidden)
      await expect(page.getByText('No Resources match.')).toBeVisible()
    }

    // Content is not searched, only titles and summaries. A derived summary is the START of the content (200 characters), so a word
    // early in a Card's text is found through its summary, and a word further in is not found at all.
    const checklist = await deliveredPack(page, demo.single.id)
    const summary = checklist.cards[0]?.summary ?? ''
    expect(summary.length).toBeGreaterThan(0)
    expect(summary).toContain('alarm')
    expect(summary).not.toContain('incident')
    await search(page, 'alarm')
    await expect(page.getByRole('link', { name: PACK.single, exact: true })).toBeVisible()
    await search(page, 'incident') // only in the Card's content, after its summary ends
    await expect(page.getByText('No Resources match.')).toBeVisible()

    // Every search went to the library, none to management.
    expect(asked.filter((url) => url.includes('/api/v1/admin/resources'))).toEqual([])
    expect(
      asked.filter((url) => url.includes('/api/v1/admin/resource-library?')).length,
    ).toBeGreaterThan(5)
    await page.context().close()
  })
})

// --- Not there, and not hinted at ------------------------------------------------------------------------------------------------

test.describe('what a Guardian is never shown', () => {
  test('a Draft Pack and a Member-only Pack are just as absent as a Pack that never existed', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    const shown: string[] = []
    const answered: { status: number; body: string }[] = []
    page.on('response', async (response) => {
      if (/\/resource-library\/packs\/[^/]+$/.test(response.url())) {
        answered.push({ status: response.status(), body: await response.text() })
      }
    })
    for (const path of [
      `/resource-library/${demo.draft.id}`,
      `/resource-library/${demo.membersOnly.id}`,
      '/resource-library/01j00000000000000000000000',
    ]) {
      await page.goto(path)
      await expect(
        page.getByRole('heading', { level: 1, name: 'Resource not found' }),
      ).toBeVisible()
      shown.push((await page.locator('[data-page-width]').textContent()) ?? '')
    }

    // Byte for byte the same page, with no retry to press and no word about why.
    expect(shown[1]).toBe(shown[0])
    expect(shown[2]).toBe(shown[0])
    expect(shown[0]).not.toMatch(/draft|unpublished|member|audience|hidden|restricted/i)
    await expect(page.getByRole('button', { name: 'Try again' })).toHaveCount(0)
    expect(answered.map((a) => a.status)).toEqual([404, 404, 404])
    expect(new Set(answered.map((a) => a.body)).size).toBe(1)
    await page.context().close()
  })

  test('the hidden Card is nowhere in the delivered Pack, and its id reads the first Card, saying nothing', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    const hiddenId = idOf('narrowed', CARD.hidden)

    // What the platform delivers: three Cards numbered 1..3, and no trace of the fourth.
    const delivered = await deliveredPack(page, demo.narrowed.id)
    expect(delivered.cards.map((c) => [c.index, c.title])).toEqual([
      [1, CARD.welcome],
      [2, CARD.firstShift],
      [3, CARD.handover],
    ])
    const raw = JSON.stringify(delivered)
    expect(raw).not.toContain(hiddenId)
    expect(raw).not.toContain(CARD.hidden)
    expect(raw).not.toContain(HIDDEN_WORD)

    // The hidden id in the address is not an error and not a hint: the first Card is read.
    await page.goto(cardAddress(demo.narrowed.id, hiddenId))
    await expect(page.getByRole('heading', { level: 2, name: CARD.welcome })).toBeVisible()
    await expect(cardNav(page).getByRole('link', { name: CARD.welcome })).toHaveAttribute(
      'aria-current',
      'true',
    )
    await expect(page.getByRole('alert')).toHaveCount(0)
    const body = page.locator('body')
    await expect(body).not.toContainText(CARD.hidden)
    await expect(body).not.toContainText(HIDDEN_WORD)
    expect(await page.content()).not.toContain(hiddenId)

    // A Draft Card of a Published Pack is no different.
    await page.goto(cardAddress(demo.operations.id, idOf('operations', CARD.draft)))
    await expect(page.getByRole('heading', { level: 2, name: CARD.keys })).toBeVisible()
    await expect(page.locator('body')).not.toContainText(CARD.draft)
    await page.context().close()
  })

  test('the library file route answers a Draft, a hidden and a missing Card the same way', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    const asks = [
      `${LIBRARY}/packs/${demo.operations.id}/cards/${idOf('operations', CARD.draft)}/file`, // a Draft Card (and not a File Card)
      `${LIBRARY}/packs/${demo.narrowed.id}/cards/${idOf('narrowed', CARD.hidden)}/file`, // a hidden Card
      `${LIBRARY}/packs/${demo.draft.id}/cards/${demo.draft.cards[0]?.id ?? ''}/file`, // a Card of a Draft Pack
      `${LIBRARY}/packs/${demo.operations.id}/cards/01j00000000000000000000000/file`, // a Card that does not exist
    ]
    const answers = []
    for (const path of asks) answers.push(await apiFrom(page, 'GET', path))
    expect(answers.map((a) => a.status)).toEqual([404, 404, 404, 404])
    expect(new Set(answers.map((a) => JSON.stringify(a.body))).size).toBe(1)
    expect(answers[0]?.body).toMatchObject({ code: 'resource_pack_not_found' })
    await page.context().close()
  })

  test('management, by contrast, lists all of it: the Draft, the Member-only Pack and the Draft Card', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    await page.goto('/resources')
    await page.getByLabel('Search titles').fill('Winter')
    await page.getByRole('button', { name: 'Search' }).click()
    const table = page.getByRole('table', { name: 'Resource Packs' })
    await expect(table).toContainText(PACK.draft)
    await expect(table.getByRole('row', { name: new RegExp(PACK.draft) })).toContainText('Draft')

    await page.getByLabel('Search titles').fill(PACK.membersOnly)
    await page.getByRole('button', { name: 'Search' }).click()
    const members = table.getByRole('row', { name: new RegExp(PACK.membersOnly) })
    await expect(members).toContainText('Published')
    await expect(members).toContainText('Members')
    await expect(members).not.toContainText('Guardians')

    // The Pack with a Draft Card lists four Cards to the person who manages it, three to the person who reads it.
    await page.goto(`/resources/packs/${demo.operations.id}`)
    await expect(page.getByRole('list', { name: 'Cards' }).getByRole('listitem')).toHaveCount(4)
    await expect(page.getByRole('list', { name: 'Cards' })).toContainText(CARD.draft)
    expect((await deliveredPack(page, demo.operations.id)).cards).toHaveLength(3)
    await page.context().close()
  })
})

// --- One Card, several, and a Series ---------------------------------------------------------------------------------------------

test.describe('one Card, several Cards and a Series', () => {
  test('a Pack with one visible Card opens directly, with no browsing controls and a meaningful heading outline', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    await page.goto('/resource-library')
    await page.getByRole('link', { name: PACK.single, exact: true }).click()

    await expect(page.getByRole('heading', { level: 1 })).toHaveText(PACK.single)
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await expect(cardNav(page)).toHaveCount(0)
    await expect(seriesNav(page)).toHaveCount(0)
    await expect(page.getByRole('link', { name: /Previous|Next/ })).toHaveCount(0)
    await expect(page.getByText(/\b\d+ of \d+\b/)).toHaveCount(0)

    // The Card is titled as its Pack, so it has no second heading saying so; its article is still named, and its own
    // headings (Opening, Closing) follow the page's.
    await expect(page.getByRole('heading', { name: PACK.single })).toHaveCount(1)
    await expect(page.getByRole('article', { name: PACK.single })).toBeVisible()
    await expect(page.getByRole('article').getByRole('heading', { level: 2 })).toHaveText([
      'Opening',
      'Closing',
    ])
    await expect(page.getByRole('article').getByRole('heading', { level: 3 })).toHaveText([
      'Something went wrong?',
    ])
    await page.context().close()
  })

  test('a Pack with several Cards that is not a Series offers each by title, and no Previous or Next', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    await page.goto(`/resource-library/${demo.operations.id}`)

    await expect(cardNav(page).getByRole('link')).toHaveText([CARD.keys, CARD.cleaning, CARD.rota])
    await expect(cardNav(page).getByRole('link', { name: CARD.keys })).toHaveAttribute(
      'aria-current',
      'true',
    )
    await expect(seriesNav(page)).toHaveCount(0)
    await expect(page.getByRole('link', { name: /^(Previous|Next)/ })).toHaveCount(0)
    await expect(page.getByText(/\bCard \d+ of \d+\b/)).toHaveCount(0)

    await cardNav(page).getByRole('link', { name: CARD.rota }).click()
    await expect(page.getByRole('heading', { level: 2, name: CARD.rota })).toBeFocused()
    await expect(page).toHaveURL(new RegExp(`card=${idOf('operations', CARD.rota)}$`))
    await page.context().close()
  })

  test('a Series with a Member-only Card between Guardian Cards is 1, 2 and 3 of 3, and moves straight across the gap', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    await page.goto(`/resource-library/${demo.narrowed.id}`)

    await expect(cardNav(page).getByRole('link')).toHaveText([
      CARD.welcome,
      CARD.firstShift,
      CARD.handover,
    ])
    await expect(seriesNav(page).getByText('Card 1 of 3')).toBeVisible()
    await expect(seriesNav(page).getByRole('link', { name: /Previous/ })).toHaveCount(0)

    // Next goes from the first Card to the one that, underneath, is the third: the Member-only Card is not a step.
    await seriesNav(page)
      .getByRole('link', { name: `Next Card: ${CARD.firstShift}` })
      .click()
    await expect(page.getByRole('heading', { level: 2, name: CARD.firstShift })).toBeFocused()
    await expect(seriesNav(page).getByText('Card 2 of 3')).toBeVisible()
    await expect(
      seriesNav(page).getByRole('link', { name: `Previous Card: ${CARD.welcome}` }),
    ).toBeVisible()

    await seriesNav(page)
      .getByRole('link', { name: `Next Card: ${CARD.handover}` })
      .click()
    await expect(page.getByRole('heading', { level: 2, name: CARD.handover })).toBeVisible()
    await expect(seriesNav(page).getByText('Card 3 of 3')).toBeVisible()
    await expect(seriesNav(page).getByRole('link', { name: /Next/ })).toHaveCount(0)

    // And back across the same gap.
    await seriesNav(page)
      .getByRole('link', { name: /Previous/ })
      .click()
    await expect(seriesNav(page).getByText('Card 2 of 3')).toBeVisible()
    await seriesNav(page)
      .getByRole('link', { name: /Previous/ })
      .click()
    await expect(seriesNav(page).getByText('Card 1 of 3')).toBeVisible()
    await expect(page.locator('body')).not.toContainText(/of 4\b/)
    await page.context().close()
  })

  test('the browser’s Back and Forward walk the Cards read, and a reload keeps the place', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    await page.goto(`/resource-library/${demo.series.id}`)
    await expect(page.getByRole('heading', { level: 2, name: CARD.why })).toBeVisible()

    await seriesNav(page)
      .getByRole('link', { name: /Next Card/ })
      .click()
    await expect(page.getByRole('heading', { level: 2, name: CARD.exits })).toBeVisible()
    await expect(seriesNav(page).getByText('Card 2 of 3')).toBeVisible()
    await cardNav(page).getByRole('link', { name: CARD.evacuation }).click()
    await expect(page.getByRole('heading', { level: 2, name: CARD.evacuation })).toBeVisible()
    await expect(seriesNav(page).getByText('Card 3 of 3')).toBeVisible()

    await page.goBack()
    await expect(page.getByRole('heading', { level: 2, name: CARD.exits })).toBeVisible()
    await page.goBack()
    await expect(page.getByRole('heading', { level: 2, name: CARD.why })).toBeVisible()
    await page.goForward()
    await expect(page.getByRole('heading', { level: 2, name: CARD.exits })).toBeVisible()
    await expect(page).toHaveURL(new RegExp(`card=${idOf('series', CARD.exits)}$`))

    await page.reload()
    await expect(page.getByRole('heading', { level: 2, name: CARD.exits })).toBeVisible()
    await expect(seriesNav(page).getByText('Card 2 of 3')).toBeVisible()
    await page.context().close()
  })
})

// --- Each kind of Card -----------------------------------------------------------------------------------------------------------

test.describe('each kind of Card, as authored', () => {
  test('rich content is drawn by the safe renderer: headings, lists, a safe link, a quotation, a table and code', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    await page.goto(`/resource-library/${demo.single.id}`)
    await expect(page.getByRole('heading', { level: 2, name: 'Opening' })).toBeVisible()
    await expect(page.getByRole('list').filter({ hasText: 'Turn off the alarm' })).toBeVisible()
    await expect(page.getByRole('article').locator('ol > li')).toHaveCount(4)
    await expect(page.getByRole('article').locator('ul > li')).toHaveCount(4)
    const link = page.getByRole('link', { name: 'incident log' })
    await expect(link).toHaveAttribute('href', 'https://example.org/flow-life/incident-log')
    await expect(link).toHaveAttribute('target', '_blank')
    await expect(link).toHaveAttribute('rel', /noopener/)
    await expect(page.locator('blockquote')).toContainText('Nobody minds a question.')

    await page.goto(`/resource-library/${demo.operations.id}`)
    const table = page.getByRole('table')
    await expect(table.getByRole('columnheader')).toHaveText(['Door', 'Held by', 'Notes'])
    await expect(table.getByRole('row')).toHaveCount(4)
    await expect(table).toContainText('spin the dial after locking')
    await page.goto(cardAddress(demo.operations.id, idOf('operations', CARD.cleaning)))
    await expect(page.locator('pre')).toContainText('GREEN toilets')
    // Nothing that could run came with any of it.
    await expect(
      page.getByRole('article').locator('script, iframe, object, embed, [onclick], [style]'),
    ).toHaveCount(0)
    await page.context().close()
  })

  test('an External link Card offers a safe link to the address, and the page never reaches that address by itself', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    const reached: string[] = []
    // The external site is never contacted: anything the browser tries to fetch from it is answered here, and counted.
    await page.context().route('https://example.org/**', (route) => {
      reached.push(route.request().url())
      return route.fulfill({ status: 200, contentType: 'text/html', body: '<title>stub</title>' })
    })
    await page.goto(`/resource-library/${demo.link.id}`)

    await expect(page.getByRole('heading', { level: 1, name: PACK.link })).toBeVisible()
    await expect(page.getByRole('heading', { level: 2, name: CARD.booking })).toBeVisible()
    const action = page.getByRole('link', { name: /^Open link/ })
    await expect(action).toHaveAttribute('href', LINK_ADDRESS)
    await expect(action).toHaveAttribute('target', '_blank')
    await expect(action).toHaveAttribute('rel', 'noopener noreferrer')
    await expect(page.getByText('Opens example.org in a new tab.')).toBeVisible()
    await page.waitForLoadState('networkidle')
    expect(reached, 'nothing was fetched on the way to showing the link').toEqual([])

    // Following it is the person's act, in a new tab, to the stubbed address.
    const [popup] = await Promise.all([page.context().waitForEvent('page'), action.click()])
    await popup.waitForLoadState()
    expect(popup.url()).toBe(LINK_ADDRESS)
    expect(reached).toEqual([LINK_ADDRESS])
    await page.context().close()
  })

  test('an image File Card shows the image through the library route, within the page', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    const asked: string[] = []
    page.on('request', (request) => asked.push(request.url()))
    await page.goto(`/resource-library/${demo.image.id}`)

    const image = page.getByRole('img', { name: CARD.floorPlan })
    await expect(image).toBeVisible()
    await expect(image).toHaveJSProperty('complete', true)
    await expect(image).toHaveJSProperty('naturalWidth', 320)
    const cardId = demo.image.cards[0]?.id ?? ''
    expect(await image.getAttribute('src')).toBe(
      `${LIBRARY}/packs/${demo.image.id}/cards/${cardId}/file?disposition=inline`,
    )
    await expect(page.locator('dd', { hasText: PNG_FILE })).toBeVisible()
    await expect(page.getByText('PNG image')).toBeVisible()
    await expect(page.getByRole('link', { name: /^Download/ })).toBeVisible()
    expect(asked.filter((url) => url.includes('/api/v1/admin/resources'))).toEqual([])
    await page.context().close()
  })

  test('a PDF is viewed in a new tab or downloaded, and another kind of file is a download only', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    const pdfId = idOf('series', CARD.evacuation)
    await page.goto(cardAddress(demo.series.id, pdfId))

    const view = page.getByRole('link', { name: /^View PDF/ })
    const href = (await view.getAttribute('href')) ?? ''
    expect(href).toBe(`${LIBRARY}/packs/${demo.series.id}/cards/${pdfId}/file?disposition=inline`)
    await expect(view).toHaveAttribute('target', '_blank')
    await expect(view).toHaveAttribute('rel', 'noopener noreferrer')
    await expect(page.locator('iframe, embed, object')).toHaveCount(0)
    const inline = await fetchedFrom(page, href)
    expect(inline).toMatchObject({ status: 200 })
    expect(inline.type).toContain('application/pdf')
    expect(inline.disposition).toMatch(/^inline/)
    const [pdf] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('link', { name: /^Download/ }).click(),
    ])
    expect(pdf.suggestedFilename()).toBe(PDF_FILE)

    // The CSV: a download, with what it is and how big, and nothing to view.
    await page.goto(cardAddress(demo.operations.id, idOf('operations', CARD.rota)))
    await expect(page.locator('dd', { hasText: ROTA_FILE })).toBeVisible()
    await expect(page.getByText(/^\d+ bytes$/)).toBeVisible()
    await expect(page.getByRole('link', { name: /View PDF/ })).toHaveCount(0)
    await expect(page.getByRole('article').getByRole('img')).toHaveCount(0)
    await expect(page.locator('body')).not.toContainText(/storage|digest|sha-?256|\/private\//i)
    const [csv] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('link', { name: /^Download/ }).click(),
    ])
    expect(csv.suggestedFilename()).toBe(ROTA_FILE)
    const chunks: Buffer[] = []
    for await (const chunk of await csv.createReadStream()) chunks.push(chunk as Buffer)
    expect(Buffer.concat(chunks).toString('utf8')).toMatch(/^Week,Day,Opening Guardian/)
    await page.context().close()
  })

  test('an image that cannot be loaded says so and offers to try again, and then loads', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    let failures = 1
    // Not a missing file (the store cannot be emptied from a journey): the platform's own answer for one, `asset_unavailable`, given to
    // the first image request only, to show what the reader meets and that asking again works.
    await page.route('**/resource-library/packs/*/cards/*/file?disposition=inline', (route) =>
      failures-- > 0
        ? route.fulfill({ status: 404, json: { message: 'x', code: 'asset_unavailable' } })
        : route.continue(),
    )
    await page.goto(`/resource-library/${demo.image.id}`)

    await expect(page.getByText('This image cannot be shown right now.')).toBeVisible()
    await expect(page.getByRole('link', { name: /Download/ })).toHaveCount(0)
    await page.getByRole('button', { name: 'Try again' }).click()
    await expect(page.getByRole('img', { name: CARD.floorPlan })).toHaveJSProperty(
      'naturalWidth',
      320,
    )
    await page.context().close()
  })
})

// --- Who may read ----------------------------------------------------------------------------------------------------------------

test.describe('who may read the library, and who may manage', () => {
  /** The same signed-in Guardian, with one capability taken out of what `/me` says: no real account holds one without the other. */
  async function asWithout(
    browser: Browser,
    baseURL: string | undefined,
    removed: string,
  ): Promise<{ page: Page; asked: string[] }> {
    const page = await guardian(browser, baseURL)
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

  test('view only: the library is read, nothing of management is offered, and none of it is asked for', async ({
    browser,
    baseURL,
  }) => {
    const { page, asked } = await asWithout(browser, baseURL, 'resources.manage')
    await page.goto('/resource-library')
    await expect(page.getByRole('link', { name: PACK.single, exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: 'All Resource Packs' })).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'Categories', exact: true })).toHaveCount(0)
    expect(asked.every((url) => url.includes('/resource-library'))).toBe(true)

    asked.length = 0
    for (const path of ['/resources', `/resources/packs/${demo.operations.id}`]) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    }
    expect(asked).toEqual([])
    await page.context().close()
  })

  test('manage only: management works and the library is refused, asking nothing of the library', async ({
    browser,
    baseURL,
  }) => {
    const { page, asked } = await asWithout(browser, baseURL, 'resources.view')
    await page.goto('/resources')
    await expect(page.getByRole('heading', { level: 1, name: 'Resources' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Resource Library', exact: true })).toHaveCount(0)
    // The Resources entry opens management, not a library this person may not read.
    await page.goto('/')
    await page.getByRole('link', { name: 'Resources', exact: true }).first().click()
    await expect(page).toHaveURL(/\/resources$/)

    asked.length = 0
    for (const path of ['/resource-library', `/resource-library/${demo.single.id}`]) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    }
    expect(asked.filter((url) => url.includes('/resource-library'))).toEqual([])
    await page.context().close()
  })

  test('both: the Resources section offers the library and management, the library first', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    await page.goto('/resource-library')
    const section = page.getByRole('navigation', { name: 'Resources' })
    await expect(section.getByRole('link')).toHaveText([
      'Resource Library',
      'All Resource Packs',
      'Add a Resource Pack',
      'Categories',
    ])
    await expect(section.getByRole('link', { name: 'Resource Library' })).toHaveAttribute(
      'aria-current',
      'page',
    )
    await page.context().close()
  })

  test('no Console access: the screen and the platform both refuse', async ({
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
    expect((await apiFrom(page, 'GET', `${LIBRARY}/packs/${demo.single.id}`)).status).toBe(403)
    expect((await apiFrom(page, 'GET', `${ROOT}/packs`)).status).toBe(403)
    await context.close()
  })
})

// --- The keyboard ----------------------------------------------------------------------------------------------------------------

test.describe('a keyboard path through reading', () => {
  test('from the Resources entry to a Series, by Enter alone, with summaries on focus and focus following the choice', async ({
    browser,
    baseURL,
  }) => {
    const page = await guardian(browser, baseURL)
    const delivered = await deliveredPack(page, demo.series.id)
    const summaryOfExits = delivered.cards.find((c) => c.title === CARD.exits)?.summary ?? ''
    expect(summaryOfExits).not.toBe('')

    await page.goto('/')
    await page.getByRole('link', { name: 'Resources', exact: true }).first().focus()
    await page.keyboard.press('Enter')
    await expect(page).toHaveURL(/\/resource-library$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Resource Library' })).toBeFocused()

    await page.getByRole('link', { name: PACK.series, exact: true }).focus()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('heading', { level: 1, name: PACK.series })).toBeFocused()

    // A Card's summary appears on focus, describes its link, and Escape puts it away without moving focus.
    const exits = cardNav(page).getByRole('link', { name: CARD.exits })
    await exits.focus()
    await expect(page.getByRole('tooltip')).toHaveText(summaryOfExits)
    await expect(exits).toHaveAccessibleDescription(summaryOfExits)
    await page.keyboard.press('Escape')
    await expect(page.getByRole('tooltip')).toHaveCount(0)
    await expect(exits).toBeFocused()

    await page.keyboard.press('Enter')
    await expect(page.getByRole('heading', { level: 2, name: CARD.exits })).toBeFocused()
    await seriesNav(page)
      .getByRole('link', { name: /^Next Card/ })
      .focus()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('heading', { level: 2, name: CARD.evacuation })).toBeFocused()
    await expect(seriesNav(page).getByText('Card 3 of 3')).toBeVisible()

    // Back, once per step, to where the reader started.
    await page.goBack()
    await expect(page.getByRole('heading', { level: 2, name: CARD.exits })).toBeVisible()
    await page.goBack()
    await expect(page.getByRole('heading', { level: 2, name: CARD.why })).toBeVisible()
    await page.goBack()
    await expect(page.getByRole('heading', { level: 1, name: 'Resource Library' })).toBeVisible()
    await page.context().close()
  })
})

// --- The editor stays out of the library -----------------------------------------------------------------------------------------

const PORT = new URL(process.env.E2E_BASE_URL ?? 'http://commons.flowlife.localhost:18080').port
const PRODUCTION_ORIGIN = `http://prod.flowlife.localhost:${PORT}`

test.describe('the editor is for authoring only', () => {
  test('on the PRODUCTION build, reading the seeded library loads the entry and no editor chunk; editing a Card does', async ({
    browser,
  }) => {
    const page = await signedInAs(browser, PRODUCTION_ORIGIN, 'plain-guardian')
    const asked: string[] = []
    const blocked: string[] = []
    page.on('request', (request) => asked.push(request.url()))
    page.on('console', (message) => {
      if (/Content Security Policy|Refused to/i.test(message.text())) blocked.push(message.text())
    })

    for (const path of [
      '/resource-library',
      `/resource-library/${demo.single.id}`,
      `/resource-library/${demo.narrowed.id}`,
      `/resource-library/${demo.image.id}`,
      cardAddress(demo.operations.id, idOf('operations', CARD.rota)),
    ]) {
      await page.goto(`${PRODUCTION_ORIGIN}${path}`)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    }
    await page.waitForLoadState('networkidle')
    expect(asked.some((url) => /\/assets\/index-[\w-]+\.js/.test(url))).toBe(true)
    expect(asked.filter((url) => /\/assets\/RichTextEditor-[\w-]+\.(js|css)/.test(url))).toEqual([])
    expect(blocked).toEqual([])

    // The control: the screen that edits a Card fetches the editor, once, with its own stylesheet.
    await page.goto(
      `${PRODUCTION_ORIGIN}/resources/packs/${demo.operations.id}/cards/${idOf('operations', CARD.keys)}`,
    )
    await expect(page.getByRole('textbox', { name: 'Content' })).toBeVisible()
    expect(asked.filter((url) => /\/assets\/RichTextEditor-[\w-]+\.js/.test(url))).toHaveLength(1)
    expect(asked.filter((url) => /\/assets\/RichTextEditor-[\w-]+\.css/.test(url))).toHaveLength(1)
    expect(blocked).toEqual([])
    await page.context().close()
  })
})

// --- Accessibility, in both themes -----------------------------------------------------------------------------------------------

for (const theme of THEMES) {
  test.describe(`no accessibility violation, ${theme} theme, colour contrast included`, () => {
    const screens: [string, () => string, (page: Page) => Promise<void>][] = [
      [
        'the library home',
        () => '/resource-library',
        async (page) => {
          await expect(page.getByRole('region', { name: CATEGORY.running })).toBeVisible()
        },
      ],
      [
        'a single-Card Resource',
        () => `/resource-library/${demo.single.id}`,
        async (page) => {
          await expect(page.getByRole('heading', { level: 2, name: 'Opening' })).toBeVisible()
        },
      ],
      [
        'a multi-Card Series',
        () => `/resource-library/${demo.narrowed.id}`,
        async (page) => {
          await expect(seriesNav(page).getByText('Card 1 of 3')).toBeVisible()
        },
      ],
      [
        'a Basic Card with rich content and a table',
        () => `/resource-library/${demo.operations.id}`,
        async (page) => {
          await expect(page.getByRole('table')).toBeVisible()
        },
      ],
      [
        'an External link Card',
        () => `/resource-library/${demo.link.id}`,
        async (page) => {
          await expect(page.getByRole('link', { name: /^Open link/ })).toBeVisible()
        },
      ],
      [
        'an image File Card',
        () => `/resource-library/${demo.image.id}`,
        async (page) => {
          await expect(page.getByRole('img', { name: CARD.floorPlan })).toBeVisible()
        },
      ],
      [
        'a downloadable File Card',
        () => cardAddress(demo.operations.id, idOf('operations', CARD.rota)),
        async (page) => {
          await expect(page.getByRole('link', { name: /^Download/ })).toBeVisible()
        },
      ],
      [
        'a PDF File Card',
        () => cardAddress(demo.series.id, idOf('series', CARD.evacuation)),
        async (page) => {
          await expect(page.getByRole('link', { name: /^View PDF/ })).toBeVisible()
        },
      ],
      [
        'a Resource that is not there',
        () => `/resource-library/${demo.draft.id}`,
        async (page) => {
          await expect(
            page.getByRole('heading', { level: 1, name: 'Resource not found' }),
          ).toBeVisible()
        },
      ],
      [
        'the management Pack page',
        () => `/resources/packs/${demo.operations.id}`,
        async (page) => {
          await expect(page.getByRole('heading', { level: 1, name: PACK.operations })).toBeVisible()
          await expect(page.getByRole('list', { name: 'Cards' })).toBeVisible()
        },
      ],
      [
        'the management Card editor',
        () => `/resources/packs/${demo.operations.id}/cards/${idOf('operations', CARD.keys)}`,
        async (page) => {
          await expect(page.getByRole('textbox', { name: 'Content' })).toBeVisible()
          await expect(page.getByRole('button', { name: 'Heading 2' })).toBeVisible()
        },
      ],
    ]
    for (const [name, path, ready] of screens) {
      test(name, async ({ browser, baseURL }) => {
        const page = await guardian(browser, baseURL, theme)
        await page.goto(path())
        await ready(page)
        expect(await axeViolations(page), name).toEqual([])
        await page.context().close()
      })
    }

    test('a Card’s summary showing', async ({ browser, baseURL }) => {
      const page = await guardian(browser, baseURL, theme)
      await page.goto(`/resource-library/${demo.narrowed.id}`)
      await cardNav(page).getByRole('link', { name: CARD.firstShift }).focus()
      await expect(page.getByRole('tooltip')).toBeVisible()
      expect(await axeViolations(page), 'a summary showing').toEqual([])
      await page.context().close()
    })
  })
}

// --- The narrow screen ------------------------------------------------------------------------------------------------------------

test.describe('no page scrolls sideways, from the phone to the desktop', () => {
  for (const width of [320, 375, 1280]) {
    test(`at ${String(width)}px`, async ({ browser, baseURL }) => {
      const page = await guardian(browser, baseURL)
      await page.setViewportSize({ width, height: 800 })
      const routes: [string, string][] = [
        ['the library, with its Categories and Packs', '/resource-library'],
        ['a Series and its navigation', `/resource-library/${demo.narrowed.id}`],
        [
          'a Series Card with a long title',
          cardAddress(demo.series.id, idOf('series', CARD.exits)),
        ],
        ['a Card with a table', `/resource-library/${demo.operations.id}`],
        ['a Card with code', cardAddress(demo.operations.id, idOf('operations', CARD.cleaning))],
        ['an image', `/resource-library/${demo.image.id}`],
        ['a file with a long name', cardAddress(demo.operations.id, idOf('operations', CARD.rota))],
        ['an External link', `/resource-library/${demo.link.id}`],
      ]
      for (const [name, path] of routes) {
        await page.goto(path)
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await page.waitForLoadState('networkidle')
        expect(await horizontalOverflow(page), `${name} at ${String(width)}px`).toBeLessThanOrEqual(
          0,
        )
      }

      // The things being measured are really there (so the check is not vacuous), and the image fits.
      await page.goto(cardAddress(demo.series.id, idOf('series', CARD.exits)))
      await expect(page.getByRole('heading', { level: 2, name: CARD.exits })).toBeVisible()
      await page.goto(cardAddress(demo.operations.id, idOf('operations', CARD.rota)))
      await expect(page.locator('dd', { hasText: ROTA_FILE })).toBeVisible()
      await page.goto(`/resource-library/${demo.operations.id}`)
      await expect(page.getByRole('columnheader', { name: 'Held by' })).toBeVisible()
      await page.goto(`/resource-library/${demo.image.id}`)
      const box = await page.getByRole('img', { name: CARD.floorPlan }).boundingBox()
      expect(box?.width ?? 0).toBeLessThanOrEqual(width)
      await page.context().close()
    })
  }
})
