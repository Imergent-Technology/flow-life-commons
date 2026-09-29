import { useCallback } from 'react'

import { getCurrentMembership, type CurrentMembershipGrant } from '../api/myMembership.ts'
import { Alert } from '../ui/Alert.tsx'
import { Badge } from '../ui/Badge.tsx'
import { EmptyState } from '../ui/EmptyState.tsx'
import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { Panel } from '../ui/Panel.tsx'
import { describeFailure } from '../ui/problem.ts'
import { SkeletonRegion, SkeletonText } from '../ui/Skeleton.tsx'
import { useLoad } from '../ui/useLoad.ts'
import { MembershipStateBadge } from './MembershipStateBadge.tsx'
import { accessThroughLabel, formatDate } from './wording.ts'

/**
 * One grant in the history: its own term and, if it was revoked, that fact — nothing else. The backend
 * DTO already carries no id, source or granting/revoking Account, so there is nothing further to hide.
 */
function GrantRow({ grant }: { grant: CurrentMembershipGrant }) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 border-b border-border py-2.5 last:border-b-0">
      <span className="text-body text-foreground">
        <time dateTime={grant.startsAt}>{formatDate(grant.startsAt)}</time>
        {' – '}
        {grant.endsAt === null ? (
          <span>Open-ended</span>
        ) : (
          <time dateTime={grant.endsAt}>{formatDate(grant.endsAt)}</time>
        )}
      </span>
      {grant.revoked ? <Badge variant="danger">Revoked</Badge> : null}
    </li>
  )
}

/**
 * `/my/membership`: the signed-in Account's own membership state and complete grant history
 * (`GET /api/v1/my/membership`, ADR 0032, Work Package 2). Every outcome here — active, lapsed, future-only
 * or never a member at all — is presented as a normal, successful answer: this page never tells someone
 * they are not really asking, and it never recomputes "active" itself. That word, `open_ended` and
 * `current_access_ends_at` come from the server and are shown exactly as given.
 */
export function MembershipPage() {
  const load = useCallback((signal: AbortSignal) => getCurrentMembership(signal), [])
  const [loaded] = useLoad(load)

  if (loaded.status === 'loading') {
    return (
      <Page width="form">
        <SkeletonRegion label="Loading your membership…" visibleLabel>
          <SkeletonText lines={3} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (loaded.status === 'failed') {
    return (
      <Page width="form">
        <PageHeader title="Membership" />
        <Alert tone="error">{describeFailure(loaded.failure).message}</Alert>
      </Page>
    )
  }

  const membership = loaded.value
  // Most recent first: a display choice made here, not a re-derivation of which grant is "current" —
  // the server's own active/open_ended/current_access_ends_at fields above are untouched by it.
  const grants = [...membership.grants].reverse()

  return (
    <Page width="form">
      <PageHeader
        title="Membership"
        status={<MembershipStateBadge active={membership.active} />}
        description={accessThroughLabel(membership)}
      />
      <Panel title="History">
        {grants.length === 0 ? (
          <EmptyState title="No membership history yet">
            There is no membership grant on record for your account.
          </EmptyState>
        ) : (
          <ul className="flex flex-col">
            {grants.map((grant, index) => (
              <GrantRow key={index} grant={grant} />
            ))}
          </ul>
        )}
      </Panel>
    </Page>
  )
}
