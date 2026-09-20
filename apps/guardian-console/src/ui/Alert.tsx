import { useEffect, useRef, type ReactNode } from 'react'

const tones = {
  error: 'border-red-300 bg-red-50 text-red-900',
  success: 'border-emerald-300 bg-emerald-50 text-emerald-900',
  info: 'border-slate-300 bg-slate-50 text-slate-800',
} as const

/**
 * A message that is announced to assistive technology: an error is an `alert`, anything else a
 * `status`. `focusOnMount` moves keyboard focus to it, for the message that follows a submission (mount
 * a fresh one per attempt, with a `key`, so a repeated failure is announced again).
 */
export function Alert({
  tone,
  focusOnMount = false,
  children,
}: {
  tone: keyof typeof tones
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
      className={`rounded-md border px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-slate-900 ${tones[tone]}`}
    >
      {children}
    </div>
  )
}
