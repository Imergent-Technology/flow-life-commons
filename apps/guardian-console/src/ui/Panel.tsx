import { cva } from 'class-variance-authority'
import { useId, type ReactNode } from 'react'

import { cn } from './cn.ts'

const panel = cva('rounded-lg border shadow-panel', {
  variants: {
    tone: {
      default: 'border-border bg-surface',
      danger: 'border-danger/40 bg-danger-soft',
    },
  },
  defaultVariants: { tone: 'default' },
})

/**
 * A bordered section of a page: optional title, description and actions above the body. A titled panel is a
 * labelled `section`, so it is a landmark-free grouping a screen reader can name. The `danger` tone tints the
 * border and ground of a destructive section; the buttons inside it stay ordinary `danger` outlines rather
 * than turning solid red.
 */
export function Panel({
  title,
  description,
  actions,
  tone,
  headingLevel = 2,
  className,
  children,
}: {
  title?: ReactNode
  description?: ReactNode
  actions?: ReactNode
  tone?: 'default' | 'danger'
  headingLevel?: 2 | 3
  className?: string
  children?: ReactNode
}) {
  const titleId = useId()
  const Heading = headingLevel === 2 ? 'h2' : 'h3'
  const hasHeader = title !== undefined || description !== undefined || actions !== undefined

  return (
    <section
      aria-labelledby={title === undefined ? undefined : titleId}
      data-tone={tone ?? 'default'}
      className={cn(panel({ tone }), className)}
    >
      {hasHeader ? (
        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-[18px] pt-4">
          <div className="flex min-w-0 flex-col gap-0.5">
            {title === undefined ? null : (
              <Heading id={titleId} className="text-section font-semibold text-foreground">
                {title}
              </Heading>
            )}
            {description === undefined ? null : (
              <p className="text-body text-muted-foreground">{description}</p>
            )}
          </div>
          {actions === undefined ? null : (
            <div className="flex flex-wrap items-center gap-2">{actions}</div>
          )}
        </div>
      ) : null}
      {children === undefined ? null : (
        <div className={cn('px-[18px] pb-4', hasHeader ? 'pt-3' : 'pt-4')}>{children}</div>
      )}
    </section>
  )
}
