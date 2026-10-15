import { useRef, useState, type SyntheticEvent } from 'react'

import { changePassword } from '../api/auth.ts'
import { useAuth, useCurrentAccount } from '../auth/auth-context.ts'
import { Alert } from './Alert.tsx'
import { proofFrom, type FactorMode } from './factor.ts'
import { NewPasswordFields } from './NewPasswordFields.tsx'
import { Panel } from './Panel.tsx'
import { describeFailure, type Problem } from './problem.ts'
import { SecondFactorFields } from './SecondFactorFields.tsx'
import { SubmitButton } from './SubmitButton.tsx'
import { TextField } from './TextField.tsx'

/**
 * Change the signed-in Account's password. Shared by the Console's Account security page and the
 * Member self-service Security page alike: any authenticated Account may change its own password
 * (`POST /password/change` needs only `auth:web`, ADR 0016), so there is nothing surface-specific here.
 *
 * The current password is required again: a session alone is not enough. An Account that has an
 * authenticator must also give a fresh second factor (an authenticator code, or by a deliberate switch a
 * recovery code; ADR 0023). `/me` says whether the Account has one, and that decides only what is SHOWN:
 * the server decides what is required, under the Account's lock. So if it asks for a code of an Account this
 * page thought had none (one enrolled in another tab), the field appears and the person tries again; and the
 * code is sent only while the field is shown. An Account without an authenticator is never asked for one.
 *
 * On success the server ends every OTHER session and rotates THIS one, so the person stays signed in. A
 * password change is not a sign-in: the session keeps its original time limit, which `/me` is re-read to show
 * rather than assumed. If the server says the session is gone (401), the shared session handling takes over.
 */
export function ChangePasswordPanel() {
  const current = useCurrentAccount()
  const { refresh } = useAuth()
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [factorMode, setFactorMode] = useState<FactorMode>('code')
  const [factor, setFactor] = useState('')
  // The server asked for a code that `/me` did not say was needed.
  const [factorAsked, setFactorAsked] = useState(false)
  const [pending, setPending] = useState(false)
  const [changes, setChanges] = useState(0)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const currentRef = useRef<HTMLInputElement>(null)
  const factorRef = useRef<HTMLInputElement>(null)
  const newRef = useRef<HTMLInputElement>(null)

  const needsFactor = current.mfa.enrolled || factorAsked

  async function submit() {
    setPending(true)
    const result = await changePassword({
      currentPassword,
      password,
      passwordConfirmation: confirmation,
      proof: needsFactor ? proofFrom(factorMode, factor) : undefined,
    })
    setPending(false)

    if (result.ok) {
      setCurrentPassword('')
      setPassword('')
      setConfirmation('')
      setFactor('')
      setFactorMode('code')
      setProblem(null)
      setChanges((n) => n + 1)
      await refresh() // the session was rotated: show the server's account of it
      return
    }
    if (result.failure.kind === 'unauthenticated') return // the session ended; the boundary handles it

    const next = describeFailure(result.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
    // A code is single use: whatever was typed, right or wrong, is not worth keeping.
    setFactor('')
    const factorRefused = next.fields.code !== undefined || next.fields.recovery_code !== undefined
    if (factorRefused) setFactorAsked(true)
    if (next.fields.current_password !== undefined) currentRef.current?.focus()
    else if (factorRefused) {
      // The field may not be on the page yet (the server asked for a code `/me` did not mention).
      setTimeout(() => factorRef.current?.focus(), 0)
    } else if (
      next.fields.password !== undefined ||
      next.fields.password_confirmation !== undefined
    ) {
      newRef.current?.focus()
    }
  }

  const fieldFailure =
    problem !== null &&
    (problem.fields.current_password !== undefined ||
      problem.fields.code !== undefined ||
      problem.fields.recovery_code !== undefined ||
      problem.fields.password !== undefined ||
      problem.fields.password_confirmation !== undefined)

  return (
    <Panel
      title="Change password"
      description="Changing your password signs you out of every other device. This session stays open, but it still ends when it would have: changing your password does not extend it."
    >
      <div className="flex flex-col gap-4">
        {changes > 0 ? (
          <Alert key={changes} tone="success" focusOnMount>
            Your password has been changed. Other devices have been signed out. This session’s time
            limit is unchanged.
          </Alert>
        ) : null}
        {problem ? (
          <Alert key={problem.attempt} tone="error" focusOnMount={!fieldFailure}>
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
          {needsFactor ? (
            <SecondFactorFields
              mode={factorMode}
              onModeChange={setFactorMode}
              value={factor}
              onValueChange={setFactor}
              errors={problem?.fields ?? {}}
              inputRef={factorRef}
            />
          ) : null}
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
      </div>
    </Panel>
  )
}
