import { useState } from 'react'

import {
  initialMembershipTerm,
  resolveMembershipTerm,
  type MembershipTermState,
} from '../../admin/membershipTerm.ts'
import { shown } from '../../admin/time.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { describeMembershipFailure, membershipSourceLabel } from '../../admin/wording.ts'
import {
  grantMembership,
  revokeMembershipGrant,
  type FieldErrors,
  type Member,
  type MembershipGrant,
  type MembershipSource,
} from '../../api/membership.ts'
import { Alert } from '../../ui/Alert.tsx'
import { dangerButton, secondaryButton } from '../../ui/classes.ts'
import type { ConfirmResult } from '../../ui/ConfirmDialog.tsx'
import { ConfirmDialog } from '../../ui/ConfirmDialog.tsx'
import { TextField } from '../../ui/TextField.tsx'
import { MembershipTermFields } from './MembershipTermFields.tsx'

/** A grant's own facts: what it means for the OVERALL record is derived server-side and shown above this section. */
function GrantRow({
  grant,
  mayManage,
  onRevoke,
}: {
  grant: MembershipGrant
  mayManage: boolean
  onRevoke: () => void
}) {
  const revoked = grant.revokedAt !== null
  return (
    <li className="flex flex-wrap items-start justify-between gap-3 rounded-md border border-slate-200 p-3">
      <div className="text-sm">
        <p className="font-medium">
          {shown(grant.startsAt)} – {grant.endsAt === null ? 'open-ended' : shown(grant.endsAt)}
        </p>
        <p className="text-slate-600">
          {membershipSourceLabel(grant.source)}
          {grant.sourceReference !== null && grant.sourceReference !== ''
            ? ` · Reference: ${grant.sourceReference}`
            : ''}
        </p>
        <p className={revoked ? 'font-medium text-red-800' : 'text-slate-500'}>
          {revoked ? `Revoked ${shown(grant.revokedAt)}` : 'Not revoked'}
        </p>
      </div>
      {mayManage && !revoked ? (
        <button type="button" className={dangerButton} onClick={onRevoke}>
          Revoke this grant
        </button>
      ) : null}
    </li>
  )
}

/**
 * The complete grant history (current, future, expired and revoked alike), and the two mutations a manager may make on
 * it: add another term (overlapping and touching terms are valid — nothing here refuses them, the derivation merges
 * them) and revoke one. Both re-fetch the authoritative record afterward rather than guessing the new derived state.
 */
