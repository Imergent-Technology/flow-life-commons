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
  publishPack,
  RemoveAfter,
  ROOT,
  signedInAsReader,
  unique,
  VIEWER_LIBRARY,
} from './resources.ts'
import { apiFrom, signedInAs } from './support.ts'

// The Resource Library's TWO readers (ADR 0038, C1, C7), in real Chromium through the real gateway and the real platform, side by side on
// the same data: the privileged viewer (policy A, `resources.view`) and the audience reader (policy C). The personas are real:
//   - `plain-guardian` holds `resources.view` and `resources.manage`: the viewer, and the author of everything below;
//   - `audience-reader` is a restricted Console user (no Resources capability) who is an Active Guardian: the audience reader.
// The ONE simulated state is a capability taken away (the platform's role catalog grants the pair together, so no real account can be
// downgraded mid-journey): where a test does it, it says so, and the platform still answers every request it is then sent. The
// platform's own refusals are proved by its test suite (WP4) and by the real 403s asserted below.

test.describe.configure({ timeout: 240_000 })

const removal = new RemoveAfter()

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

const fetchedFrom = (page: Page, path: string): Promise<{ status: number; type: string }> =>
  page.evaluate(async (path) => {
    const response = await (globalThis as unknown as Dom).fetch(path)
    return { status: response.status, type: response.headers.get('content-type') ?? '' }
  }, path)

