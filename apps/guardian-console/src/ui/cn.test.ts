import { describe, expect, it } from 'vitest'

import { cn } from './cn.ts'

describe('cn', () => {
  it('joins conditional classes', () => {
    const enabled = Math.random() > 2
    expect(cn('a', enabled && 'b', undefined, 'c')).toBe('a c')
  })

  it('lets a later utility override an earlier one', () => {
    expect(cn('px-3', 'px-4')).toBe('px-4')
  })

  // These three are wrong in stock tailwind-merge, which is why cn() registers the project's scales.
  it('keeps a type-role size alongside a colour, and replaces one size with another', () => {
    expect(cn('text-meta', 'text-foreground')).toBe('text-meta text-foreground')
    expect(cn('text-body', 'text-meta')).toBe('text-meta')
  })

  it('understands the custom radius, shadow and page-width scales', () => {
    expect(cn('rounded-sm', 'rounded-pill')).toBe('rounded-pill')
    expect(cn('shadow-panel', 'shadow-pop')).toBe('shadow-pop')
    expect(cn('max-w-page-form', 'max-w-page-detail')).toBe('max-w-page-detail')
  })

  it('still resolves colour conflicts between semantic tokens', () => {
    expect(cn('text-foreground', 'text-danger')).toBe('text-danger')
    expect(cn('bg-surface', 'bg-muted')).toBe('bg-muted')
  })
})
