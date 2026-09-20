import { useId, type HTMLInputAutoCompleteAttribute, type ReactNode, type Ref } from 'react'

interface TextFieldProps {
  label: string
  name: string
  type?: 'text' | 'email' | 'password'
  /** Always given: correct autocomplete is what lets a password manager do its job. */
  autoComplete: HTMLInputAutoCompleteAttribute
  value: string
  onChange: (value: string) => void
  /** Validation errors for this field, shown beside it and tied to it for screen readers. */
  errors?: readonly string[] | undefined
  hint?: ReactNode
  required?: boolean
  disabled?: boolean
  ref?: Ref<HTMLInputElement> | undefined
}

export function TextField({
  label,
  name,
  type = 'text',
  autoComplete,
  value,
  onChange,
  errors,
  hint,
  required = true,
  disabled = false,
  ref,
}: TextFieldProps) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const invalid = errors !== undefined && errors.length > 0
  const describedBy = [hint ? hintId : null, invalid ? errorId : null].filter(Boolean).join(' ')

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-sm font-medium text-slate-800">
        {label}
      </label>
      {hint ? (
        <div id={hintId} className="text-sm text-slate-600">
          {hint}
        </div>
      ) : null}
      <input
        ref={ref}
        id={id}
        name={name}
        type={type}
        autoComplete={autoComplete}
        value={value}
        onChange={(event) => {
          onChange(event.target.value)
        }}
        required={required}
        disabled={disabled}
        aria-invalid={invalid}
        aria-describedby={describedBy === '' ? undefined : describedBy}
        // Text a person types into a credential field is theirs: never corrected, capitalised or trimmed.
        autoCapitalize="none"
        autoCorrect="off"
        spellCheck={false}
        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900 disabled:bg-slate-100 aria-invalid:border-red-600"
      />
      {invalid ? (
        <p id={errorId} className="text-sm text-red-700">
          {errors.join(' ')}
        </p>
      ) : null}
    </div>
  )
}
