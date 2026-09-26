import { expect, test, type Page } from '@playwright/test'

import {
  expectOnlyUiPreferences,
  goToAccountSecurity,
  openAccountMenu,
  signedInAs,
} from './support.ts'

/**
 * The application shell (ADR 0030), in a real browser: the rail and drawer, the mobile
 * sheet, the top bar, the breadcrumbs and the account menu. Focus, dialogs, media queries and the
 * production policy are exactly what jsdom cannot show, so these journeys are where they are proved.
 *
 * They start from sessions the platform minted by its own sign-in (`signedInAs`), so none of them spends
 * the public login budget. `admin-read` may use every navigation item; `plain-guardian` may use the Console
 * and nothing more.
 */

const STORAGE_KEY = 'flowlife.console.ui'
const PORT = new URL(process.env.E2E_BASE_URL ?? 'http://commons.flowlife.localhost:18080').port
const PROD = `http://prod.flowlife.localhost:${PORT}`

/** The e2e project compiles without the DOM library, so browser-side code is typed through this shape. */
interface Dom {
  document: {
    activeElement: {
      tagName: string
      closest: (selector: string) => unknown
      getAttribute: (name: string) => string | null
      textContent: string | null
    } | null
    documentElement: { dataset: { theme?: string } }
    querySelector: (selector: string) => { matches: (selector: string) => boolean } | null
    body: unknown
  }
  localStorage: {
    getItem: (key: string) => string | null
    setItem: (key: string, value: string) => void
  }
  getComputedStyle: (
    element: unknown,
    pseudo?: string,
  ) => { backgroundColor: string; position: string; animationDuration: string; width: string }
  __cspViolations?: string[]
}

const stored = (page: Page): Promise<string | null> =>
  page.evaluate((key) => (globalThis as unknown as Dom).localStorage.getItem(key), STORAGE_KEY)

const themeOf = (page: Page): Promise<string | undefined> =>
  page.evaluate(() => (globalThis as unknown as Dom).document.documentElement.dataset.theme)

const focusIsInside = (page: Page, selector: string): Promise<boolean> =>
  page.evaluate(
    (sel) => (globalThis as unknown as Dom).document.activeElement?.closest(sel) != null,
    selector,
  )

const consoleNav = (page: Page) => page.getByRole('navigation', { name: 'Console' })
const railAdmin = (page: Page) => consoleNav(page).getByRole('button', { name: 'Admin' })
const pinned = (page: Page) => page.locator('[data-drawer="pinned"]')
const overlay = (page: Page) => page.locator('[data-drawer="overlay"]')
const menuButton = (page: Page) => page.getByRole('button', { name: /account menu/i })
const sheetButton = (page: Page) => page.getByRole('button', { name: 'Navigation menu' })
const h1 = (page: Page, name: string) => page.getByRole('heading', { level: 1, name })
const path = (page: Page) => new URL(page.url()).pathname

async function admin(browser: Parameters<typeof signedInAs>[0], baseURL: string | undefined) {
  return signedInAs(browser, baseURL ?? '', 'admin-read')
}

