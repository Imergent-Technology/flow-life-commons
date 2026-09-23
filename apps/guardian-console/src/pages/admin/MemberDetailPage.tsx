import { useCallback } from 'react'
import { Link, useParams } from 'react-router'

import { getMember } from '../../api/membership.ts'
import { useLoad } from '../../admin/useLoad.ts'
import { accessThroughLabel, describeMembershipFailure } from '../../admin/wording.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, MEMBERSHIP_MANAGE } from '../../auth/capabilities.ts'
import { Alert } from '../../ui/Alert.tsx'
import { PageHeading } from '../../ui/PageHeading.tsx'
import { MembershipStateBadge } from './MembershipStateBadge.tsx'
import { MembershipGrantsSection } from './MembershipGrantsSection.tsx'

/**
 * One Person's membership record: their current, derived state and their complete grant history. `404` covers both a
 * Person who does not exist and one who exists but has never held a grant — a bare Person is not yet a membership
 * record (ADR 0028, Work Package 5's Phase-1 choice).
 */
export function MemberDetailPage() {
  const current = useCurrentAccount()
  const { personId = '' } = useParams()
  const load = useCallback((signal: AbortSignal) => getMember(personId, signal), [personId])
  const [loaded, replace] = useLoad(load)

  const refresh = useCallback(async () => {
    const result = await getMember(personId)
    if (result.ok) replace(result.value)
  }, [personId, replace])

  if (loaded.status === 'loading') {
    return (
      <p role="status" className="text-slate-600">
        Loading member…
      </p>
    )
  }
  if (loaded.status === 'failed') {
    return (
      <div className="flex max-w-md flex-col gap-3">
        <PageHeading title="Member" />
        <Alert tone="error">{describeMembershipFailure(loaded.failure).message}</Alert>
        <Link to="/admin/members" className="text-sm underline">
          Back to members
        </Link>
      </div>
    )
  }

  const member = loaded.value

  return (
    <div className="flex max-w-2xl flex-col gap-8">
      <header className="flex flex-col gap-2">
        <Link to="/admin/members" className="text-sm underline">
          ← Members
        </Link>
        <div className="flex flex-wrap items-center gap-3">
          <PageHeading title={member.person.displayName} />
          <MembershipStateBadge active={member.active} />
        </div>
        <p className="text-sm text-slate-700">{accessThroughLabel(member)}</p>
      </header>

      <MembershipGrantsSection
        member={member}
        mayManage={hasCapability(current, MEMBERSHIP_MANAGE)}
        refresh={refresh}
      />
    </div>
  )
}
