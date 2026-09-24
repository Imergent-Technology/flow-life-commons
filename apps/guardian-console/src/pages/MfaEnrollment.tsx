import { useRef, useState, type SyntheticEvent } from 'react'

import { beginEnrollment, confirmEnrollment, type AuthenticatorSetup } from '../api/auth.ts'
import { useAuth } from '../auth/auth-context.ts'
import { Alert } from '../ui/Alert.tsx'
import { AuthenticatorSetupDetails } from '../ui/AuthenticatorSetup.tsx'
import { AuthLayout } from '../ui/AuthLayout.tsx'
import { describeFailure, type Problem } from '../ui/problem.ts'
import { RecoveryCodes } from '../ui/RecoveryCodes.tsx'
import { SubmitButton } from '../ui/SubmitButton.tsx'
import { TotpCodeField } from '../ui/TotpCodeField.tsx'

type Phase =
  | { kind: 'intro' }
  | { kind: 'scan'; setup: AuthenticatorSetup }
  /** Enrolled and signed in on the server. The codes exist only in this state, until the person continues. */
  | { kind: 'codes'; codes: string[] }

/**
 * First sign-in of an Account whose access needs a second factor: enrol an authenticator before the Console.
 * Nothing is enrolled by generating a secret; only entering a valid code from it makes it real, and then the
 * recovery codes are shown once. The secret, the QR code and the codes are held in this component's state and
 * nowhere else. The person asks for the secret with a click (not on load), so nothing is generated twice by a
 * remount.
 */
export function MfaEnrollment() {
  const { refresh, endPendingSignIn } = useAuth()
  const [phase, setPhase] = useState<Phase>({ kind: 'intro' })
  const [code, setCode] = useState('')
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const codeRef = useRef<HTMLInputElement>(null)

  const fail = (problem: Problem) => {
    setProblem((previous) => ({ ...problem, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  async function begin() {
    setPending(true)
    const result = await beginEnrollment()
    setPending(false)
    if (result.ok) {
      setProblem(null)
      setPhase({ kind: 'scan', setup: result.value })
      return
    }
    if (result.failure.kind === 'unauthenticated') {
      endPendingSignIn('sign-in-expired')
      return
    }
    fail(describeFailure(result.failure))
  }

  async function confirm() {
    setPending(true)
    const result = await confirmEnrollment({ code })
    setPending(false)
    if (result.ok) {
      setProblem(null)
      setCode('')
      setPhase({ kind: 'codes', codes: result.value }) // the secret and QR code leave memory with the old phase
      return
    }
    if (result.failure.kind === 'unauthenticated') {
      endPendingSignIn('sign-in-expired')
      return
    }
    setCode('')
    fail(describeFailure(result.failure))
    if (result.failure.kind === 'invalid') codeRef.current?.focus()
  }

  if (phase.kind === 'codes') {
    return (
      <AuthLayout title="Save your recovery codes">
        <RecoveryCodes
          codes={phase.codes}
          doneLabel="Continue to the Console"
          onDone={() => {
            void refresh()
          }}
        />
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      title="Set up two-step verification"
      intro="Access to the Console needs a second step as well as your password, so a stolen password is not enough. You will need an authenticator app."
    >
      {problem ? (
        <Alert key={problem.attempt} tone="error" focusOnMount={problem.fields.code === undefined}>
          {problem.message}
        </Alert>
      ) : null}

      {phase.kind === 'intro' ? (
        <button
          type="button"
          disabled={pending}
          onClick={() => {
            void begin()
          }}
          className="self-start rounded-md bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:bg-slate-500"
        >
          {pending ? 'Preparing…' : 'Set up authenticator'}
        </button>
      ) : (
        <form
          onSubmit={(event: SyntheticEvent) => {
            event.preventDefault()
            void confirm()
          }}
          className="flex flex-col gap-4"
        >
          <AuthenticatorSetupDetails setup={phase.setup} />
          <TotpCodeField
            ref={codeRef}
            label="Authentication code"
            value={code}
            onChange={setCode}
            errors={problem?.fields.code}
          />
          <SubmitButton pending={pending} pendingLabel="Checking…">
            Verify and continue
          </SubmitButton>
          <button
            type="button"
            disabled={pending}
            onClick={() => {
              void begin()
            }}
            className="self-start text-sm text-slate-700 underline"
          >
            Start over with a new key
          </button>
        </form>
      )}
    </AuthLayout>
  )
}