test.describe('account menu', () => {
  test('is a real menu: keyboard opens it, moves in it, and puts focus back', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.goto('/')
    const trigger = menuButton(page)
    await expect(trigger).toHaveAttribute('aria-haspopup', 'menu')
    await expect(trigger).toHaveAttribute('aria-expanded', 'false')

    // Enter opens it on the first item; the button names the state and what it controls.
    await trigger.focus()
    await page.keyboard.press('Enter')
    const menu = page.getByRole('menu', { name: 'Account' })
    await expect(menu).toBeVisible()
    await expect(trigger).toHaveAttribute('aria-expanded', 'true')
    const controlled = await trigger.getAttribute('aria-controls')
    await expect(page.locator(`[id="${controlled ?? ''}"]`)).toBeVisible()
    await expect(menu.getByRole('menuitem', { name: 'Account security' })).toBeFocused()

    // ↓ ↑ Home End move, and wrap.
    await page.keyboard.press('ArrowDown')
    await expect(menu.getByRole('menuitemradio', { name: 'System' })).toBeFocused()
    await page.keyboard.press('End')
    await expect(menu.getByRole('menuitem', { name: 'Sign out' })).toBeFocused()
    await page.keyboard.press('ArrowDown')
    await expect(menu.getByRole('menuitem', { name: 'Account security' })).toBeFocused()
    await page.keyboard.press('ArrowUp')
    await expect(menu.getByRole('menuitem', { name: 'Sign out' })).toBeFocused()
    await page.keyboard.press('Home')
    await expect(menu.getByRole('menuitem', { name: 'Account security' })).toBeFocused()

    // ← → move within the Theme choices only.
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('ArrowRight')
    await expect(menu.getByRole('menuitemradio', { name: 'Light' })).toBeFocused()
    await page.keyboard.press('ArrowRight')
    await expect(menu.getByRole('menuitemradio', { name: 'Dark' })).toBeFocused()
    await page.keyboard.press('ArrowRight')
    await expect(menu.getByRole('menuitemradio', { name: 'System' })).toBeFocused()
    await page.keyboard.press('ArrowLeft')
    await expect(menu.getByRole('menuitemradio', { name: 'Dark' })).toBeFocused()

    // Escape closes it and focus returns to the button.
    await page.keyboard.press('Escape')
    await expect(menu).toBeHidden()
    await expect(trigger).toBeFocused()
    await expect(trigger).toHaveAttribute('aria-expanded', 'false')

    // ↑ opens on the LAST item; Space opens on the first.
    await page.keyboard.press('ArrowUp')
    await expect(menu.getByRole('menuitem', { name: 'Sign out' })).toBeFocused()
    await page.keyboard.press('Escape')
    await page.keyboard.press('Space')
    await expect(menu.getByRole('menuitem', { name: 'Account security' })).toBeFocused()

    // Tab closes it and carries on from the button, never trapping.
    await page.keyboard.press('Tab')
    await expect(menu).toBeHidden()
    await expect(trigger).not.toBeFocused()
    expect(await focusIsInside(page, '[role="menu"]')).toBe(false)
  })

  test('closes on a click outside, without taking focus back', async ({ browser, baseURL }) => {
    const page = await admin(browser, baseURL)
    await page.goto('/')
    await menuButton(page).click()
    await expect(page.getByRole('menu', { name: 'Account' })).toBeVisible()

    await page.mouse.click(640, 500)
    await expect(page.getByRole('menu', { name: 'Account' })).toBeHidden()
    await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'false')
  })

  test('changes the theme at once, keeps the menu open, and remembers it', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.goto('/')
    const menu = await openAccountMenu(page)
    await expect(menu.getByRole('menuitemradio', { name: 'System' })).toHaveAttribute(
      'aria-checked',
      'true',
    )
    const groundBefore = await page.evaluate(
      () =>
        (globalThis as unknown as Dom).getComputedStyle(
          (globalThis as unknown as Dom).document.body,
        ).backgroundColor,
    )

    await menu.getByRole('menuitemradio', { name: 'Dark' }).click()

    expect(await themeOf(page)).toBe('dark')
    await expect(menu).toBeVisible() // the choice does not close it
    await expect(menu.getByRole('menuitemradio', { name: 'Dark' })).toHaveAttribute(
      'aria-checked',
      'true',
    )
    await expect(menu.getByRole('menuitemradio', { name: 'System' })).toHaveAttribute(
      'aria-checked',
      'false',
    )
    const groundAfter = await page.evaluate(
      () =>
        (globalThis as unknown as Dom).getComputedStyle(
          (globalThis as unknown as Dom).document.body,
        ).backgroundColor,
    )
    expect(groundAfter).not.toBe(groundBefore)
    expect(groundAfter).toBe('rgb(14, 20, 23)') // Deep Tide's --background
    expect(JSON.parse((await stored(page)) ?? 'null')).toEqual({ v: 1, theme: 'dark' })
    await expectOnlyUiPreferences(page)

    await page.reload()
    expect(await themeOf(page)).toBe('dark')
    await menuButton(page).click()
    await expect(page.getByRole('menuitemradio', { name: 'Dark' })).toHaveAttribute(
      'aria-checked',
      'true',
    )
  })

  test('leads to Account security, and hands the new page its heading focus', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.goto('/')
    await goToAccountSecurity(page)

    await expect(h1(page, 'Account security')).toBeFocused()
    expect(path(page)).toBe('/account/security')
    await expect(page.getByRole('menu', { name: 'Account' })).toBeHidden()
    // Account security is a person's own, not a place in the navigation.
    await expect(consoleNav(page).getByRole('link', { name: /security/i })).toHaveCount(0)
  })
})

