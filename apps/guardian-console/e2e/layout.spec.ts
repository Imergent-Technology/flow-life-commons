import { expect, test, type Page } from '@playwright/test'

import { aDemoPersonId, aMemberId, aThreadId, apiFrom, signedInAs } from './support.ts'

/**
 * The layout at the widths where it changes, and the sizes and motion an accessible interface promises
 * (design spec §5, §6, §11): no page scrolls sideways at any width, the shell is the right shell either side
 * of each breakpoint, controls are big enough to touch, and nothing animates for someone who asked it not to.
 *
 * Structural, in real Chromium: jsdom lays nothing out. The colours are audited in accessibility.spec.ts.
 */

test.describe.configure({ timeout: 180_000 })

interface Dom {
  document: { documentElement: { scrollWidth: number; clientWidth: number } }
  getComputedStyle: (
    element: unknown,
    pseudo?: string,
  ) => {
    animationName: string
    animationDuration: string
    transitionDuration: string
    fontSize: string
    getPropertyValue: (name: string) => string
  }
  matchMedia: (query: string) => { matches: boolean }
}

const horizontalOverflow = (page: Page): Promise<number> =>
  page.evaluate(() => {
    const root = (globalThis as unknown as Dom).document.documentElement
    return root.scrollWidth - root.clientWidth
  })

// The widths that matter: the narrowest phone, a common phone, either side of the table stack (768), the
// rail (1024) and the pinned-drawer default (1200), and a wide desktop.
const WIDTHS = [320, 375, 767, 768, 1023, 1024, 1199, 1200, 1680]

test.describe('no page scrolls sideways, and the shell changes where it says it does', () => {
  for (const width of WIDTHS) {
    test(`at ${String(width)}px`, async ({ browser, baseURL }) => {
      const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
      await admin.goto('/')
      const listed = await apiFrom(admin, 'GET', '/api/v1/admin/accounts?q=e2e.admin.read@')
      const accountId = (listed.body as { data: { id: string }[] }).data[0]?.id ?? ''
      const memberId = await aMemberId(admin)
      const personId = await aDemoPersonId(admin, 'Marguerite Hale')
      const longNotePersonId = await aDemoPersonId(admin, 'Daniel Okoye')
      const openThread = await aThreadId(admin, 'open')
      const resolvedThread = await aThreadId(admin, 'resolved')
      await admin.setViewportSize({ width, height: 800 })

      const routes = [
        '/',
        '/account/security',
        '/admin/accounts',
        '/admin/accounts/invite',
        `/admin/accounts/${accountId}`,
        '/admin/members',
        '/admin/members/new',
        `/admin/members/${memberId}`,
        '/people',
        '/people/new',
        '/people/tags',
        `/people/${personId}`, // a paged history and a tag collection
        `/people/${longNotePersonId}`, // one very long note
        '/discussions',
        '/discussions/new',
        `/discussions/${openThread}`, // an unbroken title and message, an edit and a tombstone
        `/discussions/${resolvedThread}`,
        '/no/such/page',
      ]
      for (const route of routes) {
        await admin.goto(route)
        await expect(admin.getByRole('heading', { level: 1 })).toBeVisible()
        await admin.waitForLoadState('networkidle')
        expect(
          await horizontalOverflow(admin),
          `${route} at ${String(width)}px`,
        ).toBeLessThanOrEqual(0)
      }

      // The shell, on a page whose section has a drawer.
      await admin.goto('/admin/accounts')
      await expect(admin.getByRole('heading', { level: 1, name: 'Accounts' })).toBeVisible()
      const sheetButton = admin.getByRole('button', { name: 'Navigation menu' })
      const pinned = admin.locator('[data-drawer="pinned"]')
      const rail = admin.getByRole('navigation', { name: 'Console' })
      if (width < 1024) {
        await expect(sheetButton).toBeVisible()
        await expect(pinned).toHaveCount(0)
      } else {
        await expect(sheetButton).toHaveCount(0)
        await expect(rail).toBeVisible()
        if (width < 1200) await expect(pinned).toHaveCount(0)
        else await expect(pinned).toBeVisible()
      }

      // Tables: stacked below 768px, a table from there up.
      await admin.waitForLoadState('networkidle')
      const display = await admin
        .getByRole('table', { name: 'Accounts' })
        .locator('tbody tr')
        .first()
        .evaluate((el) =>
          (globalThis as unknown as Dom).getComputedStyle(el).getPropertyValue('display'),
        )
      expect(display).toBe(width < 768 ? 'block' : 'table-row')

      await admin.context().close()
    })
  }

  test('the public pages do not scroll sideways at 320px either', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 })
    for (const path of ['/login', '/forgot-password', '/reset-password', '/accept-invitation']) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      expect(await horizontalOverflow(page), path).toBeLessThanOrEqual(0)
    }
  })
})

