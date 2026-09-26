import type { Ref } from 'react'

import type { FieldErrors } from '../api/http.ts'
import { Button } from './Button.tsx'
import type { FactorMode } from './factor.ts'
import { TextField } from './TextField.tsx'
import { TotpCodeField } from './TotpCodeField.tsx'

/**
 * The second-factor input: a code from the authenticator app, or (by a deliberate switch) a recovery code.
 * Guidance only: the server judges the code, and a wrong one is one sentence whatever went wrong.
 */
export function SecondFactorFields({
  mode,
  onModeChange,
  value,
  onValueChange,
  errors,
  inputRef,
  disabled = false,
}: {
  mode: FactorMode
  onModeChange: (mode: FactorMode) => void
  value: string
  onValueChange: (value: string) => void
  errors: FieldErrors
  inputRef?: Ref<HTMLInputElement>
  disabled?: boolean
}) {
  const other: FactorMode = mode === 'code' ? 'recovery' : 'code'
  return (
    <div className="flex flex-col gap-2">
      {mode === 'code' ? (
        <TotpCodeField
          ref={inputRef}
          label="Authentication code"
          value={value}
          onChange={onValueChange}
          errors={errors.code}
          disabled={disabled}
        />
      ) : (
        <TextField
          ref={inputRef}
          label="Recovery code"
          name="recovery_code"
          autoComplete="off"
          value={value}
          onChange={onValueChange}
          errors={errors.recovery_code}
          hint="One of your saved recovery codes. Each works once."
          disabled={disabled}
        />
      )}
      <Button
        variant="ghost"
        size="sm"
        onClick={() => {
          onValueChange('')
          onModeChange(other)
        }}
        className="self-start"
      >
        {mode === 'code' ? 'Use a recovery code instead' : 'Use an authenticator code instead'}
      </Button>
    </div>
  )
}