test.describe('desktop navigation', () => {
  test('pins the drawer by default on a wide window, beside the page', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto('/admin/accounts')

    await expect(pinned(page)).toBeVisible()
    await expect(pinned(page).getByRole('heading', { name: 'Administration' })).toBeVisible()
    const current = pinned(page).getByRole('link', { name: 'All accounts' })
    await expect(current).toHaveAttribute('aria-current', 'page')
    await expect(consoleNav(page).getByRole('link', { name: 'Admin' })).toHaveAttribute(
      'aria-current',
      'true',
    )
    // It pushes the page: the two do not overlap.
    const drawer = await pinned(page).boundingBox()
    const main = await page.locator('main').boundingBox()
    expect(drawer).not.toBeNull()
    expect(main).not.toBeNull()
    expect((drawer?.x ?? 0) + (drawer?.width ?? 0)).toBeLessThanOrEqual((main?.x ?? 0) + 1)
    // No universal narrow cap: the page column gets the width the shell has left.
    expect(main?.width ?? 0).toBeGreaterThan(896) // the old shell's max-w-4xl
    expect(await stored(page)).toBeNull() // showing a default writes nothing
  })

  test('overlays the drawer on a mid-width window: open, Escape, and focus goes back', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1100, height: 800 })
    await page.goto('/')

    const toggle = railAdmin(page)
    await expect(toggle).toHaveAttribute('aria-expanded', 'false')
    await expect(toggle).toHaveAttribute('aria-controls', 'navigation-drawer')
    await expect(overlay(page)).toHaveCount(0)
    await expect(pinned(page)).toHaveCount(0)

    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-expanded', 'true')
    await expect(overlay(page)).toBeVisible()
    // Not on a page of this section, so focus starts on its first item.
    await expect(overlay(page).getByRole('link', { name: 'All accounts' })).toBeFocused()
    // Non-modal, no scrim: it floats over the page and the page stays in the tab order.
    await expect(page.locator('dialog[open]')).toHaveCount(0)
    const style = await overlay(page).evaluate((element) => {
      const g = globalThis as unknown as Dom
      const computed = g.getComputedStyle(element)
      return { position: computed.position, width: computed.width }
    })
    expect(style.position).toBe('fixed')
    expect(style.width).toBe('256px')

    await page.keyboard.press('Escape')
    await expect(overlay(page)).toHaveCount(0)
    await expect(toggle).toBeFocused()
    await expect(toggle).toHaveAttribute('aria-expanded', 'false')
  })

  test('puts the overlay in the tab order right after the rail, so Shift+Tab goes back to the rail, not to the end of the page', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1100, height: 800 })
    await page.goto('/admin/accounts')
    await expect(page.getByRole('table', { name: 'Accounts' })).toBeVisible()
    // The page has pagination at its very end: where the old order sent Shift+Tab.
    await expect(page.getByRole('button', { name: 'Next' })).toBeVisible()

    await railAdmin(page).click()
    await expect(overlay(page)).toBeVisible()
    // Opening still moves focus to the current page in the drawer.
    await expect(overlay(page).getByRole('link', { name: 'All accounts' })).toBeFocused()

    // In the document, the drawer follows the rail and precedes the top bar and the page.
    const order = await page.evaluate(() => {
      const g = globalThis as unknown as {
        document: {
          querySelector: (s: string) => {
            compareDocumentPosition: (other: unknown) => number
          } | null
        }
      }
      const drawer = g.document.querySelector('[data-drawer="overlay"]')
      const rail = g.document.querySelector('nav[aria-label="Console"]')
      return {
        railToDrawer: rail?.compareDocumentPosition(drawer),
        drawerToHeader: drawer?.compareDocumentPosition(g.document.querySelector('header')),
        drawerToMain: drawer?.compareDocumentPosition(g.document.querySelector('main')),
      }
    })
    const following = 4 // Node.DOCUMENT_POSITION_FOLLOWING
    expect((order.railToDrawer ?? 0) & following).toBe(following)
    expect((order.drawerToHeader ?? 0) & following).toBe(following)
    expect((order.drawerToMain ?? 0) & following).toBe(following)

    // Shift+Tab walks back through the drawer's own controls, then out to the rail: it closes, the rail control
    // that took focus keeps it, and nothing in the page (least of all its last control) is where focus went.
    await page.keyboard.press('Shift+Tab')
    await expect(
      overlay(page).getByRole('button', { name: 'Close navigation panel' }),
    ).toBeFocused()
    await page.keyboard.press('Shift+Tab')
    await expect(overlay(page).getByRole('button', { name: 'Pin navigation panel' })).toBeFocused()
    await expect(overlay(page)).toBeVisible() // still open while focus is inside it
    await page.keyboard.press('Shift+Tab')
    await expect(overlay(page)).toHaveCount(0)
    await expect(railAdmin(page)).toBeFocused()
    await expect(railAdmin(page)).toHaveAttribute('aria-expanded', 'false')
    expect(await focusIsInside(page, 'main')).toBe(false)
  })

  test('closes when Tab leaves the overlay, and focus goes on into the page rather than back to the rail', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1100, height: 800 })
    await page.goto('/admin/accounts')
    await expect(page.getByRole('table', { name: 'Accounts' })).toBeVisible()

    await railAdmin(page).click()
    await expect(overlay(page)).toBeVisible()
    await overlay(page).getByRole('link', { name: 'Add a member' }).focus()
    await page.keyboard.press('Tab')

    // The overlay is closed before focus reaches anything it was covering, and nothing bounced focus back.
    await expect(overlay(page)).toHaveCount(0)
    await expect(menuButton(page)).toBeFocused() // the next thing in the document
    await expect(railAdmin(page)).not.toBeFocused()
    expect(await focusIsInside(page, 'main')).toBe(false)
    await expect(railAdmin(page)).toHaveAttribute('aria-expanded', 'false')
  })

  test('still closes on Escape and returns focus to the rail control, from anywhere inside it', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1100, height: 800 })
    await page.goto('/admin/accounts')
    await expect(page.getByRole('table', { name: 'Accounts' })).toBeVisible()

    await railAdmin(page).click()
    await overlay(page).getByRole('link', { name: 'All members' }).focus()
    await page.keyboard.press('Escape')
    await expect(overlay(page)).toHaveCount(0)
    await expect(railAdmin(page)).toBeFocused()
  })

  test('opens the overlay on the current page, closes on an outside press, and on choosing a page', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1100, height: 800 })
    await page.goto('/admin/accounts/invite')
    await expect(h1(page, 'Invite an operator')).toBeVisible()

    await railAdmin(page).click()
    const invite = overlay(page).getByRole('link', { name: 'Invite an operator' })
    await expect(invite).toHaveAttribute('aria-current', 'page')
    await expect(invite).toBeFocused()
    // "All accounts" is not current on the invitation page: exact pages win over the detail pattern.
    await expect(overlay(page).getByRole('link', { name: 'All accounts' })).not.toHaveAttribute(
      'aria-current',
      'page',
    )

    await page.mouse.click(700, 500)
    await expect(overlay(page)).toHaveCount(0)

    await railAdmin(page).click()
    await overlay(page).getByRole('link', { name: 'All members' }).click()
    await expect(overlay(page)).toHaveCount(0)
    expect(path(page)).toBe('/admin/members')
    await expect(h1(page, 'Members')).toBeFocused()
  })

  test('honours an explicit pin or unpin across a reload, and stores only that', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto('/admin/accounts')
    await expect(pinned(page)).toBeVisible()
    expect(await stored(page)).toBeNull()

    await pinned(page).getByRole('button', { name: 'Unpin navigation panel' }).click()
    await expect(pinned(page)).toHaveCount(0)
    expect(JSON.parse((await stored(page)) ?? 'null')).toEqual({
      v: 1,
      theme: 'system',
      nav: 'overlay',
    })
    await expectOnlyUiPreferences(page)

    await page.reload()
    await expect(pinned(page)).toHaveCount(0) // an explicit choice beats the wide default
    await expect(railAdmin(page)).toBeVisible()

    // And the other way, at a width where the default is overlay.
    await page.setViewportSize({ width: 1100, height: 800 })
    await railAdmin(page).click()
    await overlay(page).getByRole('button', { name: 'Pin navigation panel' }).click()
    await expect(pinned(page)).toBeVisible()
    expect(JSON.parse((await stored(page)) ?? 'null')).toMatchObject({ nav: 'pinned' })

    await page.reload()
    await expect(pinned(page)).toBeVisible() // honoured below the pin breakpoint too
    await expectOnlyUiPreferences(page)
  })

  test('has no drawer, and no empty column, on a section with nothing beneath it', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto('/admin/accounts')
    await expect(pinned(page)).toBeVisible()
    const withDrawer = (await page.locator('main').boundingBox())?.width ?? 0

    await consoleNav(page).getByRole('link', { name: 'Overview' }).click()
    await expect(h1(page, 'Overview')).toBeFocused()
    await expect(pinned(page)).toHaveCount(0)
    const without = (await page.locator('main').boundingBox())?.width ?? 0
    expect(without).toBeGreaterThan(withDrawer + 200) // the reclaimed drawer width
  })

  test('shows breadcrumbs on detail and form pages only, from the same model', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.goto('/admin/accounts')
    await expect(h1(page, 'Accounts')).toBeVisible()
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toHaveCount(0)

    await page.goto('/admin/accounts/invite')
    const crumbs = page.getByRole('navigation', { name: 'Breadcrumb' })
    await expect(crumbs).toContainText('Accounts')
    await expect(crumbs.getByText('Invite an operator')).toHaveAttribute('aria-current', 'page')

    await page.goto('/admin/accounts')
    await page.getByRole('table', { name: 'Accounts' }).getByRole('link').first().click()
    // Wait for the detail page itself: until it renders, the h1 is still the list's "Accounts".
    await expect(page).toHaveURL(/\/admin\/accounts\/[^/]+$/)
    await expect(
      page.getByRole('heading', { level: 1, name: 'Accounts', exact: true }),
    ).toHaveCount(0)
    const name = await page.getByRole('heading', { level: 1 }).textContent()
    await expect(crumbs.getByText(name ?? '', { exact: true })).toHaveAttribute(
      'aria-current',
      'page',
    )
    // The item stays current on a detail page, without claiming to be the current PAGE: a page has one, the
    // breadcrumb's last crumb, and the navigation only says where it belongs.
    await expect(pinned(page).getByRole('link', { name: 'All accounts' })).toHaveAttribute(
      'aria-current',
      'true',
    )
    await expect(page.locator('[aria-current="page"]')).toHaveCount(1)

    // Choosing the parent crumb goes up, and the page heading takes focus as it does everywhere.
    await crumbs.getByRole('link', { name: 'Accounts' }).click()
    await expect(h1(page, 'Accounts')).toBeFocused()
    expect(path(page)).toBe('/admin/accounts')
  })

  test('moves focus to the new page heading from the drawer', async ({ browser, baseURL }) => {
    const page = await admin(browser, baseURL)
    await page.goto('/admin/accounts')
    await pinned(page).getByRole('link', { name: 'Invite an operator' }).click()
    await expect(h1(page, 'Invite an operator')).toBeFocused()
  })
})

