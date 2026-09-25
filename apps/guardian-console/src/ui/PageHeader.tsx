import type { ReactNode } from 'react'

import { usePageHeading } from './usePageHeading.ts'

/**
 * The top of a page: the h1 (in the display face), an optional status beside it, an optional one-line
 * description, and the page's one primary action on the right. The h1 is the page's only one, names the
 * document and takes focus on arrival, exactly as `PageHeading` does.
 */
export function PageHeader({
  title,
  status,
  description,
  action,
}: {
  title: string
  status?: ReactNode
  description?: ReactNode
  action?: ReactNode
}) {
  const ref = usePageHeading(title)

  return (
    <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
      <div className="flex min-w-0 flex-col gap-1.5">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <h1
            ref={ref}
            tabIndex={-1}
            className="font-display text-title font-medium text-foreground outline-none max-md:text-[1.6875rem]"
          >
            {title}
          </h1>
          {status === undefined ? null : status}
        </div>
        {description === undefined ? null : (
          <p className="text-body text-muted-foreground">{description}</p>
        )}
      </div>
      {action === undefined ? null : (
        <div className="flex flex-wrap items-center gap-2">{action}</div>
      )}
    </div>
  )
}
