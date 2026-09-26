import { useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { initialMembershipTerm, resolveMembershipTerm } from '../../admin/membershipTerm.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { describeMembershipFailure } from '../../admin/wording.ts'
import { registerMember, type Member, type MembershipSource } from '../../api/membership.ts'
import type { FieldErrors } from '../../api/http.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Button } from '../../ui/Button.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { type Problem } from '../../ui/problem.ts'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { TextField } from '../../ui/TextField.tsx'
import { MembershipSourceField } from './MembershipSourceField.tsx'
import { MembershipTermFields } from './MembershipTermFields.tsx'

/**
 * Creates a NEW Person record with no Account, invitation, login or role, and grants them their first membership access
 * in the same atomic step (ADR 0028). This is for someone the platform has never known before; it is not how an
 * existing Account holder gets membership (see the "Grant membership access" section on their Account).
 */
export function RegisterMemberPage() {
  const run = useAdminAction()
  const [displayName, setDisplayName] = useState('')
  const [term, setTerm] = useState(initialMembershipTerm)
  const [source, setSource] = useState<MembershipSource>('operator')
  const [sourceReference, setSourceReference] = useState('')
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [result, setResult] = useState<Member | null>(null)

  function fieldProblem(field: 'starts_at' | 'ends_at', message: string) {
    const fields: FieldErrors = { [field]: [message] }
    setProblem((previous) => ({ message, fields, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  async function submit() {
    setNotice(null)
    const resolved = resolveMembershipTerm(term)
    if (!resolved.ok) {
      fieldProblem(resolved.field, resolved.message)
      return
    }

    setPending(true)
    const outcome = await run(() =>
      registerMember({
        displayName,
        term: resolved.term,
        source,
        sourceReference: sourceReference.trim() === '' ? null : sourceReference.trim(),
      }),
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
          ? 'You are verified. Nothing has been added yet: press “Add member” again to continue.'
          : 'Verification was cancelled. Nothing has been added.',
      )
      return
    }
    if (outcome.failure.kind === 'unauthenticated') return // the session ended; the boundary handles it
    const next = describeMembershipFailure(outcome.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  if (result !== null) {
    return (
      <Page width="form">
        <PageHeader title="Member added" />
        <Alert tone="success" focusOnMount>
          {result.person.displayName} was added as a new Person, with an initial membership grant.
        </Alert>
        <div className="flex flex-wrap gap-3">
          <Link
            to={`/admin/members/${result.person.id}`}
            className={buttonVariants({ variant: 'secondary' })}
          >
            Open the member
          </Link>
          <Button
            onClick={() => {
              setResult(null)
              setDisplayName('')
              setTerm(initialMembershipTerm)
              setSource('operator')
              setSourceReference('')
            }}
          >
            Add another member
          </Button>
        </div>
      </Page>
    )
  }

  return (
    <Page width="form">
      <PageHeader
        title="Add a new member"
        description="This creates a new Person record and grants them membership access. It does not create a Console account, an invitation or any way to sign in."
      />
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
      <Panel>
        <form
          aria-label="Add a new member"
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
            value={displayName}
            onChange={setDisplayName}
            errors={problem?.fields.display_name}
            maxLength={255}
          />
          <MembershipTermFields value={term} onChange={setTerm} errors={problem?.fields} />
          <MembershipSourceField value={source} onChange={setSource} />
          <TextField
            label="Source reference"
            name="source_reference"
            autoComplete="off"
            value={sourceReference}
            onChange={setSourceReference}
            errors={problem?.fields.source_reference}
            hint="Optional. For Luma legacy reconciliation, an opaque reference you recognise. Never checked against anything."
            required={false}
            maxLength={191}
          />
          <div className="flex flex-wrap gap-3">
            <SubmitButton pending={pending} pendingLabel="Adding…">
              Add member
            </SubmitButton>
            <Link to="/admin/members" className={buttonVariants({ variant: 'secondary' })}>
              Cancel
            </Link>
          </div>
        </form>
      </Panel>
    </Page>
  )
}