export function MembershipGrantsSection({
  member,
  mayManage,
  refresh,
}: {
  member: Member
  mayManage: boolean
  refresh: () => Promise<void>
}) {
  const run = useAdminAction()
  const [adding, setAdding] = useState(false)
  const [term, setTerm] = useState<MembershipTermState>(initialMembershipTerm)
  const [source, setSource] = useState<MembershipSource>('operator')
  const [sourceReference, setSourceReference] = useState('')
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [confirmingGrant, setConfirmingGrant] = useState<{
    startsAt: string
    endsAt: string | null
  } | null>(null)
  const [revoking, setRevoking] = useState<MembershipGrant | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  function review() {
    const resolved = resolveMembershipTerm(term)
    if (!resolved.ok) {
      setFieldErrors({ [resolved.field]: [resolved.message] })
      return
    }
    setFieldErrors({})
    setConfirmingGrant({ startsAt: resolved.startsAt, endsAt: resolved.endsAt })
  }

  async function confirmGrant(): Promise<ConfirmResult> {
    if (confirmingGrant === null) return { kind: 'stay', tone: 'error', message: 'Nothing to add.' }
    const outcome = await run(() =>
      grantMembership(member.person.id, {
        startsAt: confirmingGrant.startsAt,
        endsAt: confirmingGrant.endsAt,
        source,
        sourceReference: sourceReference.trim() === '' ? null : sourceReference.trim(),
      }),
    )
    if (outcome.status === 'done') {
      setConfirmingGrant(null)
      setAdding(false)
      setTerm(initialMembershipTerm)
      setSourceReference('')
      setNotice('A membership grant was added.')
      void refresh()
      return { kind: 'done' }
    }
    if (outcome.status === 'verify') {
      return {
        kind: 'stay',
        tone: 'info',
        message: outcome.verified
          ? 'You are verified. Nothing has been added yet: confirm again to continue.'
          : 'Verification was cancelled. Nothing has been added.',
      }
    }
    return {
      kind: 'stay',
      tone: 'error',
      message: describeMembershipFailure(outcome.failure).message,
    }
  }

  async function confirmRevoke(): Promise<ConfirmResult> {
    if (revoking === null) return { kind: 'stay', tone: 'error', message: 'Nothing to revoke.' }
    const outcome = await run(() => revokeMembershipGrant(revoking.id))
    if (outcome.status === 'done') {
      setRevoking(null)
      setNotice('The grant has been revoked. Its record is kept, marked revoked.')
      void refresh()
      return { kind: 'done' }
    }
    if (outcome.status === 'verify') {
      return {
        kind: 'stay',
        tone: 'info',
        message: outcome.verified
          ? 'You are verified. Nothing has been changed yet: confirm again to continue.'
          : 'Verification was cancelled. Nothing has been changed.',
      }
    }
    if (outcome.failure.kind === 'conflict' && outcome.failure.code === 'grant_already_revoked') {
      // Someone else got there first (another tab, another operator). Pull in what is now true rather than guess.
      void refresh()
    }
    return {
      kind: 'stay',
      tone: 'error',
      message: describeMembershipFailure(outcome.failure).message,
    }
  }

  return (
    <section aria-labelledby="grants-heading" className="flex flex-col gap-3">
      <h2 id="grants-heading" className="text-lg font-medium">
        Grant history
      </h2>
      {notice ? (
        <Alert key={notice} tone="success">
          {notice}
        </Alert>
      ) : null}

      {member.grants.length === 0 ? (
        <p className="text-sm text-slate-600">No grants recorded.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {member.grants.map((grant) => (
            <GrantRow
              key={grant.id}
              grant={grant}
              mayManage={mayManage}
              onRevoke={() => {
                setNotice(null)
                setRevoking(grant)
              }}
            />
          ))}
        </ul>
      )}

      {mayManage ? (
        <div>
          {!adding ? (
            <button
              type="button"
              className={secondaryButton}
              onClick={() => {
                setNotice(null)
                setAdding(true)
              }}
            >
              Add grant
            </button>
          ) : (
            <div className="flex flex-col gap-3 rounded-md border border-slate-200 p-3">
              <MembershipTermFields value={term} onChange={setTerm} errors={fieldErrors} />
              <div className="flex flex-col gap-1">
                <label htmlFor="grant-source" className="text-sm font-medium text-slate-800">
                  Source
                </label>
                <select
                  id="grant-source"
                  value={source}
                  onChange={(event) => {
                    setSource(event.target.value as MembershipSource)
                  }}
                  className="w-fit rounded-md border border-slate-300 bg-white px-3 py-1.5 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900"
                >
                  <option value="operator">Operator</option>
                  <option value="luma_legacy">Luma legacy</option>
                </select>
              </div>
              <TextField
                label="Source reference"
                name="source_reference"
                autoComplete="off"
                value={sourceReference}
                onChange={setSourceReference}
                errors={fieldErrors.source_reference}
                hint="Optional, and never checked against anything."
                required={false}
                maxLength={191}
              />
              <div className="flex gap-3">
                <button type="button" className={secondaryButton} onClick={review}>
                  Review and add
                </button>
                <button
                  type="button"
                  className={secondaryButton}
                  onClick={() => {
                    setAdding(false)
                    setTerm(initialMembershipTerm)
                    setFieldErrors({})
                  }}
                >
                  Cancel
                </button>
              </div>
            </div>
          )}
        </div>
      ) : null}

      {confirmingGrant ? (
        <ConfirmDialog
          title={`Add a membership grant for ${member.person.displayName}?`}
          confirmLabel="Add grant"
          onCancel={() => {
            setConfirmingGrant(null)
          }}
          onConfirm={confirmGrant}
        >
          <p>
            {shown(confirmingGrant.startsAt)} –{' '}
            {confirmingGrant.endsAt === null ? 'open-ended' : shown(confirmingGrant.endsAt)}
          </p>
          <p>
            An overlapping or touching existing grant is not a problem: coverage is derived from all
            of them together.
          </p>
        </ConfirmDialog>
      ) : null}

      {revoking ? (
        <ConfirmDialog
          title={`Revoke this grant for ${member.person.displayName}?`}
          confirmLabel="Revoke grant"
          destructive
          onCancel={() => {
            setRevoking(null)
          }}
          onConfirm={confirmRevoke}
        >
          <p>
            {shown(revoking.startsAt)} –{' '}
            {revoking.endsAt === null ? 'open-ended' : shown(revoking.endsAt)} (
            {membershipSourceLabel(revoking.source)})
          </p>
          <p>
            This is permanent: a revoked grant cannot be un-revoked. Its record is kept, not
            deleted.
          </p>
        </ConfirmDialog>
      ) : null}
    </section>
  )
}
