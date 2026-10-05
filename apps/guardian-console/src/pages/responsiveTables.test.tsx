import { screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ADMIN_CAPABILITIES, page, serveOperator, wire } from '../test/admin.ts'
import { accountFor, json } from '../test/fakeApi.ts'
import { membersPage, wireMember } from '../test/membership.ts'
import { renderApp } from '../test/renderApp.tsx'
import { categoryList, packsPage, wireCategory, wireListedPack } from '../test/resources.ts'

// Below 768px a table becomes stacked records, and CSS reads each value's name from its `data-label`. jsdom
// cannot lay anything out, so what is provable here is the contract that makes the stacked layout honest:
// every value carries the name of the column it sits in, and the layout switches are on the elements.

afterEach(() => {
  vi.unstubAllGlobals()
})

function serve() {
  const api = serveOperator(
    accountFor({
      capabilities: [...ADMIN_CAPABILITIES, 'membership.records.view', 'resources.manage'],
    }),
  )
  api.on('GET /api/v1/admin/accounts', json(page([wire(), wire({ id: 'B', display_name: 'Bo' })])))
  api.on('GET /api/v1/admin/members', json(membersPage([wireMember()])))
  api.on('GET /api/v1/admin/resources/categories', json(categoryList([wireCategory()])))
  api.on(
    'GET /api/v1/admin/resources/packs',
    json(packsPage([wireListedPack(), wireListedPack({ id: 'B', title: 'Second pack' })])),
  )
}

async function labelsAgainstHeaders(name: string) {
  const table = await screen.findByRole('table', { name })
  const headers = within(table)
    .getAllByRole('columnheader')
    .map((header) => header.textContent)
  const rows = within(table)
    .getAllByRole('row')
    .filter((row) => within(row).queryAllByRole('columnheader').length === 0)
  expect(rows.length).toBeGreaterThan(0)
  return { table, headers, rows }
}

describe.each([
  { path: '/admin/accounts', caption: 'Accounts', columns: 5 },
  { path: '/admin/members', caption: 'Members', columns: 3 },
  { path: '/resources', caption: 'Resource Packs', columns: 6 },
])('the $caption table on a narrow screen', ({ path, caption, columns }) => {
  it('gives every value the name of its column, so a stacked record never shows a bare value', async () => {
    serve()
    renderApp(path)
    const { headers, rows } = await labelsAgainstHeaders(caption)

    expect(headers).toHaveLength(columns)
    for (const row of rows) {
      const cells = within(row).getAllByRole('cell')
      expect(cells).toHaveLength(columns - 1) // the name is the row header: the record's title
      cells.forEach((cell, index) => {
        expect(cell).toHaveAttribute('data-label', headers[index + 1])
      })
    }
  })

  it('stacks below 768px and is a table from there up', async () => {
    serve()
    renderApp(path)
    const { table, rows } = await labelsAgainstHeaders(caption)

    expect(table).toHaveClass('max-md:block')
    expect(table.querySelector('thead')).toHaveClass('max-md:sr-only') // headers stay for assistive technology
    expect(table.querySelector('tbody')).toHaveClass('max-md:flex')
    for (const row of rows) expect(row).toHaveClass('max-md:block')
    // From 768px the header is pinned to the top of the window, and the table never scrolls sideways.
    for (const header of within(table).getAllByRole('columnheader')) {
      expect(header).toHaveClass('md:sticky', 'md:top-0')
    }
    expect(table.closest('div')).toHaveClass('md:overflow-clip')
    expect(table.closest('div')).not.toHaveClass('overflow-x-auto')
  })
})
