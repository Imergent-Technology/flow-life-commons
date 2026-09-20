import { useRef, useState, type SyntheticEvent } from 'react'

import { useAuth } from '../auth/auth-context.ts'
import { Alert } from '../ui/Alert.tsx'
import { AuthLayout } from '../ui/AuthLayout.tsx'
import { describeFailure, type Problem } from '../ui/problem.ts'
import { proofFrom, type FactorMode } from '../ui/factor.ts'
import { SecondFactorFields } from '../ui/SecondFactorFields.tsx'
import { SubmitButton } from '../ui/SubmitButton.tsx'

/**
 * The second step of signing in, for an Account that has an authenticator. The password was proved and the
 * session does NOT exist yet: only this code (or a recovery code) makes one. A wrong code is one sentence
 * whatever was wrong; a sign-in that has expired (or was ended) sends the person back to the password.
 */
export function MfaChallenge() {
  const { completeSecondFactor, endPendingSignIn } = useAuth()
  const [mode, setMode] = useState<FactorMode>('code')
  const [value, setValue] = useState('')
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  async function submit() {
    setPending(true)
    const result = await completeSecondFactor(proofFrom(mode, value))
    if (result.ok) return // signed in: the state change moves on
    setPending(false)
    setValue('') // a refused code is not kept
    setProblem((previous) => ({
      ...describeFailure(result.failure),
      attempt: (previous?.attempt ?? 0) + 1,
    }))
    if (result.failure.kind === 'invalid') inputRef.current?.focus()
  }

  return (
    <AuthLayout
      title="Enter your code"
      intro="Your password was right. One more step: prove it is you."
    >
      {problem ? (
        <Alert
          key={problem.attempt}
          tone="error"
          focusOnMount={Object.keys(problem.fields).length === 0}
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
        <SecondFactorFields
          mode={mode}
          onModeChange={setMode}
          value={value}
          onValueChange={setValue}
          errors={problem?.fields ?? {}}
          inputRef={inputRef}
        />
        <SubmitButton pending={pending} pendingLabel="Checking…">
          Sign in
        </SubmitButton>
      </form>
      <button
        type="button"
        onClick={() => {
          endPendingSignIn(null)
        }}
        className="self-start text-sm text-slate-700 underline"
      >
        Start over
      </button>
    </AuthLayout>
  )
}
