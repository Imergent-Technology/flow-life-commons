import { expect, test, type Browser, type Page } from '@playwright/test'

import { axeViolations, inTheme, THEMES, type Theme } from './axe.ts'
import {
  makeBasicCard,
  makeCategory,
  makeFileCard,
  makeLinkCard,
  makePack,
  publishPack,
  RemoveAfter,
  ROOT,
  unique,
} from './resources.ts'
import { apiFrom, signedInAs } from './support.ts'

// Resources management, audited the way the rest of the Console is (design spec §5, §6, §11), in real Chromium and in both themes:
// no accessibility violation (colour contrast on, which jsdom cannot judge) in the states that matter, keyboard-only operation and
// where focus goes after each kind of action, the destructive dialog's focus behaviour, and no sideways scrolling at the narrow
// widths, even with long unbroken titles, file names and addresses.

test.describe.configure({ timeout: 240_000 })

const removal = new RemoveAfter()

interface Dom {
  document: {
    documentElement: { scrollWidth: number; clientWidth: number }
    activeElement: {
      tagName: string
      getAttribute: (name: string) => string | null
      closest: (selector: string) => unknown
    } | null
  }
}

/**
 * Where focus is. Inside a modal `<dialog>` is where it belongs. Chromium, tabbing past a modal's last control, moves focus to the
 * browser's own interface (which scripts see as the body) before it comes round again: that is the platform's behaviour and it is
 * fine. What must never happen is focus on a control of the page BEHIND the dialog, which is what `other` would mean.
 */
const whereIsFocus = (page: Page): Promise<'dialog' | 'browser' | 'other'> =>
  page.evaluate(() => {
    const active = (globalThis as unknown as Dom).document.activeElement
    if (active === null || active.tagName === 'BODY') return 'browser'
    return active.closest('dialog') !== null ? 'dialog' : 'other'
  })

const horizontalOverflow = (page: Page): Promise<number> =>
  page.evaluate(() => {
    const root = (globalThis as unknown as Dom).document.documentElement
    return root.scrollWidth - root.clientWidth
  })

interface Fixtures {
  categoryA: { id: string; name: string }
  packId: string
  packTitle: string
  basicId: string
  linkId: string
  fileId: string
  secondPackId: string
  longPackId: string
  longLinkId: string
  longFileId: string
  longTitle: string
}

let fixtures: Fixtures

async function signedIn(
  browser: Browser,
  baseURL: string | undefined,
  theme?: Theme,
): Promise<Page> {
  const page = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
  if (theme !== undefined) await inTheme(page, theme)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  return page
}

test.beforeAll(async ({ browser, baseURL }) => {
  const page = await signedIn(browser, baseURL)
  const categoryA = await makeCategory(page)
  removal.category(categoryA.id)
  const categoryB = await makeCategory(
    page,
    `C${'a'.repeat(60)}${crypto.randomUUID().slice(0, 8)}t`,
  )
  removal.category(categoryB.id)

  const pack = await makePack(page, { categoryId: categoryA.id, audiences: ['guardian', 'member'] })
  removal.pack(pack.id)
  const basic = await makeBasicCard(page, pack.id, {
    publish: true,
    text: 'Opening hours and who to ask.',
  })
  const link = await makeLinkCard(page, pack.id)
  const file = await makeFileCard(page, pack.id)
  await publishPack(page, pack.id)
  const second = await makePack(page, { categoryId: categoryA.id })
  removal.pack(second.id)

  // Long, unbroken strings where a title, a file name and an address can each force a page wider than the screen.
  const longTitle = `L${'o'.repeat(150)}ng`
  const longPack = await makePack(page, { title: longTitle, categoryId: categoryB.id })
  removal.pack(longPack.id)
  const longLink = await makeLinkCard(page, longPack.id, {
    title: `K${'e'.repeat(150)}y`,
    uri: `https://example.org/${'u'.repeat(400)}`,
  })
  const longFile = await makeFileCard(page, longPack.id, {
    title: 'A file with a long name',
    name: `${'f'.repeat(200)}.pdf`,
  })

  fixtures = {
    categoryA,
    packId: pack.id,
    packTitle: pack.title,
    basicId: basic.id,
    linkId: link.id,
    fileId: file.id,
    secondPackId: second.id,
    longPackId: longPack.id,
    longLinkId: longLink.id,
    longFileId: longFile.id,
    longTitle,
  }
  await page.context().close()
})

test.afterAll(async ({ browser, baseURL }) => {
  const page = await signedIn(browser, baseURL)
  await removal.run(page)
  await page.context().close()
})

