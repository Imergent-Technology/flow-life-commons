import { cva, type VariantProps } from 'class-variance-authority'
import type { ReactNode } from 'react'

import { cn } from './cn.ts'

const page = cva('flex w-full flex-col gap-6', {
  variants: {
    width: {
      prose: 'max-w-page-prose',
      form: 'max-w-page-form',
      detail: 'max-w-page-detail',
      wide: 'max-w-page-wide',
    },
  },
})

export type PageWidth = NonNullable<VariantProps<typeof page>['width']>

/**
 * A page's content column. The width follows the task (prose to read, a form to fill, a detail view, a wide
 * list) and the column stays left-aligned beside the navigation rather than being centred. It sets a
 * maximum only: on a narrow screen the page is simply as wide as the screen.
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
  return <div className={cn(page({ width }), className)}>{children}</div>
}
