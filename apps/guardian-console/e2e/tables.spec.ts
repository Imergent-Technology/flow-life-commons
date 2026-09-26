import { expect, test, type Locator, type Page } from '@playwright/test'

import { aMemberId, signedInAs } from './support.ts'

/**
 * The Accounts and Members lists (design spec §8): a real table from 768px, stacked records below it.
 * jsdom cannot lay anything out, so the layout is proved here, in Chromium, with computed styles.
 *
 * Structural checks only: the colours of both themes are audited in accessibility.spec.ts.
 */

const LISTS = [
  {
    path: '/admin/accounts',
    caption: 'Accounts',
    headers: ['Name', 'Email', 'Status', 'Two-step', 'Access'],
  },
  { path: '/admin/members', caption: 'Members', headers: ['Name', 'State', 'Access'] },
] as const

/** Browser-side reads, typed by hand because the e2e project has no DOM library. */
interface Wrapper {
  scrollWidth: number
  clientWidth: number
}
interface Dom {
  document: { documentElement: { scrollWidth: number; clientWidth: number } }
  getComputedStyle: (
    el: unknown,
    pseudo?: string,
  ) => { content: string; display: string; position: string; top: string }
  scrollTo: (x: number, y: number) => void
  scrollY: number
}

async function openList(page: Page, path: string, width: number) {
  await page.setViewportSize({ width, height: 700 })
  await page.goto(path)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await expect(page.locator('[aria-busy="true"]')).toHaveCount(0)
}

for (const list of LISTS) {
  test.describe(`the ${list.caption} list`, () => {
    for (const width of [375, 767]) {
      test(`is stacked, labelled records at ${String(width)}px`, async ({ browser, baseURL }) => {
        const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
        await admin.goto('/')
        await aMemberId(admin)
        await openList(admin, list.path, width)

        const table = admin.getByRole('table', { name: list.caption })
        await expect(table).toBeVisible()
        // The primary data never needs a sideways scroll.
        const overflow = await admin.evaluate(() => {
          const root = (globalThis as unknown as Dom).document.documentElement
          return root.scrollWidth - root.clientWidth
        })
        expect(overflow, 'horizontal overflow of the page').toBeLessThanOrEqual(0)

        // Each row is a block, with its cells stacked and named. The table semantics survive the layout change.
        const rows = table.getByRole('row')
        expect(await rows.count()).toBeGreaterThan(1)
        const firstRow = table.locator('tbody tr').first()
        expect(
          await firstRow.evaluate(
            (el) => (globalThis as unknown as Dom).getComputedStyle(el).display,
          ),
        ).toBe('block')
        const cells = firstRow.getByRole('cell')
        expect(await cells.count()).toBe(list.headers.length - 1)
        for (let index = 0; index < list.headers.length - 1; index += 1) {
          const label = await cells
            .nth(index)
            .evaluate(
              (el) => (globalThis as unknown as Dom).getComputedStyle(el, '::before').content,
            )
          // The label is generated content, straight from the column's own name.
          expect(label).toBe(`"${list.headers[index + 1] ?? ''}"`)
        }
        // The column headers are out of sight but still there for assistive technology.
        const header = table.getByRole('columnheader', { name: list.headers[0] })
        await expect(header).toBeAttached()
        const box = await table.locator('thead').boundingBox()
        expect(box === null || (box.width <= 1 && box.height <= 1)).toBe(true)
        // The action stays reachable: the name is a link.
        await expect(firstRow.getByRole('link').first()).toBeVisible()

        await admin.context().close()
      })
    }

    for (const width of [768, 1024, 1280]) {
      test(`is a table with a pinned header at ${String(width)}px, and never scrolls sideways`, async ({
        browser,
        baseURL,
      }) => {
        const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
        await admin.goto('/')
        await aMemberId(admin)
        await openList(admin, list.path, width)

        const table = admin.getByRole('table', { name: list.caption })
        for (const name of list.headers) {
          await expect(table.getByRole('columnheader', { name })).toBeVisible()
        }
        const firstRow = table.locator('tbody tr').first()
        expect(
          await firstRow.evaluate(
            (el) => (globalThis as unknown as Dom).getComputedStyle(el).display,
          ),
        ).toBe('table-row')
        expect(
          await firstRow
            .getByRole('cell')
            .first()
            .evaluate(
              (el) => (globalThis as unknown as Dom).getComputedStyle(el, '::before').content,
            ),
        ).not.toContain(list.headers[1])

        // Nothing is clipped: the table fits the room the page gives it.
        const clipped = await table.evaluate((el) => {
          const wrapper = (el as unknown as { parentElement: Wrapper }).parentElement
          return wrapper.scrollWidth - wrapper.clientWidth
        })
        expect(clipped, 'columns clipped by the table surface').toBeLessThanOrEqual(0)

        // The header is pinned: scrolled well down the list it is still at the top of the window.
        const header = table.getByRole('columnheader').first()
        const tall = await table.locator('tbody tr').count()
        if (tall >= 12) {
          await admin.evaluate(() => {
            ;(globalThis as unknown as Dom).scrollTo(0, 400)
          })
          await expect
            .poll(async () => (await header.boundingBox())?.y ?? 999)
            .toBeLessThanOrEqual(1)
          await expect(header).toBeInViewport()
        }

        await admin.context().close()
      })
    }
  })
}

