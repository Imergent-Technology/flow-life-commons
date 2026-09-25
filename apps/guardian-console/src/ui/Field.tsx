import { useId, type ReactNode } from 'react'

import { StatusIcon } from './StatusIcon.tsx'

/** What a control needs from its Field to be labelled, described and flagged invalid. */
export interface FieldControlProps {
  id: string
  'aria-describedby': string | undefined
  'aria-invalid': true | undefined
}

/**
 * The common composition of a form control: label, optional hint, the control, optional error. The control
 * is a render prop, so `Input`, `Select` or any native control stays an ordinary element and receives the
 * wiring (`id`, `aria-describedby`, `aria-invalid`) rather than being cloned or wrapped:
 *
 *     <Field label="Email" error={message}>{(control) => <Input {...control} type="email" />}</Field>
 *
 * An error is text with an icon, never colour alone. Field holds no validation logic: the caller decides
 * whether there is an error and what it says.
 */
export function Field({
  label,
  hint,
  error,
  children,
}: {
  label: ReactNode
  hint?: ReactNode
  error?: ReactNode
  children: (control: FieldControlProps) => ReactNode
}) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const hasHint = hint !== undefined && hint !== null && hint !== false && hint !== ''
  const hasError = error !== undefined && error !== null && error !== false && error !== ''
  const describedBy = [hasHint ? hintId : null, hasError ? errorId : null].filter(Boolean).join(' ')

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-label font-medium text-foreground">
        {label}
      </label>
      {hasHint ? (
        <div id={hintId} className="text-meta text-muted-foreground">
          {hint}
        </div>
      ) : null}
      {children({
        id,
        'aria-describedby': describedBy === '' ? undefined : describedBy,
        'aria-invalid': hasError ? true : undefined,
      })}
      {hasError ? (
        <p id={errorId} className="flex items-start gap-1.5 text-label text-danger">
          <StatusIcon kind="error" className="mt-0.5 size-4 shrink-0" />
          <span>{error}</span>
        </p>
      ) : null}
    </div>
  )
}
