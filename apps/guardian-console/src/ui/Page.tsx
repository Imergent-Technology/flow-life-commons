import { cva, type VariantProps } from 'class-variance-authority'
import type { ReactNode } from 'react'

import { cn } from './cn.ts'

const page = cva('flex w-full flex-col gap-6', {
  variants: {
    width: {
      prose: 'max-w-page-prose',
      form: 'max-w-page-form',
      detail: 'max-w-page-detail',
      wide: 'max-w-none',
    },
  },
})

export type PageWidth = NonNullable<VariantProps<typeof page>['width']>

/**
 * A page's content column. The width follows the task (prose to read, a form to fill, a detail view, an
 * operational list) and the column stays left-aligned beside the navigation rather than being centred. The
 * first three set a maximum: on a narrow screen the page is simply as wide as the screen. `wide` sets none: a
 * list takes the whole width the shell leaves it, inside the shell's own gutters.
 */
export function Page({
  width,
  className,
  children,
}: {
  width: PageWidth
  className?: string
  children: ReactNode
}) {
  return (
    <div data-page-width={width} className={cn(page({ width }), className)}>
      {children}
    </div>
  )
}

/**
 * The detail page's grid: the main column, and a 20rem aside beside it once the page itself has room (a
 * container query, so it follows the width the shell leaves the page rather than the window's). Below that
 * the aside stacks under the main column. The aside comes first in the source so that it is read first when
 * stacked, and is placed last visually on a wide layout.
 */
export function DetailLayout({ aside, children }: { aside: ReactNode; children: ReactNode }) {
  return (
    <div className="@container">
      <div className="grid gap-6 @4xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="flex min-w-0 flex-col gap-6 @4xl:order-last">{aside}</div>
        <div className="flex min-w-0 flex-col gap-6">{children}</div>
      </div>
    </div>
  )
}
