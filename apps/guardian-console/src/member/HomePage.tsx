import { useCallback } from 'react'

import { getCurrentMembership } from '../api/myMembership.ts'
import { useCurrentAccount } from '../auth/auth-context.ts'
import { Alert } from '../ui/Alert.tsx'
import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { Panel } from '../ui/Panel.tsx'
import { describeFailure } from '../ui/problem.ts'
import { SessionSummary } from '../ui/SessionSummary.tsx'
import { SkeletonRegion, SkeletonText } from '../ui/Skeleton.tsx'
import { TextLink } from '../ui/TextLink.tsx'
import { useLoad } from '../ui/useLoad.ts'
import { accessThroughLabel } from './wording.ts'
import { MembershipStateBadge } from './MembershipStateBadge.tsx'

/**
 * `/my/`: a modest authenticated self-service home (ADR 0032, Work Package 4). Nothing here is a new fact
 * the platform did not already know: a greeting from `/me`, the session `/me` already reports, and a
 * summary of the SAME `GET /my/membership` answer the Membership page shows in full. Reaching this page is
 * not evidence of active membership — the summary is exactly as truthful about "not currently active" as
 * the full history page is.
 */
export function HomePage() {
  const current = useCurrentAccount()
  const load = useCallback((signal: AbortSignal) => getCurrentMembership(signal), [])
  const [loaded] = useLoad(load)

  return (
    <Page width="form">
      <PageHeader title="Home" description={`Signed in as ${current.person.display_name}.`} />

      <Panel title="Your session">
        <SessionSummary />
      </Panel>

      <Panel title="Membership">
        <div className="flex flex-col gap-3">
          {loaded.status === 'loading' ? (
            <SkeletonRegion label="Loading your membership…">
              <SkeletonText lines={2} />
            </SkeletonRegion>
          ) : loaded.status === 'failed' ? (
            <Alert tone="error">{describeFailure(loaded.failure).message}</Alert>
          ) : (
            <div className="flex flex-wrap items-center gap-2">
              <MembershipStateBadge active={loaded.value.active} />
              <span className="text-body text-muted-foreground">
                {accessThroughLabel(loaded.value)}
              </span>
            </div>
          )}
          <TextLink to="/my/membership" className="self-start">
            See your membership history
          </TextLink>
        </div>
      </Panel>

      <Panel title="Security">
        <TextLink to="/my/security" className="self-start">
          Manage your password and two-step verification
        </TextLink>
      </Panel>
    </Page>
  )
}
