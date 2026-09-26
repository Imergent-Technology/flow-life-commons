import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { Badge } from './Badge.tsx'
import { StatusBadge } from './StatusBadge.tsx'
import { MembershipStateBadge } from '../pages/admin/MembershipStateBadge.tsx'

describe('Badge', () => {
  it.each([
    ['success', 'dot'],
    ['warning', 'diamond'],
    ['neutral', 'ring'],
    ['danger', 'dot'],
  ] as const)('%s keeps its word and carries a %s as well as a colour', (variant, shape) => {
    const { container } = render(<Badge variant={variant}>Some status</Badge>)
    expect(screen.getByText('Some status')).toBeVisible()
    const marker = container.querySelector('[data-indicator]')
    expect(marker).toHaveAttribute('data-indicator', shape)
    expect(marker).toHaveAttribute('aria-hidden', 'true')
  })

  it('reads as its words alone to assistive technology', () => {
    render(<Badge variant="warning">Invited</Badge>)
    expect(screen.getByText('Invited').textContent).toBe('Invited')
  })

  it('is a plain label, with no shape, when it is not a status', () => {
    const { container } = render(<Badge variant="accent">Guardian</Badge>)
    expect(container.querySelector('[data-indicator]')).toBeNull()
  })

  it('lets a caller pick the shape', () => {
    const { container } = render(
      <Badge variant="neutral" indicator="diamond">
        Waiting
      </Badge>,
    )
    expect(container.querySelector('[data-indicator]')).toHaveAttribute('data-indicator', 'diamond')
  })
})

describe('the status badges over Badge', () => {
  it.each([
    ['invited', 'Invited', 'diamond'],
    ['active', 'Active', 'dot'],
    ['disabled', 'Disabled', 'ring'],
  ] as const)('shows an account that is %s', (status, label, shape) => {
    const { container } = render(<StatusBadge status={status} />)
    expect(screen.getByText(label)).toBeVisible()
    expect(container.querySelector('[data-indicator]')).toHaveAttribute('data-indicator', shape)
  })

  it('shows a membership as Active with a dot, and Inactive with a ring', () => {
    const { container, rerender } = render(<MembershipStateBadge active />)
    expect(screen.getByText('Active')).toBeVisible()
    expect(container.querySelector('[data-indicator]')).toHaveAttribute('data-indicator', 'dot')
    rerender(<MembershipStateBadge active={false} />)
    expect(screen.getByText('Inactive')).toBeVisible()
    expect(container.querySelector('[data-indicator]')).toHaveAttribute('data-indicator', 'ring')
  })
})