test.describe('responsive defaults', () => {
  test('follow the width across both breakpoints and write nothing', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1300, height: 800 })
    await page.goto('/admin/accounts')

    await expect(h1(page, 'Accounts')).toBeVisible()
    const modeAt = async (width: number) => {
      await page.setViewportSize({ width, height: 800 })
      const snapshot = async () => ({
        rail: await consoleNav(page).getByRole('link', { name: 'Overview' }).count(),
        sheetButton: await sheetButton(page).count(),
        pinned: await pinned(page).count(),
      })
      return snapshot
    }
    const expectMode = async (
      width: number,
      expected: { rail: number; sheetButton: number; pinned: number },
    ) => {
      const snapshot = await modeAt(width)
      await expect.poll(snapshot, { message: `at ${String(width)}px` }).toEqual(expected)
    }

    await expectMode(1300, { rail: 1, sheetButton: 0, pinned: 1 })
    await expectMode(1200, { rail: 1, sheetButton: 0, pinned: 1 }) // at the pin breakpoint
    await expectMode(1199, { rail: 1, sheetButton: 0, pinned: 0 }) // just below it: overlay
    await expect(railAdmin(page)).toBeVisible()
    await expectMode(1024, { rail: 1, sheetButton: 0, pinned: 0 }) // at the rail breakpoint
    await expectMode(1023, { rail: 0, sheetButton: 1, pinned: 0 }) // just below it: mobile
    await expectMode(375, { rail: 0, sheetButton: 1, pinned: 0 })
    await expectMode(1200, { rail: 1, sheetButton: 0, pinned: 1 }) // and back again

    // Every crossing re-derived the layout; none stored anything.
    expect(await stored(page)).toBeNull()
  })

  test('are ignored, not erased, by the mobile shell', async ({ browser, baseURL }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto('/admin/accounts')
    await pinned(page).getByRole('button', { name: 'Unpin navigation panel' }).click()
    const explicit = await stored(page)
    expect(JSON.parse(explicit ?? 'null')).toMatchObject({ nav: 'overlay' })

    await page.setViewportSize({ width: 375, height: 800 })
    await expect(sheetButton(page)).toBeVisible() // the sheet wins whatever was chosen
    await expect(overlay(page)).toHaveCount(0)
    expect(await stored(page)).toBe(explicit) // not consulted, not cleared

    await page.setViewportSize({ width: 1400, height: 800 })
    await expect(railAdmin(page)).toBeVisible() // relevant again: the explicit overlay choice
    await expect(pinned(page)).toHaveCount(0)
    expect(await stored(page)).toBe(explicit)
  })
})

