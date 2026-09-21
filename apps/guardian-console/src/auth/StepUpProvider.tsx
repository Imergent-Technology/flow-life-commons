import { useCallback, useMemo, useRef, useState, type ReactNode } from 'react'

import { verifySecurity } from '../api/auth.ts'
import { Alert } from '../ui/Alert.tsx'
import { proofFrom, type FactorMode } from '../ui/factor.ts'
import { Modal } from '../ui/Modal.tsx'
import { describeFailure, type Problem } from '../ui/problem.ts'
import { ProofForm } from '../ui/ProofForm.tsx'
import { useAuth } from './auth-context.ts'
import { StepUpContext } from './step-up-context.ts'

/**
 * Owns the proof prompt (see step-up-context.ts). The password and the code are held in this component's state only for
 * as long as the prompt is open: they are sent once, to `POST /security/verify`, and cleared when it closes.
 */
export function StepUpProvider({ children }: { children: ReactNode }) {
  const { refresh } = useAuth()
  const [open, setOpen] = useState(false)
  const settle = useRef<((verified: boolean) => void) | null>(null)
  const waiting = useRef<Promise<boolean> | null>(null)

  const request = useCallback((): Promise<boolean> => {
    if (waiting.current !== null) return waiting.current // one prompt at a time: a second ask shares it
    const promise = new Promise<boolean>((resolve) => {
      settle.current = (verified) => {
        settle.current = null
        waiting.current = null
        setOpen(false)
        resolve(verified)
      }
    })
    waiting.current = promise
    setOpen(true)
    return promise
  }, [])

  const value = useMemo(() => ({ request }), [request])

  return (
    <StepUpContext value={value}>
      {children}
      {open ? (
        <StepUpPrompt
          onVerified={() => {
            void refresh().then(() => settle.current?.(true))
          }}
          onCancel={() => settle.current?.(false)}
        />
      ) : null}
    </StepUpContext>
  )
}

function StepUpPrompt({ onVerified, onCancel }: { onVerified: () => void; onCancel: () => void }) {
  const [password, setPassword] = useState('')
  const [mode, setMode] = useState<FactorMode>('code')
  const [factor, setFactor] = useState('')
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const passwordRef = useRef<HTMLInputElement>(null)
  const factorRef = useRef<HTMLInputElement>(null)

  async function submit() {
    setPending(true)
    const result = await verifySecurity({ currentPassword: password, ...proofFrom(mode, factor) })
    setPending(false)
    if (result.ok) {
      setPassword('')
      setFactor('')
      onVerified()
      return
    }
    if (result.failure.kind === 'unauthenticated') {
      onCancel() // the session ended; the boundary handles it
      return
    }
    const next = describeFailure(result.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
    setFactor('')
    if (next.fields.current_password !== undefined) passwordRef.current?.focus()
    else if (next.fields.code !== undefined || next.fields.recovery_code !== undefined)
      factorRef.current?.focus()
  }

  return (
    <Modal
      title="Confirm it is you"
      onClose={() => {
        if (!pending) onCancel()
      }}
    >
      <p className="text-sm text-slate-700">
        This action changes someone&rsquo;s access, so it needs a recent check of who you are.
        Nothing has been changed yet: after you confirm, you will be asked to press the button
        again.
      </p>
      {problem && Object.keys(problem.fields).length === 0 ? (
        <Alert key={problem.attempt} tone="error" focusOnMount>
          {problem.message}
        </Alert>
      ) : null}
      <ProofForm
        title="Confirm it is you"
        submitLabel="Confirm"
        pending={pending}
        errors={problem?.fields ?? {}}
        password={password}
        onPasswordChange={setPassword}
        mode={mode}
        onModeChange={setMode}
        factor={factor}
        onFactorChange={setFactor}
        passwordRef={passwordRef}
        factorRef={factorRef}
        onSubmit={() => {
          void submit()
        }}
        onCancel={onCancel}
      />
    </Modal>
  )
}
