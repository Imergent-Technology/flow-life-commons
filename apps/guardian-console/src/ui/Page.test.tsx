import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { Page } from './Page.tsx'
import { PageHeader } from './PageHeader.tsx'

describe('Page', () => {
  it.each([
    ['prose', 'max-w-page-prose'],
    ['form', 'max-w-page-form'],
    ['detail', 'max-w-page-detail'],
  ] as const)('gives the %s width its own maximum, and no other', (width, expected) => {
    const { container } = render(<Page width={width}>Content</Page>)
    const column = container.firstElementChild
    expect(column).toHaveClass(expected)
    const others = ['prose', 'form', 'detail']
      .filter((w) => w !== width)
      .map((w) => `max-w-page-${w}`)
    for (const other of others) expect(column).not.toHaveClass(other)
    expect(column).not.toHaveClass('max-w-none')
  })

  it('gives the wide width no maximum at all: an operational list takes the width the shell leaves it', () => {
    const { container } = render(<Page width="wide">Content</Page>)
    const column = container.firstElementChild
    expect(column).toHaveClass('max-w-none', 'w-full')
    expect(column?.className).not.toMatch(/max-w-page-/)
    expect(column).toHaveAttribute('data-page-width', 'wide')
  })

  it('stays left-aligned: it is never centred', () => {
    const { container } = render(<Page width="wide">Content</Page>)
    expect(container.firstElementChild).not.toHaveClass('mx-auto')
    expect(container.firstElementChild).toHaveClass('w-full')
  })
})

describe('PageHeader', () => {
  it('has one h1, with an optional status, description and primary action', () => {
    render(
      <PageHeader
        title="Members"
        status={<span>Live</span>}
        description="Everyone who has held a membership."
        action={<a href="/new">Add member</a>}
      />,
    )
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getByRole('heading', { level: 1, name: 'Members' })).toHaveClass('font-display')
    expect(screen.getByText('Live')).toBeVisible()
    expect(screen.getByText('Everyone who has held a membership.')).toBeVisible()
    expect(screen.getByRole('link', { name: 'Add member' })).toBeVisible()
  })

  it('renders just the heading when it is given nothing else', () => {
    const { container } = render(<PageHeader title="Overview" />)
    expect(container.querySelectorAll('p')).toHaveLength(0)
    expect(screen.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  })

  it('takes focus on arrival and names the document', () => {
    render(<PageHeader title="Members" />)
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus()
    expect(document.title).toBe('Members · Flow Life Commons')
    expect(document.title).not.toContain('Guardian Console')
  })

  it('moves focus and the title again when the page changes', () => {
    const { rerender } = render(<PageHeader title="Members" />)
    rerender(<PageHeader title="Accounts" />)
    expect(screen.getByRole('heading', { level: 1, name: 'Accounts' })).toHaveFocus()
    expect(document.title).toBe('Accounts · Flow Life Commons')
  })
})
