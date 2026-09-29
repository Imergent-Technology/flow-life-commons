import { useCallback, useRef, useState, type SyntheticEvent } from 'react'

import {
  getCommonsAccess,
  inviteExistingPerson,
  type CommonsAccess,
  type InvitationOutcome,
} from '../../api/admin.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { describeAdminFailure } from '../../admin/wording.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { type Problem } from '../../ui/problem.ts'
import { Skeleton, SkeletonRegion } from '../../ui/Skeleton.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { TextField } from '../../ui/TextField.tsx'
import { useLoad } from '../../ui/useLoad.ts'

function statusLine(state: CommonsAccess['state']): string {
  switch (state) {
    case 'invited':
      return 'Invited: has not set a password yet.'
    case 'active':
      return 'Has an active Commons Account.'
    case 'disabled':
      return 'Has a Commons Account, currently disabled.'
    case 'not_invited':
      return '' // unreachable: the form renders instead, see below
  }
}

/**
 * Whether this Person has a Commons Account yet and, if not, the one action WP1 built for exactly this case:
 * invite them (`POST /admin/people/{person}/invitation`, ADR 0032, Work Package 5). A different backend
 * operation from "Invite an operator" (`InviteOperatorPage`): no new Person is created here, and no role is
 * assigned — an invited Member holds no capability by default.
 *
 * Self-fetches `GET /admin/people/{person}/commons-access` given only `personId`, the same composition
 * `AccountMembershipSection` already uses in the other direction (Membership data on the Account page):
 * Membership's own responses carry nothing about a Commons Account, ever — an invariant a backend test
 * enforces — so this is a genuinely separate read the Member detail page composes on screen, never a field
 * threaded through the Member record.
 */
export function InviteToCommonsSection({ personId }: { personId: string }) {
  const load = useCallback((signal: AbortSignal) => getCommonsAccess(personId, signal), [personId])
  const [loaded, replace] = useLoad(load)

  return (
    <Panel title="Commons Account">
      {loaded.status === 'loading' ? (
        <SkeletonRegion label="Loading…" visibleLabel>
          <Skeleton className="w-40" />
        </SkeletonRegion>
      ) : null}
      {loaded.status === 'failed' ? (
        <Alert tone="error">{describeAdminFailure(loaded.failure).message}</Alert>
      ) : null}
      {loaded.status === 'loaded' ? (
        <CommonsAccessContent
          personId={personId}
          access={loaded.value}
          onInvited={(next) => {
            replace(next)
          }}
        />
      ) : null}
    </Panel>
  )
}

function CommonsAccessContent({
  personId,
  access,
  onInvited,
}: {
  personId: string
  access: CommonsAccess
  onInvited: (next: CommonsAccess) => void
}) {
  const run = useAdminAction()
  const [email, setEmail] = useState('')
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [result, setResult] = useState<InvitationOutcome | null>(null)
  const emailRef = useRef<HTMLInputElement>(null)

  if (result === null && !access.canInvite) {
    return <p className="text-body text-foreground">{statusLine(access.state)}</p>
  }

  async function submit() {
    setPending(true)
    setNotice(null)
    const outcome = await run(() => inviteExistingPerson(personId, email))
    setPending(false)

    if (outcome.status === 'done') {
      setProblem(null)
      setResult(outcome.value)
      onInvited({ state: 'invited', canInvite: false })
      return
    }
    if (outcome.status === 'verify') {
      setNotice(
        outcome.verified
          ? 'You are verified. Nothing has been sent yet: press “Invite to Commons” again to continue.'
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
    return sent ? (
      <Alert tone="success" focusOnMount>
        Invited. The invitation went to <strong>{result.account.email}</strong>; it works once. They
        set their password from the link in it.
      </Alert>
    ) : (
      <Alert tone="error" focusOnMount>
        The Account was created, but the invitation email could not be sent. Reload to try sending
        it again.
      </Alert>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <p className="text-body text-muted-foreground">
        This person has no Commons Account yet. Invite them to set a password and sign in.
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
        aria-label="Invite to Commons"
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
          autoComplete="off"
          value={email}
          onChange={setEmail}
          errors={problem?.fields.email}
        />
        <SubmitButton pending={pending} pendingLabel="Sending…">
          Invite to Commons
        </SubmitButton>
      </form>
    </div>
  )
}
