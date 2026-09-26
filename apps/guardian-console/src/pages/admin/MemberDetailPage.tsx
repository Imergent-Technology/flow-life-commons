import { useCallback } from 'react'
import { useParams } from 'react-router'

import { getMember } from '../../api/membership.ts'
import { useLoad } from '../../admin/useLoad.ts'
import { accessThroughLabel, describeMembershipFailure } from '../../admin/wording.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, MEMBERSHIP_MANAGE } from '../../auth/capabilities.ts'
import { useBreadcrumbLeaf } from '../../shell/breadcrumb-leaf.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { TextLink } from '../../ui/TextLink.tsx'
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
  useBreadcrumbLeaf(loaded.status === 'loaded' ? loaded.value.person.displayName : undefined)

  const refresh = useCallback(async () => {
    const result = await getMember(personId)
    if (result.ok) replace(result.value)
  }, [personId, replace])

  if (loaded.status === 'loading') {
    return (
      <Page width="detail">
        <SkeletonRegion label="Loading member…" visibleLabel>
          <SkeletonText lines={4} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (loaded.status === 'failed') {
    return (
      <Page width="prose">
        <PageHeader title="Member" />
        <Alert tone="error">{describeMembershipFailure(loaded.failure).message}</Alert>
        <TextLink to="/admin/members" className="self-start">
          Back to members
        </TextLink>
      </Page>
    )
  }

  const member = loaded.value

  return (
    <Page width="detail">
      <PageHeader
        title={member.person.displayName}
        status={<MembershipStateBadge active={member.active} />}
        description={accessThroughLabel(member)}
      />

      <MembershipGrantsSection
        member={member}
        mayManage={hasCapability(current, MEMBERSHIP_MANAGE)}
        refresh={refresh}
      />
    </Page>
  )
}
