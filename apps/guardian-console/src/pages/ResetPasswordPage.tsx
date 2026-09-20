import { useRef, useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { resetPassword } from '../api/auth.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useSecretFragment } from '../auth/useSecretFragment.ts'
import { Alert } from '../ui/Alert.tsx'
import { AuthLayout } from '../ui/AuthLayout.tsx'
import { NewPasswordFields } from '../ui/NewPasswordFields.tsx'
import { describeFailure, type Problem } from '../ui/problem.ts'
import { SubmitButton } from '../ui/SubmitButton.tsx'

/**
 * The page the emailed link opens: `/reset-password#token=...&email=...`. The secrets are read from
 * the fragment into memory and scrubbed from the address bar at once (useSecretFragment). Reloading
 * loses them, so the page then says the link cannot be used and offers to send another.
 */
export function ResetPasswordPage() {
  const { token, email } = useSecretFragment(['token', 'email'])
  const { refresh } = useAuth()
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [pending, setPending] = useState(false)
  const [done, setDone] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const passwordRef = useRef<HTMLInputElement>(null)

  async function submit() {
    if (token === undefined || email === undefined) return
    setPending(true)
    const result = await resetPassword({
      email,
      token,
      password,
      passwordConfirmation: confirmation,
    })
    setPending(false)
    if (result.ok) {
      setPassword('')
      setConfirmation('')
      setProblem(null)
      setDone(true)
      // The reset ended every session the Account had, possibly including one in this browser.
      await refresh()
      return
    }
    setProblem((previous) => ({
      ...describeFailure(result.failure),
      attempt: (previous?.attempt ?? 0) + 1,
    }))
    if (result.failure.kind === 'invalid') passwordRef.current?.focus()
  }

  if (done) {
    return (
      <AuthLayout title="Password changed">
        <Alert tone="success" focusOnMount>
          Your password has been changed and you have been signed out everywhere. Sign in with the
          new password to continue.
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

  // Missing or incomplete link, or one the server refused: one answer, however it went wrong.
  const tokenRefused = problem?.fields.token !== undefined
  if (token === undefined || email === undefined || tokenRefused) {
    return (
      <AuthLayout title="Reset link not usable">
        <Alert tone="error">
          This password reset link is invalid, incomplete or has expired. Request a new one.
        </Alert>
        <Link to="/forgot-password" className="self-start text-slate-700 underline">
          Request a new reset link
        </Link>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      title="Choose a new password"
      intro="Choosing a new password signs you out everywhere."
    >
      {problem ? (
        <Alert
          key={problem.attempt}
          tone="error"
          focusOnMount={problem.fields.password === undefined}
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
        <NewPasswordFields
          passwordRef={passwordRef}
          password={password}
          confirmation={confirmation}
          onPasswordChange={setPassword}
          onConfirmationChange={setConfirmation}
          errors={problem?.fields ?? {}}
        />
        <SubmitButton pending={pending} pendingLabel="Saving…">
          Set new password
        </SubmitButton>
      </form>
    </AuthLayout>
  )
}
