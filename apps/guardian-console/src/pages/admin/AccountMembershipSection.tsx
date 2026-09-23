import { useCallback, useState } from 'react'
import { Link } from 'react-router'

import { initialMembershipTerm, resolveMembershipTerm } from '../../admin/membershipTerm.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { useLoad } from '../../admin/useLoad.ts'
import { accessThroughLabel, describeMembershipFailure } from '../../admin/wording.ts'
import type { ManagedAccount } from '../../api/admin.ts'
import {
  getMember,
  grantMembership,
  type FieldErrors,
  type MembershipSource,
} from '../../api/membership.ts'
import { Alert } from '../../ui/Alert.tsx'
import { secondaryButton } from '../../ui/classes.ts'
import { TextField } from '../../ui/TextField.tsx'
import { MembershipStateBadge } from './MembershipStateBadge.tsx'
import { MembershipTermFields } from './MembershipTermFields.tsx'

/**
 * Membership Foundation Phase 1 has no general Person search or contact model yet, so there is no page that lets an
 * operator pick an arbitrary existing Person to grant membership to. This Account already IS one legitimate, existing
 * path to a Person id (an Account always has exactly one, ADR 0015) — so this offers "Grant membership access" from
 * here, using the ordinary Membership API, rather than inventing a People-search surface this package is not scoped to
 * build. It does not create a new Person: `RegisterMemberPage` is where that happens, deliberately, distinctly.
 */
export function AccountMembershipSection({
  account,
  mayView,
  mayManage,
}: {
  account: ManagedAccount
  mayView: boolean
  mayManage: boolean
}) {
  const load = useCallback(
    (signal: AbortSignal) => getMember(account.personId, signal),
    [account.personId],
  )
  const [loaded, replace] = useLoad(load)
  const run = useAdminAction()
  const [adding, setAdding] = useState(false)
  const [term, setTerm] = useState(initialMembershipTerm)
  const [source, setSource] = useState<MembershipSource>('operator')
  const [sourceReference, setSourceReference] = useState('')
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [problem, setProblem] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  if (!mayView) return null

  async function submitGrant() {
    setProblem(null)
    setNotice(null)
    const resolved = resolveMembershipTerm(term)
    if (!resolved.ok) {
      setFieldErrors({ [resolved.field]: [resolved.message] })
      return
    }
    setFieldErrors({})

    const outcome = await run(() =>
      grantMembership(account.personId, {
        startsAt: resolved.startsAt,
        endsAt: resolved.endsAt,
        source,
        sourceReference: sourceReference.trim() === '' ? null : sourceReference.trim(),
      }),
    )
    if (outcome.status === 'done') {
      setAdding(false)
      setNotice('Membership access was granted.')
      const refreshed = await getMember(account.personId)
      if (refreshed.ok) replace(refreshed.value)
      return
    }
    if (outcome.status === 'verify') {
      setNotice(
        outcome.verified
          ? 'You are verified. Nothing has been added yet: press “Grant access” again to continue.'
          : 'Verification was cancelled. Nothing has been added.',
      )
      return
    }
    setProblem(describeMembershipFailure(outcome.failure).message)
  }

  return (
    <section aria-labelledby="membership-heading" className="flex flex-col gap-3">
      <h2 id="membership-heading" className="text-lg font-medium">
        Membership
      </h2>
      {notice ? (
        <Alert key={notice} tone="success">
          {notice}
        </Alert>
      ) : null}

      {loaded.status === 'loading' ? <p className="text-sm text-slate-600">Loading…</p> : null}

      {loaded.status === 'loaded' ? (
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <MembershipStateBadge active={loaded.value.active} />
          <span>{accessThroughLabel(loaded.value)}</span>
          <Link to={`/admin/members/${account.personId}`} className="underline">
            View membership record
          </Link>
        </div>
      ) : null}

      {loaded.status === 'failed' && loaded.failure.kind === 'not-found' ? (
        <>
          <p className="text-sm text-slate-600">They hold no membership record.</p>
          {mayManage ? (
            <div>
              {!adding ? (
                <button
                  type="button"
                  className={secondaryButton}
                  onClick={() => {
                    setAdding(true)
                  }}
                >
                  Grant membership access
                </button>
              ) : (
                <div className="flex flex-col gap-3 rounded-md border border-slate-200 p-3">
                  {problem !== null ? <Alert tone="error">{problem}</Alert> : null}
                  <MembershipTermFields value={term} onChange={setTerm} errors={fieldErrors} />
                  <div className="flex flex-col gap-1">
                    <label
                      htmlFor="account-membership-source"
                      className="text-sm font-medium text-slate-800"
                    >
                      Source
                    </label>
                    <select
                      id="account-membership-source"
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
                    required={false}
                    maxLength={191}
                  />
                  <div className="flex gap-3">
                    <button
                      type="button"
                      className={secondaryButton}
                      onClick={() => {
                        void submitGrant()
                      }}
                    >
                      Grant access
                    </button>
                    <button
                      type="button"
                      className={secondaryButton}
                      onClick={() => {
                        setAdding(false)
                        setTerm(initialMembershipTerm)
                        setFieldErrors({})
                        setProblem(null)
                      }}
                    >
                      Cancel
                    </button>
                  </div>
                </div>
              )}
            </div>
          ) : null}
        </>
      ) : null}

      {loaded.status === 'failed' && loaded.failure.kind !== 'not-found' ? (
        <Alert tone="error">{describeMembershipFailure(loaded.failure).message}</Alert>
      ) : null}
    </section>
  )
}
