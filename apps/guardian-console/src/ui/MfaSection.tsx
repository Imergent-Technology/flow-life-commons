import { useRef, useState, type SyntheticEvent } from 'react'

import {
  beginReplacement,
  confirmReplacement,
  regenerateRecoveryCodes,
  type AuthenticatorSetup,
  type FreshProof,
} from '../api/auth.ts'
import { useAuth, useCurrentAccount } from '../auth/auth-context.ts'
import { Alert } from './Alert.tsx'
import { AuthenticatorSetupDetails } from './AuthenticatorSetup.tsx'
import { Button } from './Button.tsx'
import { proofFrom, type FactorMode } from './factor.ts'
import { Panel } from './Panel.tsx'
import { describeFailure, type Problem } from './problem.ts'
import { ProofForm } from './ProofForm.tsx'
import { Property, PropertyList } from './PropertyList.tsx'
import { RecoveryCodes } from './RecoveryCodes.tsx'
import { SubmitButton } from './SubmitButton.tsx'
import { TotpCodeField } from './TotpCodeField.tsx'

type PanelState =
  | { kind: 'closed' }
  | { kind: 'regenerate' }
  | { kind: 'regenerated'; codes: string[] }
  | { kind: 'replace' }
  | { kind: 'replace-confirm'; setup: AuthenticatorSetup }
  | { kind: 'replaced' }

/**
 * Two-step verification, once it exists: its status, and the two things that can be done to it, each behind
 * FRESH proof (the current password AND a second factor, typed here and sent in the body): a session alone never
 * exposes or changes a credential. There is deliberately no way to switch it off.
 *
 * Nothing about the authenticator itself is shown: not its secret, not a code. A new secret and new recovery
 * codes are shown once, when generated, and held only in this component's state.
 *
 * Shared by the Console's `AccountSecurityPage` and the Member `SecurityPage` alike (`ui/`, not `pages/`, so
 * the Member surface may import it without crossing `src/member/import-boundary.test.ts`): every operation it
 * calls (`mfa/recovery-codes`, `mfa/authenticator(/confirm)`) needs only `auth:web`, not `console.access`.
 *
 * There is no "not set up" state here, on purpose: EVERY caller renders this only once `current.mfa.enrolled`
 * is already true (an Account reaching the Console always has one, since Console access needs a second
 * factor, ADR 0023; a Member may or may not, and `SecurityPage` checks first). First-time enrolment is part
 * of signing in (`MfaEnrollment`), not of this component, for either surface.
 */
