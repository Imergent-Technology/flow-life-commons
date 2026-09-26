import { expect, test, type Page } from '@playwright/test'

import { aMemberId, apiFrom, signedInAs } from './support.ts'

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
