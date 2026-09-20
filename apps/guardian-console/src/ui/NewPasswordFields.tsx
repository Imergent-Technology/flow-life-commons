import type { Ref } from 'react'

import type { FieldErrors } from '../api/http.ts'
import { PasswordRequirements } from './PasswordRequirements.tsx'
import { TextField } from './TextField.tsx'

/**
 * The "new password, and again" pair shared by invitation acceptance, reset and change. Guidance only:
 * the server is the judge of the password, so nothing here checks length, content or that the two match.
 */
export function NewPasswordFields({
  password,
  confirmation,
  onPasswordChange,
  onConfirmationChange,
  errors,
  passwordRef,
  disabled = false,
}: {
  password: string
  confirmation: string
  onPasswordChange: (value: string) => void
  onConfirmationChange: (value: string) => void
  errors: FieldErrors
  passwordRef?: Ref<HTMLInputElement>
  disabled?: boolean
}) {
  return (
    <>
      <TextField
        ref={passwordRef}
        label="New password"
        name="password"
        type="password"
        autoComplete="new-password"
        value={password}
        onChange={onPasswordChange}
        errors={errors.password}
        hint={<PasswordRequirements />}
        disabled={disabled}
      />
      <TextField
        label="Confirm new password"
        name="password_confirmation"
        type="password"
        autoComplete="new-password"
        value={confirmation}
        onChange={onConfirmationChange}
        errors={errors.password_confirmation}
        disabled={disabled}
      />
    </>
  )
}
