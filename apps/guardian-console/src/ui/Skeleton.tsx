import type { ReactNode } from 'react'

import { cn } from './cn.ts'

/**
 * A quiet placeholder block shaped like the content it stands in for. It pulses only where the person has
 * not asked for reduced motion; otherwise it is a still block. It is decorative and hidden from assistive
 * technology: put it inside a `SkeletonRegion`, which carries the loading announcement.
 */
export function Skeleton({ className }: { className?: string }) {
  return (
    <div
      aria-hidden="true"
      className={cn('h-4 rounded-sm bg-muted motion-safe:animate-pulse', className)}
    />
  )
}

/** Lines of text, the last one shorter as a paragraph's is. */
export function SkeletonText({ lines = 3 }: { lines?: number }) {
  return (
    <div aria-hidden="true" className="flex flex-col gap-2">
      {Array.from({ length: lines }, (_, index) => (
        <Skeleton key={index} className={index === lines - 1 && lines > 1 ? 'w-2/3' : 'w-full'} />
      ))}
    </div>
  )
}

/**
 * Names what is loading. The label is the accessible loading state (a polite `status`); the skeleton inside
 * is only the picture of it. Pass `visibleLabel` to show the words too, as the pages' "Loading members…"
 * lines do today.
 */
export function SkeletonRegion({
  label,
  visibleLabel = false,
  children,
}: {
  label: string
  visibleLabel?: boolean
  children: ReactNode
}) {
  return (
    <div role="status" aria-busy="true" className="flex flex-col gap-3">
      <span className={visibleLabel ? 'text-body text-muted-foreground' : 'sr-only'}>{label}</span>
      {children}
    </div>
  )
}

/**
 * A list's worth of rows, on the surface a table sits on, for `SkeletonRegion` while a page of records
 * loads: the picture of what is about to appear, so the page does not jump when it does.
 */
export function SkeletonRows({ rows = 5 }: { rows?: number }) {
  return (
    <div
      aria-hidden="true"
      className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 shadow-panel"
    >
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="flex items-center gap-4">
          <Skeleton className="h-5 w-1/4" />
          <Skeleton className="h-5 w-2/5" />
          <Skeleton className="h-5 w-16" />
        </div>
      ))}
    </div>
  )
}
