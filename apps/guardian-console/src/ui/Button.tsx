import type { ComponentProps, ReactNode } from 'react'

import { buttonVariants, type ButtonVariants } from './button-variants.ts'
import { cn } from './cn.ts'

type ButtonProps = ComponentProps<'button'> &
  ButtonVariants & {
    /** While true the button cannot be activated and shows `pendingLabel` (when given) in place of its children. */
    pending?: boolean
    pendingLabel?: string
  }

/**
 * The Console's button. `type` defaults to `button`, so a button inside a form never submits by accident;
 * say `type="submit"` for the one that should.
 *
 * Pending is `disabled` (as `SubmitButton` always was) plus `aria-busy`: the platform then guarantees it
 * cannot be activated, by mouse, keyboard or assistive technology. The pending label reserves the width of
 * the idle one where both are plain text, so the button does not jump when it swaps.
 */
export function Button({
  variant,
  size,
  pending = false,
  pendingLabel,
  type = 'button',
  disabled,
  className,
  children,
  ...props
}: ButtonProps) {
  const showPendingLabel = pending && pendingLabel !== undefined
  const otherLabel = showPendingLabel ? children : pendingLabel
  const reserve = typeof otherLabel === 'string' ? otherLabel : undefined
  const content: ReactNode = showPendingLabel ? pendingLabel : children

  return (
    <button
      {...props}
      type={type}
      disabled={disabled === true || pending}
      aria-busy={pending ? true : undefined}
      className={cn(buttonVariants({ variant, size }), className)}
    >
      {reserve === undefined ? (
        content
      ) : (
        <span
          data-reserve={reserve}
          className="inline-flex flex-col items-center after:invisible after:block after:h-0 after:overflow-hidden after:content-[attr(data-reserve)]"
        >
          {content}
        </span>
      )}
    </button>
  )
}
