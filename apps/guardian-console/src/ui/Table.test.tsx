import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import {
  DataTable,
  DataTableCell,
  DataTableHead,
  DataTableHeaderCell,
  DataTableRow,
  DataTableRowHeader,
} from './DataTable.tsx'
import { Pagination } from './Pagination.tsx'

function Sample({ email = 'ada@example.org' }: { email?: string }) {
  return (
    <DataTable caption="Members" className="min-w-[32rem]">
      <DataTableHead>
        <tr>
          <DataTableHeaderCell>Name</DataTableHeaderCell>
          <DataTableHeaderCell>Email</DataTableHeaderCell>
        </tr>
      </DataTableHead>
      <tbody>
        <DataTableRow>
          <DataTableRowHeader>
            <a href="/m/1">Ada</a>
          </DataTableRowHeader>
          <DataTableCell truncate>{email}</DataTableCell>
        </DataTableRow>
      </tbody>
    </DataTable>
  )
}

describe('DataTable', () => {
  it('is a native table, named by its caption, with column and row headers', () => {
    render(<Sample />)
    const table = screen.getByRole('table', { name: 'Members' })
    expect(table.tagName).toBe('TABLE')
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((h) => h.textContent),
    ).toEqual(['Name', 'Email'])
    const rowHeader = within(table).getByRole('rowheader', { name: 'Ada' })
    expect(rowHeader).toHaveAttribute('scope', 'row')
    expect(within(table).getAllByRole('columnheader')[0]).toHaveAttribute('scope', 'col')
    expect(within(table).getByRole('cell', { name: 'ada@example.org' })).toBeVisible()
  })

  it('takes plain table props, so it is a table and nothing more', () => {
    render(<Sample />)
    expect(screen.getByRole('table')).toHaveClass('min-w-[32rem]')
    expect(screen.getByRole('table').closest('div')).toHaveClass('overflow-x-auto')
  })

  it('truncates on request and keeps the full text as a title', () => {
    render(<Sample email="a.very.long.address@a-very-long-domain.example.org" />)
    const cell = screen.getByRole('cell')
    expect(cell).toHaveClass('truncate')
    expect(cell).toHaveAttribute('title', 'a.very.long.address@a-very-long-domain.example.org')
  })

  it('does not truncate an ordinary cell', () => {
    render(
      <DataTable caption="T">
        <tbody>
          <DataTableRow>
            <DataTableCell>Everything shown</DataTableCell>
          </DataTableRow>
        </tbody>
      </DataTable>,
    )
    expect(screen.getByRole('cell')).not.toHaveClass('truncate')
    expect(screen.getByRole('cell')).not.toHaveAttribute('title')
  })
})

describe('Pagination', () => {
  it('disables Previous on the first page and Next on the last', () => {
    const { rerender } = render(<Pagination page={1} lastPage={3} onPageChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled()

    rerender(<Pagination page={3} lastPage={3} onPageChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'Previous' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
  })

  it('does not request a page from a disabled button', async () => {
    const user = userEvent.setup()
    const onPageChange = vi.fn()
    render(<Pagination page={1} lastPage={1} onPageChange={onPageChange} />)
    await user.click(screen.getByRole('button', { name: 'Previous' }))
    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(onPageChange).not.toHaveBeenCalled()
  })

  it('reports the neighbouring page', async () => {
    const user = userEvent.setup()
    const onPageChange = vi.fn()
    render(<Pagination page={2} lastPage={3} onPageChange={onPageChange} />)
    await user.click(screen.getByRole('button', { name: 'Previous' }))
    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(onPageChange.mock.calls).toEqual([[1], [3]])
  })

  it('is a labelled navigation region whose position is announced', () => {
    render(<Pagination page={2} lastPage={5} onPageChange={vi.fn()} />)
    expect(screen.getByRole('navigation', { name: 'Pages' })).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent('Page 2 of 5')
  })

  it('shows the count, with the right noun, only when the caller has one', () => {
    const { rerender } = render(
      <Pagination
        page={1}
        lastPage={5}
        total={120}
        noun={{ one: 'member', other: 'members' }}
        onPageChange={vi.fn()}
      />,
    )
    expect(screen.getByRole('status')).toHaveTextContent('Page 1 of 5 (120 members)')
    rerender(
      <Pagination
        page={1}
        lastPage={1}
        total={1}
        noun={{ one: 'member', other: 'members' }}
        onPageChange={vi.fn()}
      />,
    )
    expect(screen.getByRole('status')).toHaveTextContent('Page 1 of 1 (1 member)')
  })
})
