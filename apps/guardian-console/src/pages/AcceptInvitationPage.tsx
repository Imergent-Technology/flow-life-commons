import { useRef, useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { acceptInvitation } from '../api/auth.ts'
import { useSecretFragment } from '../auth/useSecretFragment.ts'
import { Alert } from '../ui/Alert.tsx'
import { AuthLayout } from '../ui/AuthLayout.tsx'
import { NewPasswordFields } from '../ui/NewPasswordFields.tsx'
import { describeFailure, type Problem } from '../ui/problem.ts'
import { SubmitButton } from '../ui/SubmitButton.tsx'
import { TextField } from '../ui/TextField.tsx'

/**
 * Accepts an invitation and chooses a password. The token comes from `#token=...` when a link carried
 * it (read into memory and scrubbed from the address bar at once), or is entered by hand: the
 * administrator bootstrap prints an opaque token rather than mailing a link.
 *
 * Accepting does NOT sign anyone in, and does not claim their email is verified. The person signs in
 * as usual afterwards.
 */
export function AcceptInvitationPage() {
  const { token: linkToken } = useSecretFragment(['token'])
  const [typedToken, setTypedToken] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [pending, setPending] = useState(false)
  const [done, setDone] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const tokenRef = useRef<HTMLInputElement>(null)
  const passwordRef = useRef<HTMLInputElement>(null)

  const token = linkToken ?? typedToken

  async function submit() {
    setPending(true)
    const result = await acceptInvitation({ token, password, passwordConfirmation: confirmation })
    setPending(false)
    if (result.ok) {
      setPassword('')
      setConfirmation('')
      setTypedToken('')
      setProblem(null)
      setDone(true)
      return
    }
    setProblem((previous) => ({
      ...describeFailure(result.failure),
      attempt: (previous?.attempt ?? 0) + 1,
    }))
    if (result.failure.kind === 'invalid') {
      const { fields } = describeFailure(result.failure)
      if (fields.token !== undefined && linkToken === undefined) tokenRef.current?.focus()
      else if (fields.password !== undefined || fields.password_confirmation !== undefined) {
        passwordRef.current?.focus()
      }
    }
  }

  if (done) {
    return (
      <AuthLayout title="Invitation accepted">
        <Alert tone="success" focusOnMount>
          Your password is set and your account is active. You are not signed in yet: sign in with
          your email address and new password to continue.
        </Alert>
        <Link
          to="/login"
          className="self-start rounded-md bg-slate-900 px-4 py-2 font-medium text-white"
        >
          Continue to sign in
        </Link>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title="Accept your invitation" intro="Choose a password to activate your account.">
      {problem ? (
        <Alert
          key={problem.attempt}
          tone="error"
          focusOnMount={
            problem.fields.password === undefined &&
            problem.fields.password_confirmation === undefined &&
            (problem.fields.token === undefined || linkToken !== undefined)
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
        {linkToken === undefined ? (
          <TextField
            ref={tokenRef}
            label="Invitation token"
            name="token"
            // Plain text on purpose: a password-type field would invite a password manager to store
            // a one-time token, and the person pasting it needs to see it arrived intact.
            type="text"
            autoComplete="off"
            value={typedToken}
            onChange={setTypedToken}
            errors={problem?.fields.token}
            hint="The token you were given with your invitation."
          />
        ) : (
          <p className="text-sm text-slate-600">Your invitation link was recognised.</p>
        )}
        <NewPasswordFields
          passwordRef={passwordRef}
          password={password}
          confirmation={confirmation}
          onPasswordChange={setPassword}
          onConfirmationChange={setConfirmation}
          errors={problem?.fields ?? {}}
        />
        <SubmitButton pending={pending} pendingLabel="Saving…">
          Set password and activate
        </SubmitButton>
      </form>
      <Link to="/login" className="text-sm text-slate-700 underline">
        Back to sign in
      </Link>
    </AuthLayout>
  )
}
