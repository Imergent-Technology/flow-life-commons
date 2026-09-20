import { useRef, useState, type SyntheticEvent } from 'react'
import { Link, Navigate, useLocation } from 'react-router'

import { useAuth } from '../auth/auth-context.ts'
import { returnPathFrom } from '../auth/returnPath.ts'
import { Alert } from '../ui/Alert.tsx'
import { MfaChallenge } from './MfaChallenge.tsx'
import { MfaEnrollment } from './MfaEnrollment.tsx'
import { AuthLayout } from '../ui/AuthLayout.tsx'
import { describeFailure, type Problem } from '../ui/problem.ts'
import { ServiceUnavailable } from '../ui/ServiceUnavailable.tsx'
import { StatusScreen } from '../ui/StatusScreen.tsx'
import { SubmitButton } from '../ui/SubmitButton.tsx'
import { TextField } from '../ui/TextField.tsx'

// One sentence for EVERY refused sign-in (unknown address, wrong password, invited Account, disabled
// Account). The server answers them identically on purpose; the screen must not undo that.
const CREDENTIALS_REFUSED = 'The email address or password is incorrect.'

const NOTICES = {
  'signed-out': 'You have been signed out.',
  'session-ended': 'Your session has ended. Sign in again to continue.',
  'sign-in-expired': 'Your sign-in timed out. Enter your password again.',
} as const

export function LoginPage() {
  const { state, signIn, refresh } = useAuth()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const emailRef = useRef<HTMLInputElement>(null)

  if (state.status === 'loading') return <StatusScreen>Checking your session…</StatusScreen>
  if (state.status === 'unavailable') return <ServiceUnavailable onRetry={refresh} />
  // The password was proved; the second factor is the next step. Not signed in, so no Console yet.
  if (state.status === 'second-factor') {
    return state.step === 'challenge' ? <MfaChallenge /> : <MfaEnrollment />
  }
  // Already signed in (or just now signed in): on to the Console, or back to where they were headed.
  if (state.status === 'authenticated') {
    return <Navigate to={returnPathFrom(location.state)} replace />
  }

  async function submit() {
    setPending(true)
    const result = await signIn(email, password)
    if (result.ok) {
      // Signed in (the state change navigates away) or on to the second factor. Either way the password has
      // done its job: this component stays mounted for the second step, so it must not keep it.
      setPassword('')
      setPending(false)
      return
    }

    setPending(false)
    setPassword('') // a refused password is not kept
    setProblem((previous) => {
      const attempt = (previous?.attempt ?? 0) + 1
      return result.failure.kind === 'unauthenticated'
        ? { message: CREDENTIALS_REFUSED, fields: {}, attempt }
        : { ...describeFailure(result.failure), attempt }
    })
    if (result.failure.kind === 'invalid') emailRef.current?.focus()
  }

  return (
    <AuthLayout title="Sign in" intro="For Guardians and operators. Accounts are by invitation.">
      {state.notice ? <Alert tone="info">{NOTICES[state.notice]}</Alert> : null}
      {problem ? (
        <Alert key={problem.attempt} tone="error" focusOnMount={problem.fields.email === undefined}>
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
          ref={emailRef}
          label="Email address"
          name="email"
          type="email"
          autoComplete="username"
          value={email}
          onChange={setEmail}
          errors={problem?.fields.email}
        />
        <TextField
          label="Password"
          name="password"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={setPassword}
        />
        <SubmitButton pending={pending} pendingLabel="Signing in…">
          Sign in
        </SubmitButton>
      </form>
      <nav aria-label="Account help" className="flex flex-col gap-1 text-sm">
        <Link to="/forgot-password" className="text-slate-700 underline">
          Forgot your password?
        </Link>
        <Link to="/accept-invitation" className="text-slate-700 underline">
          I have an invitation
        </Link>
      </nav>
    </AuthLayout>
  )
}