/** The screens that matter, each with something to wait for so the audit sees the finished page (the editor included). */
function screens(
  f: Fixtures,
): { name: string; path: string; ready: (page: Page) => Promise<void> }[] {
  const heading = (page: Page) => expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  const editor = async (page: Page, name = 'Content') => {
    await heading(page)
    await expect(page.getByRole('textbox', { name })).toBeVisible()
  }
  return [
    {
      name: 'the Categories',
      path: '/resources/categories',
      ready: async (page) => {
        await expect(page.getByRole('list', { name: 'Categories' })).toBeVisible()
      },
    },
    {
      name: 'the Pack list',
      path: '/resources',
      ready: async (page) => {
        await expect(page.getByRole('table', { name: 'Resource Packs' })).toBeVisible()
      },
    },
    { name: 'adding a Pack', path: '/resources/new', ready: heading },
    {
      name: 'a Pack with its Cards',
      path: `/resources/packs/${f.packId}`,
      ready: async (page) => {
        await expect(page.getByRole('list', { name: 'Cards' })).toBeVisible()
      },
    },
    {
      name: 'adding a Card',
      path: `/resources/packs/${f.packId}/cards/new`,
      ready: (page) => editor(page),
    },
    {
      name: 'a Basic Card',
      path: `/resources/packs/${f.packId}/cards/${f.basicId}`,
      ready: (page) => editor(page),
    },
    {
      name: 'an External link Card',
      path: `/resources/packs/${f.packId}/cards/${f.linkId}`,
      ready: (page) => editor(page, 'Description (optional)'),
    },
    {
      name: 'a File Card',
      path: `/resources/packs/${f.packId}/cards/${f.fileId}`,
      ready: (page) => editor(page, 'Description (optional)'),
    },
  ]
}

for (const theme of THEMES) {
  test.describe(`no accessibility violation, ${theme} theme, colour contrast included`, () => {
    test('every management screen', async ({ browser, baseURL }) => {
      const page = await signedIn(browser, baseURL, theme)
      for (const screen of screens(fixtures)) {
        await page.goto(screen.path)
        await screen.ready(page)
        expect(await axeViolations(page), screen.name).toEqual([])
      }
      await page.context().close()
    })

    test('the states a person meets after acting: a refusal, a conflict, a dialog', async ({
      browser,
      baseURL,
    }) => {
      const page = await signedIn(browser, baseURL, theme)

      // A refusal in place: the Pack cannot be published yet.
      await page.goto(`/resources/packs/${fixtures.secondPackId}`)
      await page.getByRole('button', { name: 'Publish Pack' }).click()
      await expect(page.getByRole('alert')).toContainText('The Pack cannot be published yet')
      expect(await axeViolations(page), 'a refused publish').toEqual([])

      // A revision conflict, with the saved version shown beside the person's edits.
      await page.goto(`/resources/packs/${fixtures.secondPackId}`)
      const form = page.getByRole('form', { name: 'Pack details' })
      const base = (
        (await apiFrom(page, 'GET', `${ROOT}/packs/${fixtures.secondPackId}`)).body as {
          revision: number
        }
      ).revision
      await apiFrom(page, 'PATCH', `${ROOT}/packs/${fixtures.secondPackId}`, {
        revision: base,
        summary: 'Saved elsewhere',
      })
      await form.getByLabel('Summary').fill('Mine')
      await form.getByRole('button', { name: 'Save details' }).click()
      await expect(page.getByText('Someone else saved changes to this Pack first.')).toBeVisible()
      expect(await axeViolations(page), 'a revision conflict').toEqual([])

      // The destructive dialog, and the dialog that orders Packs.
      await page.goto(`/resources/packs/${fixtures.packId}/cards/${fixtures.basicId}`)
      await expect(page.getByRole('textbox', { name: 'Content' })).toBeVisible()
      await page.getByRole('button', { name: 'Delete Card…' }).click()
      await expect(
        page.getByRole('dialog', { name: 'Permanently delete this Card?' }),
      ).toBeVisible()
      expect(await axeViolations(page), 'the delete dialog').toEqual([])
      await page.keyboard.press('Escape')

      await page.goto('/resources/categories')
      await page
        .getByRole('button', { name: `Order the Packs in ${fixtures.categoryA.name}` })
        .click()
      await expect(
        page.getByRole('list', { name: `Packs in ${fixtures.categoryA.name}` }),
      ).toBeVisible()
      expect(await axeViolations(page), 'the order dialog').toEqual([])
      await page.context().close()
    })
  })
}