interface Box {
  left: number
  right: number
  width: number
}

interface Measuring {
  document: {
    querySelector: (selector: string) => Element | null
    body: { appendChild: (el: unknown) => void; removeChild: (el: unknown) => void }
    createElement: (tag: string) => { style: { backgroundColor: string } }
  }
  getComputedStyle: (element: unknown) => {
    maxWidth: string
    backgroundColor: string
    paddingLeft: string
    paddingRight: string
  }
}

interface Element {
  getBoundingClientRect: () => Box
}

/** The page column, the space the shell leaves it, and what it is capped to, as the browser lays them out. */
const measurePage = (page: Page) =>
  page.evaluate(() => {
    const g = globalThis as unknown as Measuring
    const column = g.document.querySelector('[data-page-width]')
    const main = g.document.querySelector('main')
    if (column === null || main === null) throw new Error('no page column')
    const inner = main.getBoundingClientRect()
    const style = g.getComputedStyle(main)
    const box = column.getBoundingClientRect()
    return {
      kind: (column as unknown as { dataset: { pageWidth: string } }).dataset.pageWidth,
      maxWidth: g.getComputedStyle(column).maxWidth,
      width: box.width,
      right: box.right,
      available: inner.width - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight),
      gutterRight: inner.right - box.right,
      paddingRight: parseFloat(style.paddingRight),
    }
  })