export function MfaSection() {
  const current = useCurrentAccount()
  const { refresh } = useAuth()
  const [panel, setPanel] = useState<PanelState>({ kind: 'closed' })
  const [password, setPassword] = useState('')
  const [mode, setMode] = useState<FactorMode>('code')
  const [factor, setFactor] = useState('')
  const [newCode, setNewCode] = useState('')
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const passwordRef = useRef<HTMLInputElement>(null)
  const factorRef = useRef<HTMLInputElement>(null)
  const newCodeRef = useRef<HTMLInputElement>(null)

  const { recovery_codes_remaining: remaining } = current.mfa

  function open(next: PanelState) {
    setPanel(next)
    setProblem(null)
    setPassword('')
    setFactor('')
    setNewCode('')
    setMode('code') // every panel starts with the authenticator field, whatever the last one was left on
  }

  function fail(failure: Parameters<typeof describeFailure>[0]) {
    const next = describeFailure(failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
    setFactor('')
    if (next.fields.current_password !== undefined) passwordRef.current?.focus()
    else if (next.fields.code !== undefined || next.fields.recovery_code !== undefined)
      factorRef.current?.focus()
  }

  const proof = (): FreshProof => ({ currentPassword: password, ...proofFrom(mode, factor) })

  async function regenerate() {
    setPending(true)
    const result = await regenerateRecoveryCodes(proof())
    setPending(false)
    if (!result.ok) {
      if (result.failure.kind !== 'unauthenticated') fail(result.failure) // 401: the session ended; the boundary handles it
      return
    }
    open({ kind: 'regenerated', codes: result.value })
    await refresh() // the count, and the session's fresh verification
  }

  async function beginReplace() {
    setPending(true)
    const result = await beginReplacement(proof())
    setPending(false)
    if (!result.ok) {
      if (result.failure.kind !== 'unauthenticated') fail(result.failure)
      return
    }
    open({ kind: 'replace-confirm', setup: result.value })
  }

  async function confirmReplace() {
    setPending(true)
    const result = await confirmReplacement({ code: newCode })
    setPending(false)
    if (!result.ok) {
      if (result.failure.kind === 'unauthenticated') return
      if (result.failure.kind === 'invalid' && result.failure.errors.authenticator !== undefined) {
        // The server says there is no pending setup to prove any more (it expired, or was replaced). The dead
        // QR code and key must not stay up: back to the proof step, which is how a new one is asked for.
        open({ kind: 'replace' })
        setProblem((previous) => ({
          message:
            'That setup is no longer valid, so the key and QR code have been removed. Confirm it is you to get a new one.',
          fields: {},
          attempt: (previous?.attempt ?? 0) + 1,
        }))
        return
      }
      setNewCode('')
      const next = describeFailure(result.failure)
      setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
      newCodeRef.current?.focus()
      return
    }
    open({ kind: 'replaced' })
    await refresh()
  }

  const proofFormProps = {
    pending,
    errors: problem?.fields ?? {},
    password,
    onPasswordChange: setPassword,
    mode,
    onModeChange: setMode,
    factor,
    onFactorChange: setFactor,
    passwordRef,
    factorRef,
    onCancel: () => {
      open({ kind: 'closed' })
    },
  }

  return (
    <Panel title="Two-step verification">
      <div className="flex flex-col gap-4">
        <PropertyList>
          <Property term="Authenticator app">On</Property>
          <Property term="Recovery codes left">{remaining}</Property>
        </PropertyList>
        {remaining <= 3 ? (
          <Alert tone="info">
            {remaining === 0
              ? 'You have no recovery codes left. Generate new ones so you can get in if you lose your authenticator.'
              : 'You are running low on recovery codes. Consider generating a new set.'}
          </Alert>
        ) : null}

        {/* The three panels with a form of their own show their error beside it, so it is announced once. */}
        {problem &&
        panel.kind !== 'regenerate' &&
        panel.kind !== 'replace' &&
        panel.kind !== 'replace-confirm' ? (
          <Alert
            key={problem.attempt}
            tone="error"
            focusOnMount={problem.fields.code === undefined}
          >
            {problem.message}
          </Alert>
        ) : null}

        {panel.kind === 'closed' ? (
          <div className="flex flex-wrap gap-2">
            <Button
              onClick={() => {
                open({ kind: 'regenerate' })
              }}
            >
              Generate new recovery codes
            </Button>
            <Button
              onClick={() => {
                open({ kind: 'replace' })
              }}
            >
              Replace authenticator
            </Button>
          </div>
        ) : null}

        {panel.kind === 'regenerate' ? (
          <>
            {problem ? (
              <Alert
                key={problem.attempt}
                tone="error"
                focusOnMount={Object.keys(problem.fields).length === 0}
              >
                {problem.message}
              </Alert>
            ) : null}
            <p className="text-label text-muted-foreground">
              New codes replace every code you have now: the old ones stop working.
            </p>
            <ProofForm
              {...proofFormProps}
              title="Generate new recovery codes"
              submitLabel="Generate codes"
              onSubmit={() => {
                void regenerate()
              }}
            />
          </>
        ) : null}

        {panel.kind === 'regenerated' ? (
          <RecoveryCodes
            codes={panel.codes}
            doneLabel="Done"
            onDone={() => {
              open({ kind: 'closed' })
            }}
          />
        ) : null}

        {panel.kind === 'replace' ? (
          <>
            {problem ? (
              <Alert
                key={problem.attempt}
                tone="error"
                focusOnMount={Object.keys(problem.fields).length === 0}
              >
                {problem.message}
              </Alert>
            ) : null}
            <p className="text-label text-muted-foreground">
              Your current authenticator keeps working until you prove the new one, so you cannot
              lock yourself out.
            </p>
            <ProofForm
              {...proofFormProps}
              title="Replace authenticator"
              submitLabel="Continue"
              onSubmit={() => {
                void beginReplace()
              }}
            />
          </>
        ) : null}

        {panel.kind === 'replace-confirm' ? (
          <form
            aria-label="Prove the new authenticator"
            onSubmit={(event: SyntheticEvent) => {
              event.preventDefault()
              void confirmReplace()
            }}
            className="flex flex-col gap-4"
          >
            {problem ? (
              <Alert
                key={problem.attempt}
                tone="error"
                focusOnMount={problem.fields.code === undefined}
              >
                {problem.message}
              </Alert>
            ) : null}
            <AuthenticatorSetupDetails setup={panel.setup} />
            <TotpCodeField
              ref={newCodeRef}
              label="Code from the new authenticator"
              value={newCode}
              onChange={setNewCode}
              errors={problem?.fields.code}
            />
            <div className="flex gap-3">
              <SubmitButton pending={pending} pendingLabel="Checking…">
                Switch to the new authenticator
              </SubmitButton>
              <Button
                onClick={() => {
                  open({ kind: 'closed' })
                }}
              >
                Cancel
              </Button>
            </div>
          </form>
        ) : null}

        {panel.kind === 'replaced' ? (
          <Alert tone="success" focusOnMount>
            Your authenticator has been replaced. The old one no longer works, and other devices
            have been signed out.
          </Alert>
        ) : null}
      </div>
    </Panel>
  )
}