test.describe('the keyboard', () => {
  test('a destructive dialog holds focus inside it, closes on Escape, and gives focus back to what opened it', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    await page.goto(`/resources/packs/${fixtures.packId}/cards/${fixtures.linkId}`)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    const opener = page.getByRole('button', { name: 'Delete Card…' })
    await opener.focus()
    await page.keyboard.press('Enter')
    const dialog = page.getByRole('dialog', { name: 'Permanently delete this Card?' })
    await expect(dialog).toBeVisible()
    // It names itself, and focus is on its heading, so a screen reader is told where it is.
    await expect(
      dialog.getByRole('heading', { name: 'Permanently delete this Card?' }),
    ).toBeFocused()

    // Tab, and Shift+Tab, go round inside the dialog; nothing behind it can be reached.
    for (let press = 0; press < 8; press++) {
      await page.keyboard.press(press < 5 ? 'Tab' : 'Shift+Tab')
      expect(
        await whereIsFocus(page),
        `focus reached the page behind the dialog on press ${String(press)}`,
      ).not.toBe('other')
    }
    // Cancel is the first thing reached, so the safe choice is the easy one.
    await dialog.getByRole('heading').focus()
    await page.keyboard.press('Tab')
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused()

    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)
    await expect(opener).toBeFocused()
    // Nothing was deleted.
    expect(
      (await apiFrom(page, 'GET', `${ROOT}/packs/${fixtures.packId}/cards/${fixtures.linkId}`))
        .status,
    ).toBe(200)
    await page.context().close()
  })

  test('a Category is added, a Card published and details saved with the keyboard alone, and focus lands on the outcome each time', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    const name = unique('E2E Keyboard')

    await page.goto('/resources/categories')
    await page.getByLabel('New Category').focus()
    await page.keyboard.type(name)
    await page.keyboard.press('Enter')
    const created = page.getByText(`The Category “${name}” was created.`)
    await expect(created).toBeVisible()
    // The outcome holds focus (the field that was typed in is still there, but the person is told where they are).
    await expect(
      page.getByRole('status').filter({ hasText: `The Category “${name}” was created.` }),
    ).toBeFocused()
    const listed = (await apiFrom(page, 'GET', `${ROOT}/categories`)).body as {
      data: { id: string; name: string }[]
    }
    removal.category(listed.data.find((c) => c.name === name)?.id ?? '')

    const pack = await makePack(page, { audiences: ['guardian'] })
    removal.pack(pack.id)
    const card = await makeBasicCard(page, pack.id)
    await page.goto(`/resources/packs/${pack.id}/cards/${card.id}`)
    await expect(page.getByRole('textbox', { name: 'Content' })).toBeVisible()
    const publish = page.getByRole('button', { name: 'Publish Card' })
    await publish.focus()
    await page.keyboard.press('Enter')
    await expect(
      page.getByRole('status').filter({ hasText: 'The Card is now Published.' }),
    ).toBeFocused()

    const title = page.getByLabel('Title')
    await title.focus()
    await page.keyboard.type(' (kept)')
    await page.keyboard.press('Enter')
    await expect(page.getByRole('status').filter({ hasText: 'The Card was saved.' })).toBeFocused()
    await page.context().close()
  })

  test('a field the server refuses takes focus, so a keyboard user lands on what to fix', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedIn(browser, baseURL)
    await page.goto(`/resources/packs/${fixtures.packId}/cards/new`)
    await page.getByRole('radio', { name: /External link/ }).check()
    await page.getByLabel('Title').fill('Refused')
    await page.getByLabel('Web address').fill('javascript:alert(1)')
    await page.getByRole('button', { name: 'Create Card' }).click()

    await expect(page.getByLabel('Web address')).toBeFocused()
    await expect(page.getByLabel('Web address')).toHaveAttribute('aria-invalid', 'true')
    await page.context().close()
  })
})

const WIDTHS = [320, 375, 1280]

test.describe('no page scrolls sideways, even with long unbroken titles, file names and addresses', () => {
  for (const width of WIDTHS) {
    test(`at ${String(width)}px`, async ({ browser, baseURL }) => {
      const page = await signedIn(browser, baseURL)
      await page.setViewportSize({ width, height: 800 })
      const f = fixtures
      const routes: [string, string][] = [
        ['the Categories', '/resources/categories'],
        ['the Pack list', '/resources'],
        ['adding a Pack', '/resources/new'],
        ['a Pack', `/resources/packs/${f.packId}`],
        ['a Pack with a very long title and Card', `/resources/packs/${f.longPackId}`],
        ['adding a Card', `/resources/packs/${f.packId}/cards/new`],
        ['a Basic Card', `/resources/packs/${f.packId}/cards/${f.basicId}`],
        [
          'an External link Card with a very long address',
          `/resources/packs/${f.longPackId}/cards/${f.longLinkId}`,
        ],
        [
          'a File Card with a very long file name',
          `/resources/packs/${f.longPackId}/cards/${f.longFileId}`,
        ],
      ]
      for (const [name, path] of routes) {
        await page.goto(path)
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        if (path.includes('/cards/'))
          await expect(page.getByRole('textbox', { name: /Content|Description/ })).toBeVisible()
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
    await page.goto(`/resources/packs/${fixtures.longPackId}`)
    await expect(page.getByRole('heading', { level: 1, name: fixtures.longTitle })).toBeVisible()
    await expect(page.getByRole('link', { name: /^K/ })).toBeVisible()
    await page.goto(`/resources/packs/${fixtures.longPackId}/cards/${fixtures.longFileId}`)
    await expect(page.getByRole('region', { name: 'File' })).toContainText('f'.repeat(200))
    await page.context().close()
  })
})