test.describe('long values', () => {
  interface Measured {
    lines: number
    overflow: number
    outside: number
  }

  /** How a value sits in its record: the lines its text takes, and whether it overflows or leaves its box. */
  const measure = (cell: Locator): Promise<Measured> =>
    cell.evaluate((el) => {
      const g = globalThis as unknown as {
        document: {
          createRange: () => {
            selectNodeContents: (n: unknown) => void
            getClientRects: () => ArrayLike<{ top: number; right: number }>
          }
        }
      }
      const range = g.document.createRange()
      range.selectNodeContents(el)
      const rects = Array.from(range.getClientRects())
      const box = (
        el as unknown as { getBoundingClientRect: () => { right: number } }
      ).getBoundingClientRect()
      const own = el as unknown as { scrollWidth: number; clientWidth: number }
      return {
        lines: new Set(rects.map((rect) => Math.round(rect.top))).size,
        overflow: own.scrollWidth - own.clientWidth,
        outside: Math.max(0, ...rects.map((rect) => rect.right - box.right)),
      }
    })

  for (const width of [320, 375]) {
    test(`stacked records at ${String(width)}px keep an address that fits on one line, and wrap the rest inside the record`, async ({
      browser,
      baseURL,
    }) => {
      const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
      const person = (id: string, name: string, email: string) => ({
        id: `01J0000000000000000${id}`,
        person_id: `01J000000000000000${id}PR`,
        display_name: name,
        email,
        email_verified_at: null,
        status: 'active',
        created_at: '2026-09-19T09:00:00Z',
        last_login_at: null,
        disabled_at: null,
        mfa: { enrolled: true, recovery_codes_remaining: 8 },
        invitation: null,
        assignments: [],
      })
      await admin.route('**/api/v1/admin/accounts?*', (route) =>
        route.fulfill({
          json: {
            data: [
              // What the development data really has: a 28-character address that used to leave its last letter alone.
              person('TYPICAL001', 'E2E Invited Operator', 'invited.b3d6ac06@example.org'),
              person(
                'LONGMAIL01',
                'Featherstonehaugh Cholmondeley-Wolfeschlegelstein',
                'a.very.long.operator.name.with.many.parts@a-rather-long-subdomain.example.org',
              ),
              // An identifier-shaped local part with nothing to break on.
              person(
                'IDENTIFIER',
                'Wolfeschlegelsteinhausenbergerdorffvoralternwarengewissenhaft',
                'e2e.identifier.0123456789abcdef0123456789abcdef0123456789@example.org',
              ),
            ],
            meta: { page: 1, per_page: 25, total: 3, last_page: 1 },
          },
        }),
      )
      await admin.setViewportSize({ width, height: 800 })
      await admin.goto('/admin/accounts')
      const table = admin.getByRole('table', { name: 'Accounts' })
      await expect(table).toBeVisible()

      const root = await admin.evaluate(() => {
        const r = (globalThis as unknown as Dom).document.documentElement
        return r.scrollWidth - r.clientWidth
      })
      expect(root, 'horizontal overflow of the page').toBeLessThanOrEqual(0)

      const rows = table.locator('tbody tr')
      // An address that fits the record's width takes ONE line: no orphaned last character.
      const typical = await measure(rows.nth(0).locator('td[data-label="Email"]'))
      expect(typical.lines, 'lines for a 28-character address').toBe(1)

      for (const row of [0, 1, 2]) {
        const email = await measure(rows.nth(row).locator('td[data-label="Email"]'))
        expect(email.overflow, `row ${String(row)} address overflows its cell`).toBeLessThanOrEqual(
          0,
        )
        expect(email.outside, `row ${String(row)} address leaves its cell`).toBeLessThanOrEqual(0.5)
        const name = await measure(rows.nth(row).locator('th[scope="row"]'))
        expect(name.overflow, `row ${String(row)} name overflows`).toBeLessThanOrEqual(0)
        expect(name.outside, `row ${String(row)} name leaves its cell`).toBeLessThanOrEqual(0.5)
      }

      // Short fields keep the compact two-column line: label beside value, the record no taller for them.
      const beside = await rows
        .nth(0)
        .locator('td[data-label="Status"]')
        .evaluate((el) => {
          const own = el as unknown as { getBoundingClientRect: () => { height: number } }
          return own.getBoundingClientRect().height
        })
      expect(beside, 'a short field stays a single line').toBeLessThan(34)
      await admin.context().close()
    })
  }

  for (const width of [320, 375, 768, 1024]) {
    test(`a very long name and address neither overflow nor clip at ${String(width)}px`, async ({
      browser,
      baseURL,
    }) => {
      const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
      const unbroken =
        'Wolfeschlegelsteinhausenbergerdorffvoralternwarengewissenhaftschaferswesenchafewarenwohlgepflege'
      await admin.route('**/api/v1/admin/accounts?*', (route) =>
        route.fulfill({
          json: {
            data: [
              {
                id: '01J00000000000000000LONG01',
                person_id: '01J00000000000000000LONGPR',
                display_name: unbroken,
                email: `${unbroken.toLowerCase()}@a-remarkably-long-subdomain.example-organisation.example.org`,
                email_verified_at: null,
                status: 'invited',
                created_at: '2026-09-19T09:00:00Z',
                last_login_at: null,
                disabled_at: null,
                mfa: { enrolled: false, recovery_codes_remaining: 0 },
                invitation: null,
                assignments: [
                  {
                    key: 'a',
                    name: 'A role with quite a long name indeed',
                    description: '',
                    granted_at: '2026-09-19T09:05:00Z',
                  },
                  {
                    key: 'b',
                    name: 'Another role with a long name',
                    description: '',
                    granted_at: '2026-09-19T09:05:00Z',
                  },
                ],
              },
            ],
            meta: { page: 1, per_page: 25, total: 1, last_page: 1 },
          },
        }),
      )
      await admin.setViewportSize({ width, height: 700 })
      await admin.goto('/admin/accounts')
      await expect(admin.getByRole('table', { name: 'Accounts' })).toBeVisible()

      const overflow = await admin.evaluate(() => {
        const root = (globalThis as unknown as Dom).document.documentElement
        return root.scrollWidth - root.clientWidth
      })
      expect(overflow, 'horizontal overflow of the page').toBeLessThanOrEqual(0)
      const clipped = await admin.getByRole('table', { name: 'Accounts' }).evaluate((el) => {
        const wrapper = (el as unknown as { parentElement: Wrapper }).parentElement
        return wrapper.scrollWidth - wrapper.clientWidth
      })
      expect(clipped, 'columns clipped by the table surface').toBeLessThanOrEqual(0)
      await admin.context().close()
    })
  }
})
