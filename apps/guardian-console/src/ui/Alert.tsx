import { cva } from 'class-variance-authority'
import { useEffect, useRef, type ReactNode } from 'react'

import { StatusIcon } from './StatusIcon.tsx'

const alert = cva(
  'flex items-start gap-2.5 rounded-md border px-3 py-2 text-body outline-none focus-visible:ring-2 focus-visible:ring-ring',
  {
    variants: {
      tone: {
        error: 'border-danger/30 bg-danger-soft text-danger',
        success: 'border-success/30 bg-success-soft text-success',
        warning: 'border-warning/30 bg-warning-soft text-warning',
        info: 'border-accent-foreground/20 bg-accent text-accent-foreground',
      },
    },
  },
)

type Tone = 'error' | 'success' | 'warning' | 'info'

/**
 * A message that is announced to assistive technology: an error is an `alert`, anything else a
 * `status`. `focusOnMount` moves keyboard focus to it, for the message that follows a submission (mount
 * a fresh one per attempt, with a `key`, so a repeated failure is announced again).
 *
 * The icon repeats the tone as a shape; the words carry the meaning.
 */
export function Alert({
  tone,
  focusOnMount = false,
  children,
}: {
  tone: Tone
  focusOnMount?: boolean
  children: ReactNode
}) {
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (focusOnMount) ref.current?.focus()
  }, [focusOnMount])

  return (
    <div
      ref={ref}
      tabIndex={-1}
      role={tone === 'error' ? 'alert' : 'status'}
      className={alert({ tone })}
    >
      <StatusIcon kind={tone} className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0 text-foreground">{children}</div>
    </div>
  )
}