test.describe('operational lists take the full width the shell leaves them', () => {
  // 1024 rail only · 1280 pinned drawer · 1680 wide desktop · 2560 and 3440 (ultrawide), all far beyond the old 92rem (1472px) cap.
  const VIEWPORTS = [1024, 1280, 1680, 2560, 3440]

  for (const width of VIEWPORTS) {
    test(`Accounts and Members fill it at ${String(width)}px, inside the shell's gutters`, async ({
      browser,
      baseURL,
    }) => {
      const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
      await admin.setViewportSize({ width, height: 900 })
      for (const [route, name] of [
        ['/admin/accounts', 'Accounts'],
        ['/admin/members', 'Members'],
      ] as const) {
        await admin.goto(route)
        await expect(admin.getByRole('heading', { level: 1, name })).toBeVisible()
        await expect(admin.getByRole('table', { name })).toBeVisible()
        const page = await measurePage(admin)
        const at = `${route} at ${String(width)}px`
        expect(page.kind, at).toBe('wide')
        expect(page.maxWidth, `${at}: no cap`).toBe('none')
        // The column IS the available width: it neither stops early nor eats the gutter.
        expect(Math.abs(page.width - page.available), `${at}: fills the shell`).toBeLessThan(1)
        expect(page.paddingRight, `${at}: the shell keeps a right gutter`).toBeGreaterThan(0)
        expect(Math.abs(page.gutterRight - page.paddingRight), `${at}: gutter kept`).toBeLessThan(1)
        // The table itself reaches the column's right edge instead of ending early: it sits inside the
        // card's 1px border, so within 2px is flush.
        const tableRight = await admin
          .getByRole('table', { name })
          .evaluate((el) => (el as unknown as Element).getBoundingClientRect().right)
        expect(
          Math.abs(page.right - tableRight),
          `${at}: the table fills the column`,
        ).toBeLessThanOrEqual(2)
        if (width >= 2560) expect(page.width, `${at}: past the old 92rem cap`).toBeGreaterThan(1472)
      }
      expect(await horizontalOverflow(admin)).toBeLessThanOrEqual(0)
      await admin.context().close()
    })
  }

  test('prose, form and detail pages keep their own maximums at an ultrawide width', async ({
    browser,
    baseURL,
  }) => {
    const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
    await admin.setViewportSize({ width: 3440, height: 900 })
    await admin.goto('/')
    const listed = await apiFrom(admin, 'GET', '/api/v1/admin/accounts?q=e2e.admin.read@')
    const accountId = (listed.body as { data: { id: string }[] }).data[0]?.id ?? ''
    const memberId = await aMemberId(admin)
    const capped = { prose: 672, form: 576, detail: 1184 } as const // 42rem, 36rem, 74rem
    const seen = new Set<string>()
    for (const route of [
      '/',
      '/account/security',
      '/admin/accounts/invite',
      '/admin/members/new',
      `/admin/accounts/${accountId}`,
      `/admin/members/${memberId}`,
      '/no/such/page',
    ]) {
      await admin.goto(route)
      await expect(admin.getByRole('heading', { level: 1 })).toBeVisible()
      const page = await measurePage(admin)
      const kind = page.kind as keyof typeof capped
      seen.add(kind)
      expect(capped[kind], `${route} declares ${kind}`).toBeDefined()
      expect(page.maxWidth, route).toBe(`${String(capped[kind])}px`)
      expect(page.width, route).toBeLessThanOrEqual(capped[kind] + 1)
      expect(page.width, `${route} is capped, well short of the available width`).toBeLessThan(
        page.available - 200,
      )
    }
    expect([...seen].sort()).toEqual(['detail', 'form', 'prose'])
    await admin.context().close()
  })
})

test.describe('the rail is the deepest navigation plane', () => {
  /** What a token paints as, resolved by the browser itself, so `rgb(2 7 9 / .85)` and `rgba(2, 7, 9, 0.85)` compare. */
  const painted = (page: Page, token: string) =>
    page.evaluate((name) => {
      const g = globalThis as unknown as Measuring
      const probe = g.document.createElement('div')
      probe.style.backgroundColor = `var(${name})`
      g.document.body.appendChild(probe)
      const value = g.getComputedStyle(probe).backgroundColor
      g.document.body.removeChild(probe)
      return value
    }, token)

  const backgroundOf = (page: Page, selector: string) =>
    page
      .locator(selector)
      .first()
      .evaluate((el) => (globalThis as unknown as Measuring).getComputedStyle(el).backgroundColor)

  for (const [scheme, theme] of [
    ['light', 'Garden'],
    ['dark', 'Deep Tide'],
  ] as const) {
    test(`in ${theme}: the rail has its own surface, the pinned drawer keeps the navigation surface`, async ({
      browser,
      baseURL,
    }) => {
      const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
      await admin.emulateMedia({ colorScheme: scheme })
      await admin.setViewportSize({ width: 1440, height: 900 })
      await admin.goto('/admin/accounts')
      await expect(admin.locator('[data-drawer="pinned"]')).toBeVisible()

      const rail = await backgroundOf(admin, 'nav[aria-label="Console"]')
      const drawer = await backgroundOf(admin, '[data-drawer="pinned"]')
      expect(rail, 'the rail paints --nav-rail').toBe(await painted(admin, '--nav-rail'))
      expect(drawer, 'the pinned drawer paints --nav').toBe(await painted(admin, '--nav'))
      expect(rail, 'the two planes are different surfaces').not.toBe(drawer)
      // The top bar and the page are not the rail: they show the canvas.
      expect(await backgroundOf(admin, 'header'), 'the top bar stays transparent').toBe(
        'rgba(0, 0, 0, 0)',
      )
      await admin.context().close()
    })
  }

  test('the overlay drawer and the mobile sheet stay on the secondary surface, not the rail plane', async ({
    browser,
    baseURL,
  }) => {
    const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
    await admin.goto('/')
    const raised = await painted(admin, '--surface-raised')

    await admin.setViewportSize({ width: 1100, height: 800 }) // rail, drawer overlays
    await admin.goto('/admin/accounts')
    await admin
      .getByRole('navigation', { name: 'Console' })
      .getByRole('button', { name: 'Admin' })
      .click()
    await expect(admin.locator('[data-drawer="overlay"]')).toBeVisible()
    expect(await backgroundOf(admin, '[data-drawer="overlay"]')).toBe(raised)
    expect(await backgroundOf(admin, '[data-drawer="overlay"]')).not.toBe(
      await painted(admin, '--nav-rail'),
    )

    await admin.setViewportSize({ width: 600, height: 800 }) // mobile bar and sheet
    await admin.goto('/admin/accounts')
    await admin.getByRole('button', { name: 'Navigation menu' }).click()
    await expect(admin.locator('dialog[open]')).toBeVisible()
    expect(await backgroundOf(admin, 'dialog[open]')).toBe(raised)
    await admin.context().close()
  })
})

