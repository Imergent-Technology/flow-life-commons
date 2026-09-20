import type { Ref, SyntheticEvent } from 'react'

import type { FieldErrors } from '../api/http.ts'
import type { FactorMode } from './factor.ts'
import { SecondFactorFields } from './SecondFactorFields.tsx'
import { SubmitButton } from './SubmitButton.tsx'
import { TextField } from './TextField.tsx'

/**
 * "Confirm it is you": the current password AND a second factor, asked for again in the request that changes
 * or reveals a credential. A session alone is never enough, so every management form starts here.
 */
export function ProofForm({
  title,
  submitLabel,
  pending,
  errors,
  password,
  onPasswordChange,
  mode,
  onModeChange,
  factor,
  onFactorChange,
  passwordRef,
  factorRef,
  onSubmit,
  onCancel,
}: {
  title: string
  submitLabel: string
  pending: boolean
  errors: FieldErrors
  password: string
  onPasswordChange: (value: string) => void
  mode: FactorMode
  onModeChange: (mode: FactorMode) => void
  factor: string
  onFactorChange: (value: string) => void
  passwordRef: Ref<HTMLInputElement>
  factorRef: Ref<HTMLInputElement>
  onSubmit: () => void
  onCancel: () => void
}) {
  return (
    <form
      aria-label={title}
      onSubmit={(event: SyntheticEvent) => {
        event.preventDefault()
        onSubmit()
      }}
      className="flex max-w-md flex-col gap-4"
    >
      <p className="text-sm text-slate-600">
        Confirm it is you: your password and a second-step code.
      </p>
      <TextField
        ref={passwordRef}
        label="Current password"
        name="current_password"
        type="password"
        autoComplete="current-password"
        value={password}
        onChange={onPasswordChange}
        errors={errors.current_password}
      />
      <SecondFactorFields
        mode={mode}
        onModeChange={onModeChange}
        value={factor}
        onValueChange={onFactorChange}
        errors={errors}
        inputRef={factorRef}
      />
      <div className="flex gap-3">
        <SubmitButton pending={pending} pendingLabel="Checking…">
          {submitLabel}
        </SubmitButton>
        <button
          type="button"
          onClick={onCancel}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
        >
          Cancel
        </button>
      </div>
    </form>
  )
}