test.describe('the breadcrumbs on a phone', () => {
  const trail = (page: Page) => page.getByRole('navigation', { name: 'Breadcrumb' })

  test('give detail and form pages a way back up, below the top bar, from the same model', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 375, height: 800 })

    await page.goto('/admin/accounts/invite')
    await expect(h1(page, 'Invite an operator')).toBeVisible()
    await expect(trail(page)).toHaveCount(1)
    await expect(trail(page).getByText('Invite an operator')).toHaveAttribute(
      'aria-current',
      'page',
    )
    const bar = await page.locator('header').boundingBox()
    const box = await trail(page).boundingBox()
    expect(bar).not.toBeNull()
    expect(box?.y ?? 0).toBeGreaterThanOrEqual((bar?.y ?? 0) + (bar?.height ?? 0) - 1) // below the bar, not in it
    expect((box?.x ?? 0) + (box?.width ?? 0)).toBeLessThanOrEqual(375) // and inside the screen

    // An account's own page: named by what it loaded, and the trail leads back to the list.
    await page.goto('/admin/accounts')
    await page.getByRole('table', { name: 'Accounts' }).getByRole('link').first().click()
    await expect(page).toHaveURL(/\/admin\/accounts\/[^/]+$/)
    await expect(
      page.getByRole('heading', { level: 1, name: 'Accounts', exact: true }),
    ).toHaveCount(0)
    const name = await page.getByRole('heading', { level: 1 }).textContent()
    await expect(trail(page).getByText(name ?? '', { exact: true })).toHaveAttribute(
      'aria-current',
      'page',
    )
    await trail(page).getByRole('link', { name: 'Accounts' }).click()
    await expect(h1(page, 'Accounts')).toBeFocused()
    expect(path(page)).toBe('/admin/accounts')
    await expect(trail(page)).toHaveCount(0)
    await page.context().close()
  })

  test('appear only where the model supplies a trail, and are not doubled on a desktop', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 375, height: 800 })
    for (const route of ['/', '/admin/accounts', '/admin/members', '/account/security']) {
      await page.goto(route)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      await expect(trail(page), route).toHaveCount(0)
    }
    await page.setViewportSize({ width: 1400, height: 800 })
    await page.goto('/admin/members/new')
    await expect(h1(page, 'Add a new member')).toBeVisible()
    await expect(trail(page)).toHaveCount(1) // the top bar's, once
    await page.context().close()
  })
})

