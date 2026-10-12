import { type ReactNode } from 'react'
import { useParams } from 'react-router'

import type { RelationshipType } from '../../api/relationships.ts'
import { useRelationshipTypes } from '../../relationships/relationship-types-context.ts'
import { describeRelationshipFailure } from '../../relationships/wording.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { NotFoundPage } from '../NotFoundPage.tsx'

type Needs = 'view' | 'manage' | 'either'

const allows = (type: RelationshipType, needs: Needs): boolean =>
  needs === 'view'
    ? type.actions.view
    : needs === 'manage'
      ? type.actions.manage
      : type.actions.view || type.actions.manage

/**
 * The guard of every `/relationships/:slug*` route. The type comes from the SERVED definitions: a slug that is not among the
 * types this operator may view or manage is a page that is not found, which says nothing about whether such a type exists.
 * A type they know, but whose action this route needs is not theirs, is a plain "Not permitted" inside the Console (the
 * same presentation as `RequireCapability`). This chooses what to PRESENT: the server refuses every request on its own
 * account, whatever this shows, and a manager without the view capability reaches intake and the management view and never
 * the directory or the history.
 */
export function RelationshipRoute({
  needs,
  children,
}: {
  needs: Needs
  children: (type: RelationshipType) => ReactNode
}) {
  const { slug = '' } = useParams()
  const { state, reload } = useRelationshipTypes()

  if (state.status === 'loading') {
    return (
      <Page width="detail">
        <SkeletonRegion label="Loading…" visibleLabel>
          <SkeletonText lines={3} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (state.status === 'failed') {
    return (
      <Page width="prose">
        <PageHeader title="Relationships" />
        <Alert tone="error">{describeRelationshipFailure(state.failure).message}</Alert>
        <Button className="self-start" onClick={reload}>
          Try again
        </Button>
      </Page>
    )
  }

  const type = state.types.find((candidate) => candidate.slug === slug)
  if (type === undefined) return <NotFoundPage />
  if (!allows(type, needs)) {
    return (
      <Page width="prose">
        <PageHeader
          title="Not permitted"
          description="Your account cannot use this part of the Console."
        />
      </Page>
    )
  }
  return <>{children(type)}</>
}
