import type { ReactNode, Ref } from 'react'

import { normalizeTotpCode } from './totpCode.ts'
import { TextField } from './TextField.tsx'

/**
 * The field for a code from an authenticator app: numeric keyboard on a phone, the one-time-code hint for
 * password managers and the OS, and only digits (at most six) kept, however the code got here. It never
 * submits by itself: the person presses the button, so a mistyped digit is not sent and a screen reader is
 * not surprised by a request.
 */
export function TotpCodeField({
  label,
  value,
  onChange,
  errors,
  hint = 'The 6-digit code from your authenticator app.',
  disabled = false,
  ref,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  errors?: readonly string[] | undefined
  hint?: ReactNode
  disabled?: boolean
  ref?: Ref<HTMLInputElement> | undefined
}) {
  return (
    <TextField
      ref={ref}
      label={label}
      name="code"
      autoComplete="one-time-code"
      inputMode="numeric"
      value={value}
      onChange={(raw) => {
        onChange(normalizeTotpCode(raw))
      }}
      errors={errors}
      hint={hint}
      disabled={disabled}
    />
  )
}
