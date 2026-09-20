import { useRef, useState, type SyntheticEvent } from 'react'

import { changePassword } from '../api/auth.ts'
import { useAuth, useCurrentAccount } from '../auth/auth-context.ts'
import { Alert } from '../ui/Alert.tsx'
import { NewPasswordFields } from '../ui/NewPasswordFields.tsx'
import { PageHeading } from '../ui/PageHeading.tsx'
import { describeFailure, type Problem } from '../ui/problem.ts'
import { SessionSummary } from '../ui/SessionSummary.tsx'
import { SubmitButton } from '../ui/SubmitButton.tsx'
import { MfaSection } from './MfaSection.tsx'
import { TextField } from '../ui/TextField.tsx'

/**
 * Change the signed-in Account's password. The current password is required again: a session alone is
 * not enough (there is no step-up authentication yet, so this is the re-authentication control).
 *
 * On success the server ends every OTHER session and rotates THIS one, so the person stays signed in:
 * the Console re-reads `/me` (the new authentication time) rather than sending them back to sign in.
 * If the server says the session is gone (401), the shared session handling takes over instead.
 */
export function AccountSecurityPage() {
  const current = useCurrentAccount()
  const { refresh } = useAuth()
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [pending, setPending] = useState(false)
  const [changes, setChanges] = useState(0)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const currentRef = useRef<HTMLInputElement>(null)
  const newRef = useRef<HTMLInputElement>(null)

  async function submit() {
    setPending(true)
    const result = await changePassword({
      currentPassword,
      password,
      passwordConfirmation: confirmation,
    })
    setPending(false)

    if (result.ok) {
      setCurrentPassword('')
      setPassword('')
      setConfirmation('')
      setProblem(null)
      setChanges((n) => n + 1)
      await refresh() // the session was rotated and re-dated: show the server's account of it
      return
    }
    if (result.failure.kind === 'unauthenticated') return // the session ended; the boundary handles it

    const next = describeFailure(result.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
    if (next.fields.current_password !== undefined) currentRef.current?.focus()
    else if (
      next.fields.password !== undefined ||
      next.fields.password_confirmation !== undefined
    ) {
      newRef.current?.focus()
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeading title="Account security" />

      <section aria-labelledby="session-heading" className="flex flex-col gap-2">
        <h2 id="session-heading" className="text-lg font-medium">
          Your session
        </h2>
        <SessionSummary />
      </section>

      <MfaSection />

      <section aria-labelledby="password-heading" className="flex max-w-md flex-col gap-4">
        <h2 id="password-heading" className="text-lg font-medium">
          Change password
        </h2>
        <p className="text-sm text-slate-600">
          Changing your password signs you out of every other device. This session stays open.
        </p>
        {changes > 0 ? (
          <Alert key={changes} tone="success" focusOnMount>
            Your password has been changed. Other devices have been signed out.
          </Alert>
        ) : null}
        {problem ? (
          <Alert
            key={problem.attempt}
            tone="error"
            focusOnMount={
              problem.fields.current_password === undefined &&
              problem.fields.password === undefined &&
              problem.fields.password_confirmation === undefined
            }
          >
            {problem.message}
          </Alert>
        ) : null}
        <form
          onSubmit={(event: SyntheticEvent) => {
            event.preventDefault()
            void submit()
          }}
          className="flex flex-col gap-4"
        >
          {/* Tells a password manager which saved login this password belongs to. Not for people. */}
          <input
            type="text"
            name="username"
            autoComplete="username"
            value={current.account.email}
            readOnly
            tabIndex={-1}
            aria-hidden="true"
            className="sr-only"
          />
          <TextField
            ref={currentRef}
            label="Current password"
            name="current_password"
            type="password"
            autoComplete="current-password"
            value={currentPassword}
            onChange={setCurrentPassword}
            errors={problem?.fields.current_password}
          />
          <NewPasswordFields
            passwordRef={newRef}
            password={password}
            confirmation={confirmation}
            onPasswordChange={setPassword}
            onConfirmationChange={setConfirmation}
            errors={problem?.fields ?? {}}
          />
          <SubmitButton pending={pending} pendingLabel="Changing password…">
            Change password
          </SubmitButton>
        </form>
      </section>
    </div>
  )
}