test.describe('mobile navigation sheet', () => {
  test.use({ viewport: { width: 375, height: 800 } })

  test('is a modal dialog: labelled, scrimmed, trapping, and it gives focus back', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 375, height: 800 })
    await page.goto('/admin/accounts')

    await expect(consoleNav(page)).toHaveCount(0) // no rail below the breakpoint
    const opener = sheetButton(page)
    await expect(opener).toHaveAttribute('aria-expanded', 'false')
    await opener.click()

    const sheet = page.getByRole('dialog', { name: 'Navigation' })
    await expect(sheet).toBeVisible()
    await expect(opener).toHaveAttribute('aria-expanded', 'true')
    const modal = await page.evaluate(() =>
      (globalThis as unknown as Dom).document.querySelector('dialog')?.matches(':modal'),
    )
    expect(modal).toBe(true)
    const scrim = await page.evaluate(() => {
      const g = globalThis as unknown as Dom
      return g.getComputedStyle(g.document.querySelector('dialog'), '::backdrop').backgroundColor
    })
    expect(scrim).toBe('rgba(29, 26, 38, 0.4)') // Garden's --scrim
    expect((await sheet.boundingBox())?.width).toBeLessThanOrEqual(300)

    // Full labels, sections and groups, filtered by capability; the current page says so.
    await expect(sheet.getByText('Flow Life Commons')).toBeVisible()
    await expect(sheet.getByRole('heading', { name: 'Administration' })).toBeVisible()
    await expect(sheet.getByRole('heading', { name: 'Accounts' })).toBeVisible()
    const current = sheet.getByRole('link', { name: 'All accounts' })
    await expect(current).toHaveAttribute('aria-current', 'page')
    // Deterministic initial focus: the current page.
    await expect(current).toBeFocused()

    // Modal: the page behind is inert, so Tab never reaches it in either direction. Past the last control
    // the browser hands focus to its own chrome (which shows as the document body), never to the page.
    for (const key of ['Tab', 'Shift+Tab']) {
      for (let step = 0; step < 14; step += 1) {
        await page.keyboard.press(key)
        const inDialog = await focusIsInside(page, 'dialog')
        const onBody = await page.evaluate(
          () => (globalThis as unknown as Dom).document.activeElement?.tagName === 'BODY',
        )
        expect(inDialog || onBody, `after ${key}: focus is in the sheet or in browser chrome`).toBe(
          true,
        )
      }
    }

    // Escape closes it and focus returns to the menu button.
    await page.keyboard.press('Escape')
    await expect(sheet).toBeHidden()
    await expect(opener).toBeFocused()
    await expect(opener).toHaveAttribute('aria-expanded', 'false')

    // So does a press on the scrim.
    await opener.click()
    await expect(sheet).toBeVisible()
    await page.mouse.click(350, 400)
    await expect(sheet).toBeHidden()
    await expect(opener).toBeFocused()
  })

  test('closes on choosing a page, and the new page heading takes focus, not the menu button', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 375, height: 800 })
    await page.goto('/')
    const opener = sheetButton(page)

    await opener.click()
    const sheet = page.getByRole('dialog', { name: 'Navigation' })
    await expect(sheet.getByRole('link', { name: 'Overview' })).toHaveAttribute(
      'aria-current',
      'page',
    )
    await sheet.getByRole('link', { name: 'All members' }).click()

    await expect(sheet).toBeHidden()
    expect(path(page)).toBe('/admin/members')
    await expect(h1(page, 'Members')).toBeFocused()
    await expect(opener).not.toBeFocused()

    // Choosing the page already showing: nothing else will move focus, so it goes back to the button.
    await opener.click()
    await sheet.getByRole('link', { name: 'All members' }).click()
    await expect(sheet).toBeHidden()
    expect(path(page)).toBe('/admin/members')
    await expect(opener).toBeFocused()
  })

  test('shows the account menu as an avatar alone', async ({ browser, baseURL }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 375, height: 800 })
    await page.goto('/')
    const trigger = page.getByRole('button', { name: 'Account menu', exact: true })
    await expect(trigger).toBeVisible()
    await expect(trigger).toHaveAttribute('aria-haspopup', 'menu')
    await trigger.click()
    const box = await page.getByRole('menu', { name: 'Account' }).boundingBox()
    expect((box?.x ?? -1) + (box?.width ?? 0)).toBeLessThanOrEqual(375) // fits the screen
  })

  test('does not animate for someone who asked for reduced motion', async ({
    browser,
    baseURL,
  }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 375, height: 800 })
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await page.goto('/')
    await sheetButton(page).click()
    const duration = await page.evaluate(
      () =>
        (globalThis as unknown as Dom).getComputedStyle(
          (globalThis as unknown as Dom).document.querySelector('dialog'),
        ).animationDuration,
    )
    expect(duration).toBe('0s')
  })
})

