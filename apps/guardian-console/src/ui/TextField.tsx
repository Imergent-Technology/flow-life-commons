import type { HTMLInputAutoCompleteAttribute, ReactNode, Ref } from 'react'

import { Field } from './Field.tsx'
import { Input } from './Input.tsx'

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
  inputMode?: 'numeric' | 'text'
  maxLength?: number
  ref?: Ref<HTMLInputElement> | undefined
}

/** A labelled text input for the credential flows: a `Field` around an `Input`, with the credential attributes fixed. */
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
  inputMode,
  maxLength,
  ref,
}: TextFieldProps) {
  const error = errors !== undefined && errors.length > 0 ? errors.join(' ') : undefined

  return (
    <Field label={label} hint={hint} error={error}>
      {(control) => (
        <Input
          {...control}
          ref={ref}
          name={name}
          type={type}
          autoComplete={autoComplete}
          value={value}
          onChange={(event) => {
            onChange(event.target.value)
          }}
          required={required}
          disabled={disabled}
          inputMode={inputMode}
          maxLength={maxLength}
          // Text a person types into a credential field is theirs: never corrected, capitalised or trimmed.
          autoCapitalize="none"
          autoCorrect="off"
          spellCheck={false}
        />
      )}
    </Field>
  )
}
