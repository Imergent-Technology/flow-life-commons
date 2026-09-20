import { useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { requestPasswordReset } from '../api/auth.ts'
import { Alert } from '../ui/Alert.tsx'
import { AuthLayout } from '../ui/AuthLayout.tsx'
import { describeFailure, type Problem } from '../ui/problem.ts'
import { SubmitButton } from '../ui/SubmitButton.tsx'
import { TextField } from '../ui/TextField.tsx'

// The same words whether or not an Account exists for the address, is invited, or is disabled. The
// server answers all of those identically (and takes the same time), and the screen must too: this is
// what stops the form being a way to find out who has an Account.
const SENT =
  'If an eligible account exists for that address, password reset instructions have been sent.'

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [pending, setPending] = useState(false)
  const [sent, setSent] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)

  async function submit() {
    setPending(true)
    const result = await requestPasswordReset({ email })
    setPending(false)
    if (result.ok) {
      setProblem(null)
      setSent(true)
      return
    }
    // Only failures that carry no information about the address: a malformed one, rate limiting
    // (identical for known and unknown addresses), or an outage.
    setProblem((previous) => ({
      ...describeFailure(result.failure),
      attempt: (previous?.attempt ?? 0) + 1,
    }))
  }

  return (
    <AuthLayout
      title="Forgot your password?"
      intro="Enter the email address of your account and we will send you a link to choose a new password."
    >
      {sent ? (
        <Alert tone="success" focusOnMount>
          {SENT}
        </Alert>
      ) : (
        <>
          {problem ? (
            <Alert key={problem.attempt} tone="error" focusOnMount>
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
            <TextField
              label="Email address"
              name="email"
              type="email"
              autoComplete="email"
              value={email}
              onChange={setEmail}
              errors={problem?.fields.email}
            />
            <SubmitButton pending={pending} pendingLabel="Sending…">
              Send reset link
            </SubmitButton>
          </form>
        </>
      )}
      <Link to="/login" className="text-sm text-slate-700 underline">
        Back to sign in
      </Link>
    </AuthLayout>
  )
}