test.describe('what is shown follows capability', () => {
  test('an administrator sees Admin and every page in it', async ({ browser, baseURL }) => {
    const page = await admin(browser, baseURL)
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto('/admin/accounts')
    for (const name of ['All accounts', 'Invite an operator', 'All members', 'Add a member']) {
      await expect(pinned(page).getByRole('link', { name })).toBeVisible()
    }
  })

  test('a guardian with no capability sees Overview alone, and is refused the pages', async ({
    browser,
    baseURL,
  }) => {
    const page = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto('/')
    await expect(consoleNav(page).getByRole('link', { name: 'Overview' })).toBeVisible()
    await expect(consoleNav(page).getByRole('link', { name: 'Admin' })).toHaveCount(0)
    await expect(consoleNav(page).getByRole('button', { name: 'Admin' })).toHaveCount(0)

    await page.goto('/admin/accounts')
    await expect(h1(page, 'Not permitted')).toBeVisible()
    await expect(pinned(page)).toHaveCount(0)

    // The account menu is theirs regardless.
    const menu = await openAccountMenu(page)
    await expect(menu.getByRole('menuitem', { name: 'Account security' })).toBeVisible()
  })
})

test.describe('heading focus after navigation', () => {
  test('lands on the new h1 from the rail', async ({ browser, baseURL }) => {
    const page = await admin(browser, baseURL)
    await page.goto('/account/security')
    await consoleNav(page).getByRole('link', { name: 'Overview' }).click()
    await expect(h1(page, 'Overview')).toBeFocused()
  })
})