test.describe('controls are big enough to use', () => {
  test('on a desktop pointer every button, field and select is at least 24px tall, and fields read at 14px', async ({
    browser,
    baseURL,
  }) => {
    const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
    await admin.setViewportSize({ width: 1280, height: 800 })
    await admin.goto('/admin/accounts')
    await expect(admin.getByRole('table', { name: 'Accounts' })).toBeVisible()
    const heights = await admin.evaluate(() => {
      const g = globalThis as unknown as {
        document: {
          querySelectorAll: (
            s: string,
          ) => { getBoundingClientRect: () => { height: number; width: number } }[]
        }
      }
      return [...g.document.querySelectorAll('main button, main input, main select, header button')]
        .map((el) => el.getBoundingClientRect())
        .filter((box) => box.width > 0 && box.height > 0)
        .map((box) => box.height)
    })
    expect(heights.length).toBeGreaterThan(3)
    expect(Math.min(...heights)).toBeGreaterThanOrEqual(24)
    const field = await admin
      .getByLabel('Name or email')
      .evaluate((el) => (globalThis as unknown as Dom).getComputedStyle(el).fontSize)
    expect(field).toBe('14px')
    await admin.context().close()
  })

  test('on a touch screen every button, field and select is at least 40px tall, and fields read at 16px', async ({
    browser,
    baseURL,
  }) => {
    const admin = await signedInAs(browser, baseURL ?? '', 'admin-read', {
      viewport: { width: 390, height: 844 },
      hasTouch: true,
      isMobile: true,
    })
    await admin.goto('/')
    expect(
      await admin.evaluate(
        () => (globalThis as unknown as Dom).matchMedia('(pointer: coarse)').matches,
      ),
      'the emulation really is a coarse pointer',
    ).toBe(true)

    const measure = () =>
      admin.evaluate(() => {
        const g = globalThis as unknown as {
          document: {
            querySelectorAll: (s: string) => {
              getBoundingClientRect: () => { height: number; width: number }
              textContent: string | null
              closest: (selector: string) => unknown
            }[]
          }
        }
        return [
          ...g.document.querySelectorAll(
            'main button, main input, main select, header button, header a[href]',
          ),
        ]
          .filter((el) => el.closest('[aria-hidden="true"]') === null) // the password manager's hidden username
          .map((el) => ({ box: el.getBoundingClientRect(), name: (el.textContent ?? '').trim() }))
          .filter(({ box }) => box.width > 0 && box.height > 0)
          .map(({ box, name }) => ({ height: box.height, name }))
      })

    for (const route of [
      '/admin/accounts',
      `/admin/members/${await aMemberId(admin)}`,
      '/account/security',
    ]) {
      await admin.goto(route)
      await expect(admin.getByRole('heading', { level: 1 })).toBeVisible()
      await admin.waitForLoadState('networkidle')
      const small = (await measure()).filter(({ height }) => height < 40)
      expect(small, `controls under 40px on ${route}`).toEqual([])
    }

    await admin.goto('/admin/accounts')
    await expect(admin.getByLabel('Name or email')).toBeVisible()
    expect(
      await admin
        .getByLabel('Name or email')
        .evaluate((el) => (globalThis as unknown as Dom).getComputedStyle(el).fontSize),
    ).toBe('16px')
    await admin.context().close()
  })
})

