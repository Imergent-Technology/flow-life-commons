import type { ReactNode } from 'react'

/**
 * Says what is empty, optionally why, and offers at most one next step. The message is a polite `status`,
 * as the bare "No accounts match." sentences it replaces were, so a change from results to nothing is
 * announced. The action sits outside the status region: it is a control, not an announcement.
 */
export function EmptyState({
  title,
  action,
  children,
}: {
  title: string
  action?: ReactNode
  children?: ReactNode
}) {
  return (
    <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed border-border-strong px-[18px] py-6">
      <div role="status" className="flex flex-col gap-1">
        <p className="text-section font-semibold text-foreground">{title}</p>
        {children === undefined ? null : (
          <p className="text-body text-muted-foreground">{children}</p>
        )}
      </div>
      {action === undefined ? null : action}
    </div>
  )
}
