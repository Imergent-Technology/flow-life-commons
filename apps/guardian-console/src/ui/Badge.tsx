import { cva, type VariantProps } from 'class-variance-authority'
import type { ComponentProps } from 'react'

import { cn } from './cn.ts'

const badge = cva(
  'inline-flex items-center gap-1.5 rounded-pill border px-2 py-0.5 text-meta font-medium whitespace-nowrap',
  {
    variants: {
      variant: {
        success: 'border-success/30 bg-success-soft text-success',
        warning: 'border-warning/30 bg-warning-soft text-warning',
        neutral: 'border-border-strong bg-neutral-soft text-neutral-foreground',
        danger: 'border-danger/30 bg-danger-soft text-danger',
        accent: 'border-accent-foreground/20 bg-accent text-accent-foreground',
      },
    },
    defaultVariants: { variant: 'neutral' },
  },
)

/** filled dot = active / live · diamond = waiting / pending · hollow ring = inactive / off. */
export type BadgeIndicator = 'dot' | 'diamond' | 'ring' | 'none'

const defaultIndicator: Record<
  NonNullable<VariantProps<typeof badge>['variant']>,
  BadgeIndicator
> = { success: 'dot', warning: 'diamond', neutral: 'ring', danger: 'dot', accent: 'none' }

const indicatorShape: Record<Exclude<BadgeIndicator, 'none'>, string> = {
  dot: 'size-2 rounded-pill bg-current',
  diamond: 'size-1.5 rotate-45 bg-current',
  ring: 'size-2 rounded-pill border-2 border-current',
}

/**
 * A status in words, with a small shape so the meaning survives without colour. The text is the primary
 * meaning; the shape is decorative and hidden from assistive technology. Pass `indicator="none"` for a
 * badge that is a label rather than a status. Which words to show is the caller's business: Badge invents
 * no statuses.
 */
export function Badge({
  variant,
  indicator,
  className,
  children,
  ...props
}: ComponentProps<'span'> &
  VariantProps<typeof badge> & {
    indicator?: BadgeIndicator
  }) {
  const shape = indicator ?? defaultIndicator[variant ?? 'neutral']
  return (
    <span {...props} className={cn(badge({ variant }), className)}>
      {shape === 'none' ? null : (
        <span aria-hidden="true" data-indicator={shape} className={indicatorShape[shape]} />
      )}
      {children}
    </span>
  )
}