test.describe('someone who asked for reduced motion is not animated at', () => {
  const animated = async (page: Page, selector: string) =>
    page
      .locator(selector)
      .first()
      .evaluate((el) => {
        const style = (globalThis as unknown as Dom).getComputedStyle(el)
        return { name: style.animationName, duration: style.animationDuration }
      })

  test('the drawer, dialogs, buttons and skeletons', async ({ browser, baseURL }) => {
    for (const reduce of [true, false]) {
      const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
      await admin.emulateMedia({ reducedMotion: reduce ? 'reduce' : 'no-preference' })

      // A slow list, so its skeleton is on screen long enough to be measured.
      await admin.route('**/api/v1/admin/accounts?*', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 1500))
        await route.continue()
      })
      await admin.setViewportSize({ width: 1100, height: 800 })
      await admin.goto('/admin/accounts')
      const skeleton = await animated(admin, '[aria-busy="true"] [class*="animate-pulse"]')
      const token = await admin.evaluate(() =>
        (globalThis as unknown as Dom)
          .getComputedStyle(
            (globalThis as unknown as { document: { documentElement: unknown } }).document
              .documentElement,
          )
          .getPropertyValue('--duration-slow')
          .trim(),
      )
      await expect(admin.getByRole('table', { name: 'Accounts' })).toBeVisible()

      // The overlay drawer slides in, unless asked not to.
      await admin
        .getByRole('navigation', { name: 'Console' })
        .getByRole('button', { name: 'Admin' })
        .click()
      const drawer = await animated(admin, '[data-drawer="overlay"]')
      const hover = await admin
        .getByRole('button', { name: 'Search' })
        .evaluate((el) => (globalThis as unknown as Dom).getComputedStyle(el).transitionDuration)

      if (reduce) {
        expect(skeleton.name, 'skeleton').toBe('none')
        expect(drawer.name, 'drawer').toBe('none')
        expect(token).toBe('0ms')
        expect(hover, 'button transition').toBe('0s')
      } else {
        // The control: with no preference the same things DO move, so the assertions above can fail.
        expect(skeleton.name, 'skeleton (control)').toBe('pulse')
        expect(drawer.name, 'drawer (control)').toBe('drawer-in')
        expect(token).toBe('180ms')
        expect(hover, 'button transition (control)').not.toBe('0s')
      }
      await admin.context().close()
    }
  })

  test('a confirmation dialog opens without animation', async ({ browser, baseURL }) => {
    const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
    await admin.emulateMedia({ reducedMotion: 'reduce' })
    await admin.goto('/')
    const listed = await apiFrom(admin, 'GET', '/api/v1/admin/accounts?q=e2e.admin.target@')
    const id = (listed.body as { data: { id: string }[] }).data[0]?.id ?? ''
    await admin.goto(`/admin/accounts/${id}`)
    await admin.getByRole('button', { name: 'Disable this account' }).click()
    await expect(admin.getByRole('dialog')).toBeVisible()
    const dialog = await animated(admin, 'dialog[open]')
    expect(dialog.name).toBe('none')
    await admin.keyboard.press('Escape')
    await admin.context().close()
  })
})
