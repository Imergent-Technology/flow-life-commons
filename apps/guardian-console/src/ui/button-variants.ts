import { cva, type VariantProps } from 'class-variance-authority'

import { cn, focusRing } from './cn.ts'

/**
 * Split from Button.tsx so a link can wear the same look (`<Link className={buttonVariants(...)}>`)
 * without a component file exporting a non-component.
 *
 * `danger` is the contextual destructive treatment: an outline, so a danger zone is not a wall of red.
 * `danger-solid` is reserved for the final confirming action inside a dialog.
 */
export const buttonVariants = cva(
  cn(
    'inline-flex shrink-0 items-center justify-center gap-2 rounded-sm font-medium whitespace-nowrap',
    'transition-colors duration-(--duration-fast) ease-out',
    'disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-50',
    focusRing,
  ),
  {
    variants: {
      variant: {
        primary: 'bg-primary text-primary-foreground hover:bg-primary-hover',
        secondary: 'border border-border-strong bg-surface text-foreground hover:bg-muted',
        ghost: 'text-foreground hover:bg-muted',
        danger: 'border border-danger/40 bg-transparent text-danger hover:bg-danger-soft',
        'danger-solid': 'bg-danger text-danger-foreground hover:bg-danger/90',
      },
      size: {
        sm: 'h-(--control-sm) px-2.5 text-meta',
        md: 'h-(--control-md) px-3.5 text-label',
        lg: 'h-(--control-lg) px-5 text-body',
      },
    },
    defaultVariants: { variant: 'secondary', size: 'md' },
  },
)

export type ButtonVariants = VariantProps<typeof buttonVariants>
