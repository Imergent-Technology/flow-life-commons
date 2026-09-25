import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { Alert } from './Alert.tsx'
import { EmptyState } from './EmptyState.tsx'
import { Panel } from './Panel.tsx'
import { Property, PropertyList } from './PropertyList.tsx'
import { Skeleton, SkeletonRegion, SkeletonText } from './Skeleton.tsx'

describe('Panel', () => {
  it('is a section named by its title, with description, actions and body', () => {
    render(
      <Panel title="Access" description="Who can do what" actions={<button>Edit</button>}>
        Body text
      </Panel>,
    )
    const section = screen.getByRole('region', { name: 'Access' })
    expect(within(section).getByRole('heading', { level: 2, name: 'Access' })).toBeVisible()
    expect(within(section).getByText('Who can do what')).toBeVisible()
    expect(within(section).getByRole('button', { name: 'Edit' })).toBeVisible()
    expect(within(section).getByText('Body text')).toBeVisible()
    expect(section).toHaveAttribute('data-tone', 'default')
    expect(section).toHaveClass('border-border')
  })

  it('has a danger tone that tints the border and ground', () => {
    render(
      <Panel title="Danger zone" tone="danger">
        <button>Disable account</button>
      </Panel>,
    )
    const section = screen.getByRole('region', { name: 'Danger zone' })
    expect(section).toHaveAttribute('data-tone', 'danger')
    expect(section).toHaveClass('border-danger/40')
    expect(section).toHaveClass('bg-danger-soft')
    expect(section).not.toHaveClass('border-border')
  })

  it('can sit at h3 inside a page section', () => {
    render(<Panel title="Nested" headingLevel={3} />)
    expect(screen.getByRole('heading', { level: 3, name: 'Nested' })).toBeVisible()
  })

  it('is an anonymous group, not a landmark, without a title', () => {
    render(<Panel>Just content</Panel>)
    expect(screen.queryByRole('region')).toBeNull()
    expect(screen.getByText('Just content')).toBeVisible()
  })
})

describe('Alert', () => {
  it('announces an error as an alert', () => {
    render(<Alert tone="error">It failed.</Alert>)
    expect(screen.getByRole('alert')).toHaveTextContent('It failed.')
  })

  it.each(['success', 'warning', 'info'] as const)('announces %s politely, as a status', (tone) => {
    render(<Alert tone={tone}>Message</Alert>)
    expect(screen.getByRole('status')).toHaveTextContent('Message')
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('does not take focus unless asked', () => {
    render(<Alert tone="error">It failed.</Alert>)
    expect(screen.getByRole('alert')).not.toHaveFocus()
  })

  it('takes focus on mount with focusOnMount, and again for a fresh one', () => {
    const { rerender } = render(
      <Alert key={1} tone="error" focusOnMount>
        First
      </Alert>,
    )
    expect(screen.getByRole('alert')).toHaveFocus()
    ;(document.activeElement as HTMLElement).blur()
    rerender(
      <Alert key={2} tone="error" focusOnMount>
        Second
      </Alert>,
    )
    expect(screen.getByRole('alert')).toHaveFocus()
  })

  it('has an icon as well as a colour, hidden from assistive technology', () => {
    const { container } = render(<Alert tone="warning">Careful.</Alert>)
    expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true')
    expect(screen.getByRole('status').textContent).toBe('Careful.')
  })
})

describe('PropertyList', () => {
  it('is a description list of terms and values', () => {
    const { container } = render(
      <PropertyList>
        <Property term="Email">a@b.example</Property>
        <Property term="Account id" mono>
          01J0ABCDEF
        </Property>
      </PropertyList>,
    )
    const list = container.querySelector('dl')
    expect(list).not.toBeNull()
    expect(container.querySelectorAll('dl > div > dt')).toHaveLength(2)
    expect(container.querySelectorAll('dl > div > dd')).toHaveLength(2)
    expect(screen.getByText('Email').tagName).toBe('DT')
    expect(screen.getByText('a@b.example').tagName).toBe('DD')
  })

  it('wraps values safely, with the mono role only where asked, and never break-all', () => {
    render(
      <PropertyList>
        <Property term="Email">a@b.example</Property>
        <Property term="Id" mono>
          01J0ABCDEF
        </Property>
      </PropertyList>,
    )
    const email = screen.getByText('a@b.example')
    const id = screen.getByText('01J0ABCDEF')
    expect(email).toHaveClass('wrap-anywhere')
    expect(email).not.toHaveClass('break-all')
    expect(email).not.toHaveClass('font-mono')
    expect(id).toHaveClass('font-mono')
  })
})

describe('EmptyState', () => {
  it('names what is empty, as a polite status, with an optional explanation', () => {
    render(<EmptyState title="No members yet">Add someone to get started.</EmptyState>)
    const status = screen.getByRole('status')
    expect(status).toHaveTextContent('No members yet')
    expect(status).toHaveTextContent('Add someone to get started.')
  })

  it('offers one next action outside the status region', () => {
    render(<EmptyState title="No members yet" action={<a href="/new">Add a member</a>} />)
    expect(screen.getByRole('link', { name: 'Add a member' })).toBeVisible()
    expect(within(screen.getByRole('status')).queryByRole('link')).toBeNull()
  })
})

describe('Skeleton', () => {
  it('is decorative: hidden from assistive technology', () => {
    const { container } = render(<Skeleton className="h-8 w-8" />)
    expect(container.firstElementChild).toHaveAttribute('aria-hidden', 'true')
  })

  it('pulses only where motion is welcome', () => {
    const { container } = render(<Skeleton />)
    expect(container.firstElementChild).toHaveClass('motion-safe:animate-pulse')
    expect(container.firstElementChild).not.toHaveClass('animate-pulse')
  })

  it('draws lines of text, the last one shorter', () => {
    const { container } = render(<SkeletonText lines={3} />)
    const lines = container.querySelectorAll('[aria-hidden="true"] > div')
    expect(lines).toHaveLength(3)
    expect(lines[2]).toHaveClass('w-2/3')
  })

  it('has an accessible loading state: a status that names what is loading', () => {
    render(
      <SkeletonRegion label="Loading members…">
        <SkeletonText />
      </SkeletonRegion>,
    )
    const status = screen.getByRole('status')
    expect(status).toHaveTextContent('Loading members…')
    expect(status).toHaveAttribute('aria-busy', 'true')
  })
})
