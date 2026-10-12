import { useNavigate } from 'react-router'

import type { RelationshipType } from '../api/relationships.ts'
import { useCurrentAccount } from '../auth/auth-context.ts'
import { hasCapability, PEOPLE_VIEW } from '../auth/capabilities.ts'
import { useNotice } from '../pages/resources/useNotice.ts'
import { useBreadcrumbLeaf } from '../shell/breadcrumb-leaf.ts'
import { Alert } from '../ui/Alert.tsx'
import { buttonVariants } from '../ui/button-variants.ts'
import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { SkeletonRegion, SkeletonText } from '../ui/Skeleton.tsx'
import { TextLink } from '../ui/TextLink.tsx'
import { Link } from 'react-router'
import { personName, UNNAMED_PERSON } from './definitions.ts'
import { RelationshipPanel } from './RelationshipPanel.tsx'
import { describeUnreadable } from './unreadable.ts'
import { RelationshipStatusBadge } from './RelationshipStatusBadge.tsx'
import { useRelationshipData } from './useRelationshipData.ts'

/**
 * One person's relationship of one type, as a page (ADR 0038, C4). The reads it makes follow the operator's capabilities,
 * and neither implies the other: a manager who cannot view reads the management view and never asks for the record, history
 * or directory. A person with no relationship of this type is "not recorded", which says nothing about any other type.
 *
 * "Open full record" appears only for an operator who holds the People view capability. Everything else about the person
 * that a relationship manager needs is in this page.
 */
export function RelationshipRecord({
  type,
  personId,
}: {
  type: RelationshipType
  personId: string
}) {
  const current = useCurrentAccount()
  const navigate = useNavigate()
  const notice = useNotice()
  const data = useRelationshipData(type, personId)
  const person = data.management?.person ?? data.view?.person ?? null
  useBreadcrumbLeaf(person === null ? undefined : personName(person))

  // Back to somewhere this operator can actually go: the directory if they may view it, otherwise the way to record another.
  const home = type.actions.view ? `/relationships/${type.slug}` : `/relationships/${type.slug}/new`
  const homeLabel = type.actions.view
    ? `Back to ${type.labels.plural.toLowerCase()}`
    : 'Record someone'

  if (data.status === 'loading') {
    return (
      <Page width="detail">
        <SkeletonRegion label={`Loading ${type.labels.singular.toLowerCase()}…`} visibleLabel>
          <SkeletonText lines={4} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (data.status === 'not-found' || data.status === 'failed') {
    return (
      <Page width="prose">
        <PageHeader title={type.labels.singular} />
        {data.conflict?.kind === 'gone' ? (
          <Alert tone="warning">
            This {type.labels.singular.toLowerCase()} no longer exists: it was deleted. Nothing you
            submitted was applied.
          </Alert>
        ) : (
          <Alert tone="error">{describeUnreadable(data)}</Alert>
        )}
        <TextLink to={home} className="self-start">
          {homeLabel}
        </TextLink>
      </Page>
    )
  }

  const status = data.management?.status ?? data.view?.status.key ?? null

  return (
    <Page width="detail">
      <PageHeader
        title={person === null ? UNNAMED_PERSON : personName(person)}
        description={type.labels.singular}
        status={
          status === null ? undefined : <RelationshipStatusBadge type={type} status={status} />
        }
        action={
          hasCapability(current, PEOPLE_VIEW) && person !== null ? (
            <Link to={`/people/${person.id}`} className={buttonVariants({ variant: 'secondary' })}>
              Open full record
            </Link>
          ) : undefined
        }
      />
      {notice ? <Alert tone="success">{notice}</Alert> : null}
      <RelationshipPanel
        data={data}
        onDeleted={() => {
          const who = person === null ? 'The' : `${personName(person)}'s`
          void navigate(home, {
            state: {
              notice: `${who} ${type.labels.singular.toLowerCase()} was deleted permanently.`,
            },
          })
        }}
      />
    </Page>
  )
}