const manager = async (
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

interface Fixtures {
  category: { id: string; name: string }
  /** Published, for Guardians and Members. */
  published: { id: string; title: string; card: string }
  /** A Draft Pack for Guardians. */
  draft: { id: string; title: string; card: string }
  /** Published for Guardians and Volunteers, with a Draft Card, and a Card narrowed to Volunteers. */
  mixed: {
    id: string
    title: string
    visible: string
    draftCard: { id: string; title: string }
    narrowed: { id: string; title: string }
    file: { id: string; title: string }
  }
  /** Published for Volunteers only: a Guardian is not eligible. */
  volunteersOnly: { id: string; title: string }
  /** No Category, and a Draft. */
  loose: { id: string; title: string }
  /** No Card at all. */
  empty: { id: string; title: string }
  long: { id: string; title: string }
}

let f: Fixtures

test.beforeAll(async ({ browser, baseURL }) => {
  const page = await manager(browser, baseURL)
  const category = await makeCategory(page)
  removal.category(category.id)

  const published = await makePack(page, {
    categoryId: category.id,
    audiences: ['guardian', 'member'],
  })
  removal.pack(published.id)
  const publishedCard = await makeBasicCard(page, published.id, {
    title: unique('For everyone'),
    text: 'Read by Guardians and Members.',
    publish: true,
  })
  await publishPack(page, published.id)

  const draft = await makePack(page, {
    title: unique('Still being drafted'),
    categoryId: category.id,
    audiences: ['guardian'],
  })
  removal.pack(draft.id)
  const draftCard = await makeBasicCard(page, draft.id, {
    title: unique('Secret idea'),
    text: 'Not for readers yet.',
    publish: true,
  })

  const mixed = await makePack(page, {
    title: unique('The mixed course'),
    categoryId: category.id,
    audiences: ['guardian', 'volunteer'],
    isSeries: true,
  })
  removal.pack(mixed.id)
  const visible = await makeBasicCard(page, mixed.id, {
    title: unique('Open to both'),
    text: 'Guardians and Volunteers.',
    publish: true,
  })
  const narrowed = await makeBasicCard(page, mixed.id, {
    title: unique('Volunteers only card'),
    text: 'Only Volunteers read this.',
    publish: true,
  })
  await narrowCard(page, mixed.id, narrowed.id, ['volunteer'])
  const file = await makeUploadCard(page, mixed.id, {
    title: unique('Draft handbook'),
    name: 'handbook.pdf',
    type: 'application/pdf',
    text: pdfBytes('draft-handbook').toString('latin1'),
  })
  const draftInMixed = await makeBasicCard(page, mixed.id, {
    title: unique('Not ready'),
    text: 'A Draft Card in a Published Pack.',
  })
  await publishPack(page, mixed.id)

  const volunteersOnly = await makePack(page, {
    title: unique('Volunteer handbook'),
    categoryId: category.id,
    audiences: ['volunteer'],
  })
  removal.pack(volunteersOnly.id)
  await makeBasicCard(page, volunteersOnly.id, { text: 'Volunteers.', publish: true })
  await publishPack(page, volunteersOnly.id)

  const loose = await makePack(page, { title: unique('Loose notes'), audiences: ['member'] })
  removal.pack(loose.id)
  await makeBasicCard(page, loose.id, { text: 'No Category.' })

  const empty = await makePack(page, {
    title: unique('Nothing yet'),
    categoryId: category.id,
    audiences: ['guardian'],
  })
  removal.pack(empty.id)

  const longTitle = `V${'w'.repeat(150)}${crypto.randomUUID().slice(0, 8)}`
  const long = await makePack(page, {
    title: longTitle,
    categoryId: category.id,
    audiences: ['guardian', 'member', 'volunteer'],
  })
  removal.pack(long.id)
  await makeLinkCard(page, long.id, {
    title: `K${'e'.repeat(150)}y`,
    uri: `https://example.org/${'u'.repeat(300)}`,
    publish: true,
  })

  f = {
    category,
    published: { id: published.id, title: published.title, card: publishedCard.title },
    draft: { id: draft.id, title: draft.title, card: draftCard.title },
    mixed: {
      id: mixed.id,
      title: mixed.title,
      visible: visible.title,
      draftCard: draftInMixed,
      narrowed,
      file,
    },
    volunteersOnly,
    loose,
    empty,
    long: { id: long.id, title: longTitle },
  }
  await page.context().close()
})

test.afterAll(async ({ browser, baseURL }) => {
  const page = await manager(browser, baseURL)
  await removal.run(page)
  await page.context().close()
})

const entry = (page: Page, title: string) =>
  page.getByRole('listitem').filter({ has: page.getByRole('link', { name: title, exact: true }) })
const cardNav = (page: Page) => page.getByRole('navigation', { name: 'Cards in this Resource' })

// --- The same data, two readers ----------------------------------------------------------------------------------------------------

test.describe('the same library, read by the two readers', () => {
  test('the viewer sees every Pack and says how it was written; the audience reader sees only what a Guardian is eligible for', async ({
    browser,
    baseURL,
  }) => {
    const viewer = await manager(browser, baseURL)
    const reader = await signedInAsReader(browser, baseURL)
    await viewer.goto('/resource-library')
    await reader.goto('/resource-library')

    // The viewer: the Published Pack, the Draft, the Volunteers-only Pack, the empty Pack and the Pack with no Category.
    await expect(viewer.getByRole('heading', { level: 2, name: f.category.name })).toBeVisible()
    for (const title of [f.published, f.draft, f.mixed, f.volunteersOnly, f.empty]) {
      await expect(viewer.getByRole('link', { name: title.title, exact: true })).toBeVisible()
    }
    await expect(entry(viewer, f.draft.title)).toContainText('Draft')
    await expect(entry(viewer, f.published.title)).toContainText('Published')
    await expect(entry(viewer, f.published.title)).toContainText('For Guardians, Members')
    await expect(entry(viewer, f.volunteersOnly.title)).toContainText('For Volunteers')
    await expect(entry(viewer, f.empty.title)).toContainText('No Cards yet')
    await expect(entry(viewer, f.mixed.title)).toContainText('Series · 4 Cards, 2 Draft Cards')
    // The Pack with no Category is last, in a group of its own.
    const loose = viewer.getByRole('region', { name: 'Uncategorized' })
    await expect(loose.getByRole('link', { name: f.loose.title, exact: true })).toBeVisible()
    await expect(loose).toContainText('Draft')
    const groups = await viewer.getByRole('heading', { level: 2 }).allTextContents()
    expect(groups[groups.length - 1]).toBe('Uncategorized')

    // The audience reader: a Guardian reads the Published Packs aimed at Guardians, with a count of ONLY the Cards they can read.
    await expect(reader.getByRole('link', { name: f.published.title, exact: true })).toBeVisible()
    // Of the four Cards the viewer is told of, a Guardian can read ONE (the others are a Draft, a File Draft and one narrowed to Volunteers),
    // so the entry says nothing of a count: a single Card needs none, and a count of four would tell them what they may not see.
    await expect(entry(reader, f.mixed.title)).not.toContainText('Card')
    await expect(entry(reader, f.mixed.title)).not.toContainText(/\b[1-9]\d* (?:Cards?|Drafts?)/)
    for (const hidden of [f.draft, f.volunteersOnly, f.empty, f.loose]) {
      await expect(reader.getByRole('link', { name: hidden.title })).toHaveCount(0)
    }
    await expect(reader.getByRole('heading', { level: 2, name: 'Uncategorized' })).toHaveCount(0)
    const text = (await reader.locator('[data-page-width]').textContent()) ?? ''
    for (const word of ['Draft', 'Published', 'Last edited', 'For Volunteers', 'For Members']) {
      expect(text, `"${word}" is on the audience reader's page`).not.toContain(word)
    }
    await viewer.context().close()
    await reader.context().close()
  })

  test('the viewer’s filters and search are the platform’s, and cover Drafts; the audience reader’s search never does', async ({
    browser,
    baseURL,
  }) => {
    const viewer = await manager(browser, baseURL)
    const reader = await signedInAsReader(browser, baseURL)
    await viewer.goto('/resource-library')
    await expect(viewer.getByRole('link', { name: f.published.title })).toBeVisible()

    // Publication: Drafts are the Draft Packs and the Published Packs that hold a Draft Card.
    const answered = viewer.waitForResponse((r) => r.url().includes('publication=draft'))
    await viewer.getByLabel('Publication', { exact: true }).selectOption('draft')
    expect((await answered).status()).toBe(200)
    await expect(viewer.getByRole('link', { name: f.draft.title, exact: true })).toBeVisible()
    await expect(viewer.getByRole('link', { name: f.mixed.title, exact: true })).toBeVisible()
    await expect(viewer.getByRole('link', { name: f.published.title, exact: true })).toHaveCount(0)
    await viewer.getByLabel('Publication', { exact: true }).selectOption('published')
    await expect(viewer.getByRole('link', { name: f.published.title, exact: true })).toBeVisible()
    await expect(viewer.getByRole('link', { name: f.draft.title, exact: true })).toHaveCount(0)
    await viewer.getByLabel('Publication', { exact: true }).selectOption('')

    // Audience: only the Packs aimed at Volunteers.
    const aimed = viewer.waitForResponse((r) => r.url().includes('audience=volunteer'))
    await viewer.getByLabel('Audience', { exact: true }).selectOption('volunteer')
    expect((await aimed).status()).toBe(200)
    await expect(
      viewer.getByRole('link', { name: f.volunteersOnly.title, exact: true }),
    ).toBeVisible()
    await expect(viewer.getByRole('link', { name: f.mixed.title, exact: true })).toBeVisible()
    await expect(viewer.getByRole('link', { name: f.published.title, exact: true })).toHaveCount(0)
    await viewer.getByLabel('Audience', { exact: true }).selectOption('')

    // Search covers a Draft Card's title for the viewer ("all its Cards, Drafts included")...
    const word = f.draft.card
    const searched = viewer.waitForResponse((r) => r.url().includes('q='))
    await viewer.getByLabel('Search Resources').fill(word)
    await viewer
      .getByRole('search', { name: 'Find Resources' })
      .getByRole('button', { name: 'Search' })
      .click()
    expect((await searched).status()).toBe(200)
    await expect(viewer.getByRole('link', { name: f.draft.title, exact: true })).toBeVisible()

    // ...and never for the reader: the same words find nothing, and nothing says there was something to find.
    await reader.goto('/resource-library')
    const readerSearched = reader.waitForResponse(
      (r) => r.url().includes('/my-resources?') && r.url().includes('q='),
    )
    await reader.getByLabel('Search Resources').fill(word)
    await reader
      .getByRole('search', { name: 'Find Resources' })
      .getByRole('button', { name: 'Search' })
      .click()
    expect((await readerSearched).status()).toBe(200)
    await expect(reader.getByText('No Resources match.')).toBeVisible()
    await viewer.context().close()
    await reader.context().close()
  })

  test('a Pack, read by the viewer with every Card and by the audience reader with theirs, and each reader’s file route serves only its own', async ({
    browser,
    baseURL,
  }) => {
    const viewer = await manager(browser, baseURL)
    const reader = await signedInAsReader(browser, baseURL)

    await viewer.goto(`/resource-library/${f.mixed.id}`)
    await expect(viewer.getByRole('heading', { level: 1, name: f.mixed.title })).toBeVisible()
    // Every Card, in the platform's order, the Drafts marked, numbered across all of them.
    await expect(cardNav(viewer).getByRole('link')).toHaveText([
      f.mixed.visible,
      f.mixed.narrowed.title,
      `${f.mixed.file.title}Draft`,
      `${f.mixed.draftCard.title}Draft`,
    ])
    await expect(viewer.getByText('Card 1 of 4')).toBeVisible()
    // The Card narrowed to Volunteers inside a Pack for Guardians and Volunteers says so.
    await cardNav(viewer).getByRole('link', { name: f.mixed.narrowed.title }).click()
    await expect(viewer.getByRole('article')).toContainText('For Volunteers')
    await expect(viewer.getByRole('article')).toContainText(
      'Narrower than its Pack: the Pack is for Guardians, Volunteers.',
    )
    // A Draft File Card is served to the viewer, through the VIEWER's file route. (Choosing the last Card put its summary away, so the
    // next is chosen directly: no move of the pointer is needed to uncover it.)
    await cardNav(viewer).getByRole('link', { name: f.mixed.file.title }).click()
    const view = viewer.getByRole('link', { name: /^View PDF/ })
    const href = (await view.getAttribute('href')) ?? ''
    expect(href).toBe(
      `${VIEWER_LIBRARY}/packs/${f.mixed.id}/cards/${f.mixed.file.id}/file?disposition=inline`,
    )
    expect((await fetchedFrom(viewer, href)).status).toBe(200)

    // The audience reader: the Cards a Guardian may read, none of the others, no hint of them.
    await reader.goto(`/resource-library/${f.mixed.id}`)
    await expect(reader.getByRole('heading', { level: 1, name: f.mixed.title })).toBeVisible()
    await expect(reader.getByText('Card 1 of 1')).toHaveCount(0)
    await expect(reader.locator('body')).not.toContainText(f.mixed.narrowed.title)
    await expect(reader.locator('body')).not.toContainText(f.mixed.draftCard.title)
    await expect(reader.locator('body')).not.toContainText(f.mixed.file.title)
    // Its own file route refuses what it is not shown, with the one ordinary answer; the viewer's route is refused outright.
    const refused = await apiFrom(
      reader,
      'GET',
      `${LIBRARY}/packs/${f.mixed.id}/cards/${f.mixed.file.id}/file`,
    )
    expect(refused.status).toBe(404)
    expect(refused.body).toMatchObject({ code: 'resource_pack_not_found' })
    expect((await apiFrom(reader, 'GET', `${VIEWER_LIBRARY}/packs/${f.mixed.id}`)).status).toBe(403)
    await viewer.context().close()
    await reader.context().close()
  })

  test('an empty Pack, a Draft Pack and a Pack with no Category are read by the viewer, and are "not found" to the audience reader', async ({
    browser,
    baseURL,
  }) => {
    const viewer = await manager(browser, baseURL)
    const reader = await signedInAsReader(browser, baseURL)

    await viewer.goto(`/resource-library/${f.empty.id}`)
    await expect(viewer.getByRole('heading', { level: 1, name: f.empty.title })).toBeVisible()
    await expect(viewer.getByText('This Pack has no Cards yet.')).toBeVisible()
    await viewer.goto(`/resource-library/${f.draft.id}`)
    await expect(viewer.getByRole('heading', { level: 1, name: f.draft.title })).toBeVisible()
    await expect(viewer.getByText(f.draft.card).first()).toBeVisible()
    await viewer.goto(`/resource-library/${f.loose.id}`)
    await expect(viewer.getByText('In Uncategorized')).toBeVisible()

    const shown: string[] = []
    for (const id of [f.empty.id, f.draft.id, f.loose.id, '01j00000000000000000000000']) {
      await reader.goto(`/resource-library/${id}`)
      await expect(
        reader.getByRole('heading', { level: 1, name: 'Resource not found' }),
      ).toBeVisible()
      shown.push((await reader.locator('[data-page-width]').textContent()) ?? '')
    }
    // The same page for all four, whatever the reason: nothing says a Draft, an empty Pack or another audience exists.
    expect(new Set(shown).size).toBe(1)
    await viewer.context().close()
    await reader.context().close()
  })
})

// --- A capability taken away ------------------------------------------------------------------------------------------------------

test.describe('when resources.view is taken away from a viewer who has the library open', () => {
  /** SIMULATED: the platform's routes for the viewer refuse (403) and /me no longer lists the capability. The audience routes are real. */
  async function revokeOn(page: Page): Promise<void> {
    const me = (await apiFrom(page, 'GET', '/api/v1/me')).body as { capabilities: string[] }
    await page.route('**/api/v1/admin/resource-library**', (route) =>
      route.fulfill({ status: 403, json: { message: 'This action is unauthorized.' } }),
    )
    await page.route('**/api/v1/me', (route) =>
      route.fulfill({
        json: { ...me, capabilities: me.capabilities.filter((c) => c !== 'resources.view') },
      }),
    )
  }

  test('the library follows the refusal: everything the viewer held goes at once, /me is read again, and the audience reader opens at the same address', async ({
    browser,
    baseURL,
  }) => {
    const page = await manager(browser, baseURL)
    await page.goto('/resource-library')
    await expect(page.getByRole('link', { name: f.draft.title, exact: true })).toBeVisible()
    await expect(page.getByLabel('Publication', { exact: true })).toBeVisible()
    const asked: string[] = []
    page.on('request', (request) => asked.push(request.url()))

    await revokeOn(page)
    // The viewer's next request is refused.
    await page.getByLabel('Publication', { exact: true }).selectOption('draft')

    // Nothing of the viewer is left: no filter, no Draft, no marker, and no Pack the viewer listed.
    await expect(page.getByLabel('Publication', { exact: true })).toHaveCount(0)
    await expect(page.getByLabel('Audience', { exact: true })).toHaveCount(0)
    for (const title of [f.draft.title, f.published.title, f.loose.title]) {
      await expect(page.getByRole('link', { name: title })).toHaveCount(0)
    }
    await expect(page.getByRole('heading', { level: 2, name: 'Uncategorized' })).toHaveCount(0)
    await expect(page.getByRole('heading', { level: 1, name: 'Resource Library' })).toBeFocused()
    // The audience reader, which this person has no relationship for: nothing to read, said in the one way.
    await expect(page.getByText('There are no Resources for you yet.')).toBeVisible()
    expect(asked.filter((url) => url.endsWith('/api/v1/me')).length).toBeGreaterThan(0)
    expect(asked.some((url) => url.includes('/api/v1/admin/my-resources'))).toBe(true)
    expect(page.url()).toMatch(/\/resource-library$/)
    await page.context().close()
  })

  test('a Draft deep link, opened from the viewer, reads "not found" once the capability is gone, and nothing else about it', async ({
    browser,
    baseURL,
  }) => {
    const page = await manager(browser, baseURL)
    await page.goto('/resource-library')
    await expect(page.getByRole('link', { name: f.draft.title, exact: true })).toBeVisible()
    await revokeOn(page)

    await page.getByRole('link', { name: f.draft.title, exact: true }).click()

    await expect(page.getByRole('heading', { level: 1, name: 'Resource not found' })).toBeVisible()
    expect(page.url()).toContain(`/resource-library/${f.draft.id}`)
    const text = (await page.locator('[data-page-width]').textContent()) ?? ''
    expect(text).not.toContain(f.draft.title)
    expect(text).not.toContain(f.draft.card)
    expect(text).not.toMatch(/draft|unpublished/i)
    await page.context().close()
  })
})

// --- Volunteers, in management ----------------------------------------------------------------------------------------------------

test.describe('the Volunteer audience, authored and previewed in management', () => {
  test('a Pack is aimed at Volunteers, a Card is narrowed to them, and preview shows what Volunteers would receive, through management', async ({
    browser,
    baseURL,
  }) => {
    const page = await manager(browser, baseURL)
    const pack = await makePack(page, {
      title: unique('E2E Volunteer Pack'),
      categoryId: f.category.id,
    })
    removal.pack(pack.id)
    await makeBasicCard(page, pack.id, {
      title: unique('For all'),
      text: 'Everyone.',
      publish: true,
    })
    const volunteerCard = await makeBasicCard(page, pack.id, {
      title: unique('Volunteers read this'),
      text: 'Volunteers.',
      publish: true,
    })

    await page.goto(`/resources/packs/${pack.id}`)
    const audiences = page.getByRole('form', { name: 'Pack audiences' })
    await audiences.getByRole('checkbox', { name: 'Guardians' }).check()
    await audiences.getByRole('checkbox', { name: 'Volunteers' }).check()
    await audiences.getByRole('button', { name: 'Save audiences' }).click()
    await expect(page.getByText('The Pack’s audiences were saved.')).toBeVisible()
    expect((await apiFrom(page, 'GET', `${ROOT}/packs/${pack.id}`)).body).toMatchObject({
      audiences: ['guardian', 'volunteer'],
    })

    // Narrowing a Card to Volunteers: offered because its Pack has them, and not Members, which it does not.
    await page.goto(`/resources/packs/${pack.id}/cards/${volunteerCard.id}`)
    const narrow = page.getByRole('form', { name: 'Card audience' })
    await narrow.getByRole('radio', { name: /Only part of that audience/ }).check()
    await expect(narrow.getByRole('checkbox', { name: 'Members' })).toHaveCount(0)
    await narrow.getByRole('checkbox', { name: 'Volunteers' }).check()
    await narrow.getByRole('button', { name: 'Save audience' }).click()
    await expect(page.getByText('The Card’s audience was saved.')).toBeVisible()

    // The Pack list filters by Volunteers through the platform.
    await page.goto('/resources')
    const filtered = page.waitForResponse(
      (response) =>
        response.url().includes('/api/v1/admin/resources/packs') &&
        response.url().includes('audience=volunteer'),
    )
    await page.getByLabel('Audience', { exact: true }).selectOption('volunteer')
    expect((await filtered).status()).toBe(200)
    await expect(page.getByRole('link', { name: pack.title })).toBeVisible()

    // Preview as Volunteers: the platform's projection, asked of MANAGEMENT; the viewer's and the audience reader's routes are not.
    await page.goto(`/resources/packs/${pack.id}`)
    const asked: string[] = []
    page.on('request', (request) => asked.push(request.url()))
    const form = page.getByRole('form', { name: 'Preview the Pack' })
    await form.getByLabel('Preview as').selectOption('volunteer')
    await form.getByRole('button', { name: 'Preview' }).click()
    const result = page.getByTestId('pack-preview')
    await expect(result).toContainText('Volunteers would see 2 Cards')
    expect(
      asked.some((url) =>
        url.includes(`/api/v1/admin/resources/packs/${pack.id}/preview?audience=volunteer`),
      ),
    ).toBe(true)
    expect(
      asked.filter((url) => url.includes('/resource-library') || url.includes('/my-resources')),
    ).toEqual([])

    // And the same Pack, as a Guardian reader is served it: the Card narrowed to Volunteers is not theirs.
    await publishPack(page, pack.id)
    const reader = await signedInAsReader(browser, baseURL)
    const delivered = await apiFrom(reader, 'GET', `${LIBRARY}/packs/${pack.id}`)
    expect(delivered.status).toBe(200)
    expect(
      (delivered.body as { cards: { title: string }[] }).cards.map((c) => c.title),
    ).toHaveLength(1)
    expect(JSON.stringify(delivered.body)).not.toContain(volunteerCard.title)
    await reader.context().close()
    await page.context().close()
  })
})

// --- Accessibility, keyboard and the narrow screen ---------------------------------------------------------------------------------

for (const theme of THEMES) {
  test.describe(`no accessibility violation in the privileged viewer, ${theme} theme, colour contrast included`, () => {
    test('the library with its filters, a Draft Pack, a Series with Draft and narrowed Cards, an empty Pack and a Pack with no Category', async ({
      browser,
      baseURL,
    }) => {
      const page = await manager(browser, baseURL, theme)
      const screens: [string, string, () => Promise<void>][] = [
        [
          'the library',
          '/resource-library',
          async () => {
            await expect(entry(page, f.draft.title)).toContainText('Draft')
          },
        ],
        [
          'a Series with a Draft Card',
          `/resource-library/${f.mixed.id}`,
          async () => {
            await expect(cardNav(page)).toBeVisible()
          },
        ],
        [
          'a Card narrowed to Volunteers',
          `/resource-library/${f.mixed.id}?card=${f.mixed.narrowed.id}`,
          async () => {
            await expect(page.getByRole('article')).toContainText('Narrower than its Pack')
          },
        ],
        [
          'a Draft File Card',
          `/resource-library/${f.mixed.id}?card=${f.mixed.file.id}`,
          async () => {
            await expect(page.getByRole('link', { name: /^View PDF/ })).toBeVisible()
          },
        ],
        [
          'an empty Pack',
          `/resource-library/${f.empty.id}`,
          async () => {
            await expect(page.getByText('This Pack has no Cards yet.')).toBeVisible()
          },
        ],
        [
          'a Pack with no Category',
          `/resource-library/${f.loose.id}`,
          async () => {
            await expect(page.getByText('In Uncategorized')).toBeVisible()
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
      await page.context().close()
    })

    test('the filters and the search are reachable and used by keyboard alone, and a Draft is marked in words', async ({
      browser,
      baseURL,
    }) => {
      const page = await manager(browser, baseURL, theme)
      await page.goto('/resource-library')
      await expect(page.getByRole('heading', { level: 1, name: 'Resource Library' })).toBeFocused()

      // The Category choices come from the unfiltered library, so the control is disabled (and skipped by Tab) until they have arrived.
      await expect(page.getByLabel('Category', { exact: true })).toBeEnabled()
      await page.getByLabel('Search Resources').focus()
      await page.keyboard.press('Tab')
      await expect(page.getByLabel('Category', { exact: true })).toBeFocused()
      await page.keyboard.press('Tab')
      await expect(page.getByLabel('Publication', { exact: true })).toBeFocused()
      const answered = page.waitForResponse((r) => r.url().includes('publication=draft'))
      await page.keyboard.press('ArrowDown') // Published
      await page.keyboard.press('ArrowDown') // Drafts
      await answered
      await expect(page.getByRole('link', { name: f.draft.title, exact: true })).toBeVisible()
      await page.keyboard.press('Tab')
      await expect(page.getByLabel('Audience', { exact: true })).toBeFocused()
      await expect(entry(page, f.draft.title)).toContainText('Draft')

      // Into a Pack and along its Cards, focus following the choice, and a Draft Card marked in words in the list and in the reading.
      await page.goto(`/resource-library/${f.mixed.id}`)
      await cardNav(page).getByRole('link', { name: f.mixed.draftCard.title }).focus()
      await page.keyboard.press('Enter')
      await expect(
        page.getByRole('heading', { level: 2, name: f.mixed.draftCard.title }),
      ).toBeFocused()
      await expect(page.getByRole('article').getByText('Draft', { exact: true })).toBeVisible()
      await page.context().close()
    })
  })
}

test.describe('no viewer page scrolls sideways, even with long titles and every audience', () => {
  for (const width of [320, 375, 1280]) {
    test(`at ${String(width)}px`, async ({ browser, baseURL }) => {
      const page = await manager(browser, baseURL)
      await page.setViewportSize({ width, height: 800 })
      const routes: [string, string][] = [
        ['the library', '/resource-library'],
        ['a Pack with a very long title', `/resource-library/${f.long.id}`],
        ['a Series with Draft and narrowed Cards', `/resource-library/${f.mixed.id}`],
        ['a Draft File Card', `/resource-library/${f.mixed.id}?card=${f.mixed.file.id}`],
        ['an empty Pack', `/resource-library/${f.empty.id}`],
      ]
      for (const [name, path] of routes) {
        await page.goto(path)
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await page.waitForLoadState('networkidle')
        expect(await horizontalOverflow(page), `${name} at ${String(width)}px`).toBeLessThanOrEqual(
          0,
        )
      }
      // The check is not vacuous: the long title is really on the library page being measured.
      await page.goto('/resource-library')
      await expect(page.getByRole('link', { name: f.long.title })).toBeVisible()
      await page.context().close()
    })
  }
})
