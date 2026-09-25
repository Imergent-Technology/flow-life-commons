import { useId, type ComponentProps, type ReactNode } from 'react'

import { cn, focusRing } from './cn.ts'

/**
 * A native checkbox with its label. Clicking the label toggles it, and the optional hint is tied to the
 * checkbox with `aria-describedby`.
 */
export function Checkbox({
  label,
  hint,
  className,
  ...props
}: Omit<ComponentProps<'input'>, 'type' | 'children'> & { label: ReactNode; hint?: ReactNode }) {
  const generatedId = useId()
  const id = props.id ?? generatedId
  const hintId = `${id}-hint`
  const hasHint = hint !== undefined && hint !== null && hint !== false && hint !== ''

  return (
    <div className="flex items-start gap-2.5">
      <input
        {...props}
        id={id}
        type="checkbox"
        aria-describedby={hasHint ? hintId : props['aria-describedby']}
        className={cn(
          'mt-0.5 size-4 shrink-0 cursor-pointer rounded-sm accent-primary disabled:cursor-not-allowed',
          focusRing,
          className,
        )}
      />
      <div className="flex flex-col">
        <label htmlFor={id} className="text-body text-foreground">
          {label}
        </label>
        {hasHint ? (
          <span id={hintId} className="text-meta text-muted-foreground">
            {hint}
          </span>
        ) : null}
      </div>
    </div>
  )
}
