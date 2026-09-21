import { useCallback, useRef, useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { inviteOperator, listRoleCatalog, type InvitationOutcome } from '../../api/admin.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { useLoad } from '../../admin/useLoad.ts'
import { describeAdminFailure } from '../../admin/wording.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, ROLES_ASSIGN } from '../../auth/capabilities.ts'
import { Alert } from '../../ui/Alert.tsx'
import { secondaryButton } from '../../ui/classes.ts'
import { PageHeading } from '../../ui/PageHeading.tsx'
import { type Problem } from '../../ui/problem.ts'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { TextField } from '../../ui/TextField.tsx'

/**
 * Invite a new operator: a name, an email address and, if you may assign access, what they should be able to do. The
 * invitation goes to that address by email and is never shown here. The Account can exist with no access at all; it simply
 * cannot use the Console until someone gives it some.
 */
export function InviteOperatorPage() {
  const current = useCurrentAccount()
  const mayAssign = hasCapability(current, ROLES_ASSIGN)
  const run = useAdminAction()
  const loadCatalog = useCallback((signal: AbortSignal) => listRoleCatalog(signal), [])
  const [catalog] = useLoad(loadCatalog)

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [chosen, setChosen] = useState<string[]>([])
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [result, setResult] = useState<InvitationOutcome | null>(null)
  const emailRef = useRef<HTMLInputElement>(null)

  async function submit() {
    setPending(true)
    setNotice(null)
    const outcome = await run(() =>
      inviteOperator({ email, displayName: name, initialAssignments: chosen }),
    )
    setPending(false)

    if (outcome.status === 'done') {
      setProblem(null)
      setResult(outcome.value)
      return
    }
    if (outcome.status === 'verify') {
      setNotice(
        outcome.verified
          ? 'You are verified. Nothing has been sent yet: press “Send invitation” again to continue.'
          : 'Verification was cancelled. Nothing has been sent.',
      )
      return
    }
    if (outcome.failure.kind === 'unauthenticated') return // the session ended; the boundary handles it
    const next = describeAdminFailure(outcome.failure)
    const fields = { ...next.fields }
    if (outcome.failure.kind === 'conflict' && outcome.failure.code === 'email_already_in_use') {
      fields.email = [next.message]
    }
    setProblem((previous) => ({ ...next, fields, attempt: (previous?.attempt ?? 0) + 1 }))
    if (fields.email !== undefined) emailRef.current?.focus()
  }

  if (result !== null) {
    const sent = result.delivery === 'sent'
    return (
      <div className="flex max-w-lg flex-col gap-4">
        <PageHeading title={sent ? 'Invitation sent' : 'Invitation not sent'} />
        {sent ? (
          <Alert tone="success" focusOnMount>
            {result.account.displayName} has been invited. The invitation went to{' '}
            <strong>{result.account.email}</strong>; it works once. They set their password from the
            link in it.
          </Alert>
        ) : (
          <Alert tone="error" focusOnMount>
            {result.account.displayName} was added, but the invitation email could not be sent. Open
            their account and choose “Send a new invitation” to try again.
          </Alert>
        )}
        <div className="flex flex-wrap gap-3">
          <Link to={`/admin/accounts/${result.account.id}`} className={secondaryButton}>
            Open the account
          </Link>
          <button
            type="button"
            className={secondaryButton}
            onClick={() => {
              setResult(null)
              setName('')
              setEmail('')
              setChosen([])
            }}
          >
            Invite someone else
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="flex max-w-lg flex-col gap-4">
      <PageHeading title="Invite an operator" />
      <p className="text-sm text-slate-600">
        They will get an email with a link to set their password. Nothing is shown here that they
        would need to sign in.
      </p>
      {notice ? (
        <Alert key={notice} tone="info" focusOnMount>
          {notice}
        </Alert>
      ) : null}
      {problem && Object.keys(problem.fields).length === 0 ? (
        <Alert key={problem.attempt} tone="error" focusOnMount>
          {problem.message}
        </Alert>
      ) : null}
      <form
        aria-label="Invite an operator"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          void submit()
        }}
        className="flex flex-col gap-4"
      >
        <TextField
          label="Display name"
          name="display_name"
          autoComplete="off"
          value={name}
          onChange={setName}
          errors={problem?.fields.display_name}
          maxLength={200}
        />
        <TextField
          ref={emailRef}
          label="Email address"
          name="email"
          type="email"
          autoComplete="off"
          value={email}
          onChange={setEmail}
          errors={problem?.fields.email}
        />
        {mayAssign ? (
          <fieldset className="flex flex-col gap-2">
            <legend className="text-sm font-medium text-slate-800">Access to give them</legend>
            {catalog.status === 'loading' ? (
              <p className="text-sm text-slate-600">Loading…</p>
            ) : null}
            {catalog.status === 'failed' ? (
              <Alert tone="error">The list of access roles could not be loaded.</Alert>
            ) : null}
            {catalog.status === 'loaded'
              ? catalog.value.map((entry) => (
                  <label key={entry.key} className="flex items-start gap-2 text-sm">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={chosen.includes(entry.key)}
                      onChange={(event) => {
                        setChosen((held) =>
                          event.target.checked
                            ? [...held, entry.key]
                            : held.filter((key) => key !== entry.key),
                        )
                      }}
                    />
                    <span>
                      <span className="font-medium">{entry.name}</span>
                      <span className="block text-slate-600">{entry.description}</span>
                    </span>
                  </label>
                ))
              : null}
            <p className="text-sm text-slate-600">
              You can leave all of these off. Without access to the Console they can set a password
              but cannot use it.
            </p>
          </fieldset>
        ) : null}
        <div className="flex gap-3">
          <SubmitButton pending={pending} pendingLabel="Sending…">
            Send invitation
          </SubmitButton>
          <Link to="/admin/accounts" className={secondaryButton}>
            Cancel
          </Link>
        </div>
      </form>
    </div>
  )
}
