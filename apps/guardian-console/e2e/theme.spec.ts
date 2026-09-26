import { expect, test, type Page } from '@playwright/test'

import { expectOnlyUiPreferences } from './support.ts'

/**
 * The theme infrastructure (ADR 0030), in a real browser under the production policy.
 *
 * The account menu's own theme choice is exercised in shell.spec.ts; these journeys instead
 * seed the one approved preference key directly via `addInitScript`. That is Playwright's own
 * instrumentation: it runs before any page script and is not subject to the page's CSP, exactly like
 * the violation reporter `security.spec.ts` installs the same way. No UI is added just for the
 * test, and the production-equivalent origin (`prod.flowlife.localhost`) is used throughout, so
 * these journeys prove the real build under the real policy rather than the weaker development one.
 */

const PORT = new URL(process.env.E2E_BASE_URL ?? 'http://commons.flowlife.localhost:18080').port
const PROD = `http://prod.flowlife.localhost:${PORT}`
const STORAGE_KEY = 'flowlife.console.ui'

/**
 * The e2e project compiles without the DOM library (tsconfig.node.json), so browser-side code is typed
 * through this shape, as the rest of the suite is through its own casts.
 */
interface Dom {
  document: {
    documentElement: {
      dataset: { theme?: string }
      getAttribute: (name: string) => string | null
    }
  }
  localStorage: { setItem: (key: string, value: string) => void }
  MutationObserver: new (callback: () => void) => {
    observe: (
      target: unknown,
      options: { attributes: boolean; attributeFilter: string[]; subtree: boolean },
    ) => void
  }
  __themeHistory: string[]
}

/** Seeds the one approved preference key before any page script runs. */
async function seedPreference(page: Page, value: object | string): Promise<void> {
  const json = JSON.stringify(value)
  await page.addInitScript(
    ([key, serialized]) => {
      ;(globalThis as unknown as Dom).localStorage.setItem(key, serialized)
    },
    [STORAGE_KEY, json] as const,
  )
}

/**
 * Records every value `data-theme` is ever set to, from before the first page script runs — the
 * strongest available proof that React never paints under the wrong resolved theme and then flips: if
 * it did, this history would show more than one distinct value.
 *
 * Observes `document` itself, with `subtree: true`, rather than `document.documentElement` directly:
 * `dataset.theme = value` (what `applyResolvedTheme` uses) does not call the monkey-patchable
 * `Element.prototype.setAttribute` in this engine — confirmed empirically, by patching it and watching
 * every OTHER attribute React sets arrive while `data-theme` never did — so a MutationObserver is the
 * only mechanism low-level enough to see it. `document` is used as the observed root, rather than
 * `document.documentElement` captured once and held, because that reference is this early and is not
 * guaranteed to be the same object the response body's real `<html>` element becomes; `document`
 * itself never changes identity, and `subtree: true` covers whatever ends up under it.
 */
async function observeThemeAttribute(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const d = globalThis as unknown as Dom
    d.__themeHistory = []
    const observer = new d.MutationObserver(() => {
      d.__themeHistory.push(d.document.documentElement.getAttribute('data-theme') ?? '')
    })
    observer.observe(d.document, {
      attributes: true,
      attributeFilter: ['data-theme'],
      subtree: true,
    })
  })
}

const themeHistory = (page: Page): Promise<string[]> =>
  page.evaluate(() => (globalThis as unknown as Dom).__themeHistory)

const dataTheme = (page: Page): Promise<string | undefined> =>
  page.evaluate(() => (globalThis as unknown as Dom).document.documentElement.dataset.theme)

async function expectSignInPage(page: Page): Promise<void> {
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
}

test.describe('theme infrastructure', () => {
  test('boots in System mode and resolves against the OS preference', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.goto(`${PROD}/login`)
    await expectSignInPage(page)
    expect(await dataTheme(page)).toBe('dark')

    await page.emulateMedia({ colorScheme: 'light' })
    await page.goto(`${PROD}/login`)
    await expectSignInPage(page)
    expect(await dataTheme(page)).toBe('light')
  })

  test('an explicit stored Light preference wins over a dark OS setting', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' })
    await seedPreference(page, { v: 1, theme: 'light' })
    await page.goto(`${PROD}/login`)
    await expectSignInPage(page)
    expect(await dataTheme(page)).toBe('light')
  })

  test('an explicit stored Dark preference wins over a light OS setting', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' })
    await seedPreference(page, { v: 1, theme: 'dark' })
    await page.goto(`${PROD}/login`)
    await expectSignInPage(page)
    expect(await dataTheme(page)).toBe('dark')
  })

  test('the resolved theme is correct from the start: no wrong value is ever observed', async ({
    page,
  }) => {
    await page.emulateMedia({ colorScheme: 'dark' })
    await seedPreference(page, { v: 1, theme: 'light' })
    await observeThemeAttribute(page)
    await page.goto(`${PROD}/login`)
    await expectSignInPage(page)

    // At least one stamp happened (the observer really is wired up), and every one of them is the
    // resolved value: light, from the explicit preference, in spite of the dark OS setting. The Provider's
    // own mount effect may repeat the same value after the pre-render stamp already applied it — that
    // is a redundant write, not a flip — but a wrong value appearing even once, however briefly, fails
    // this: an operator opposite the OS must never see a frame painted under the wrong theme.
    // At least one stamp happened (the observer really is wired up), and every one of them is the
    // resolved value: light, from the explicit preference, in spite of the dark OS setting. The
    // Provider's own mount effect may repeat the same value after the pre-render stamp already applied
    // it — that is a redundant write, not a flip — but a wrong value appearing even once, however
    // briefly, fails this: an operator opposite the OS must never see a frame painted under the wrong
    // theme.
    const history = await themeHistory(page)
    expect(history.length).toBeGreaterThan(0)
    expect(history.every((value) => value === 'light')).toBe(true)
  })

  test('follows a System media-preference change live, with no navigation', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' })
    // No seeded preference: the implicit default is System.
    await page.goto(`${PROD}/login`)
    await expectSignInPage(page)
    expect(await dataTheme(page)).toBe('light')

    await page.emulateMedia({ colorScheme: 'dark' })
    await expect
      .poll(async () => dataTheme(page), { message: 'data-theme should follow the OS live' })
      .toBe('dark')
  })

  test('falls back safely from a malformed stored preference', async ({ page }) => {
    await seedPreference(page, 'not an object, just a bare string')
    await page.goto(`${PROD}/login`)
    // Falls back to a resolved theme and boots normally rather than failing to render at all.
    await expectSignInPage(page)
    expect(await dataTheme(page)).toMatch(/^(light|dark)$/)
  })

  test('boots with no interaction and storage stays inside the approved schema', async ({
    page,
  }) => {
    await page.goto(`${PROD}/login`)
    await expectSignInPage(page)
    await expectOnlyUiPreferences(page)
  })

  test('the storage assertion rejects an extra field even though the key count is still one', async ({
    page,
  }) => {
    // A security assertion, not a count: something writing an identity or session-shaped field into
    // the one approved key must fail this even though storage still holds exactly one key.
    await seedPreference(page, { v: 1, theme: 'light', token: 'not-allowed-here' })
    await page.goto(`${PROD}/login`)
    await expectSignInPage(page)
    await expect(expectOnlyUiPreferences(page)).rejects.toThrow()
  })
})
