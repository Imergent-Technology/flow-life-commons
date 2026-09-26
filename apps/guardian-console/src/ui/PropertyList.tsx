import type { ReactNode } from 'react'

import { cn } from './cn.ts'

/**
 * A list of facts about one thing, as a native description list: a compact term column beside the values once the
 * list itself is wide enough for one (a container query, so a 20rem aside stacks each term over its value while a
 * full-width panel keeps them side by side).
 */
export function PropertyList({ children, className }: { children: ReactNode; className?: string }) {
  return <dl className={cn('@container flex flex-col gap-y-2 text-body', className)}>{children}</dl>
}

/**
 * One term and its value. The pair sits in a `div`, which a description list allows, so each row can lay
 * itself out. Values wrap anywhere they must (an email address, a long name) instead of breaking
 * mid-word by default or overflowing. `mono` is for identifiers.
 */
export function Property({
  term,
  mono = false,
  children,
}: {
  term: ReactNode
  mono?: boolean
  children: ReactNode
}) {
  return (
    <div className="grid grid-cols-1 gap-x-4 gap-y-0.5 @[20rem]:grid-cols-[8.125rem_minmax(0,1fr)]">
      <dt className="text-muted-foreground">{term}</dt>
      <dd className={cn('wrap-anywhere text-foreground', mono && 'font-mono text-meta')}>
        {children}
      </dd>
    </div>
  )
}