test.describe('under the production policy', () => {
  test('the shell, its image and its dialogs raise no CSP violation', async ({ browser }) => {
    const page = await signedInAs(browser, PROD, 'admin-read')
    await page.addInitScript(() => {
      const g = globalThis as unknown as {
        __cspViolations: string[]
        addEventListener: (type: string, listener: (event: unknown) => void) => void
      }
      g.__cspViolations = []
      g.addEventListener('securitypolicyviolation', (event: unknown) => {
        const e = event as { violatedDirective?: string; blockedURI?: string }
        g.__cspViolations.push(`${e.violatedDirective ?? '?'} ${e.blockedURI ?? '?'}`)
      })
    })
    const refused: string[] = []
    page.on('console', (message) => {
      if (/Content Security Policy|Refused to/i.test(message.text())) refused.push(message.text())
    })

    // Wide: rail, badge, pinned drawer, account menu with a theme change.
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto(`${PROD}/admin/accounts`)
    await expect(h1(page, 'Accounts')).toBeVisible()
    await expect(pinned(page)).toBeVisible()
    const badge = consoleNav(page).getByRole('img', { name: 'Flow Life Commons' })
    await expect(badge).toBeVisible()
    expect(
      await badge.evaluate((image) => (image as unknown as { naturalWidth: number }).naturalWidth),
    ).toBeGreaterThan(0) // it actually loaded: same origin, hashed, under img-src 'self'
    const menu = await openAccountMenu(page)
    await menu.getByRole('menuitemradio', { name: 'Dark' }).click()
    expect(await themeOf(page)).toBe('dark')

    // Overlay drawer, and the mobile sheet with its dialog animation.
    await page.setViewportSize({ width: 1100, height: 800 })
    await pinned(page).waitFor({ state: 'detached' })
    await railAdmin(page).click()
    await expect(overlay(page)).toBeVisible()
    await page.keyboard.press('Escape')
    await page.setViewportSize({ width: 375, height: 800 })
    await sheetButton(page).click()
    await expect(page.getByRole('dialog', { name: 'Navigation' })).toBeVisible()
    await page.keyboard.press('Escape')

    const violations = await page.evaluate(
      () => (globalThis as unknown as Dom).__cspViolations ?? [],
    )
    expect(violations).toEqual([])
    expect(refused).toEqual([])
  })
})
