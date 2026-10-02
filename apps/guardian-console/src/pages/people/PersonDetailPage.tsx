import { useCallback } from 'react'
import { useParams } from 'react-router'

import { getPerson } from '../../api/people.ts'
import { describePeopleFailure } from '../../admin/peopleWording.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, PEOPLE_MANAGE } from '../../auth/capabilities.ts'
import { useBreadcrumbLeaf } from '../../shell/breadcrumb-leaf.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { TextLink } from '../../ui/TextLink.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { ContactMethodsSection } from './ContactMethodsSection.tsx'
import { ProfileSection } from './ProfileSection.tsx'

/**
 * One Person as CRM knows them: their name, the profile CRM holds about them, and their contact methods (ADR 0034). Nothing
 * about an Account, access, Membership or security belongs here: those have their own pages, behind their own capabilities.
 * What a Guardian may CHANGE follows `crm.people.manage`, which is separate from the `crm.people.view` that got them here.
 */
export function PersonDetailPage() {
  const current = useCurrentAccount()
  const { personId = '' } = useParams()
  const load = useCallback((signal: AbortSignal) => getPerson(personId, signal), [personId])
  const [loaded, replace] = useLoad(load)
  useBreadcrumbLeaf(loaded.status === 'loaded' ? loaded.value.person.displayName : undefined)

  // Every change re-reads the record: a contact method's primary flag, in particular, moves between rows.
  const refresh = useCallback(async () => {
    const result = await getPerson(personId)
    if (result.ok) replace(result.value)
  }, [personId, replace])

  if (loaded.status === 'loading') {
    return (
      <Page width="detail">
        <SkeletonRegion label="Loading person…" visibleLabel>
          <SkeletonText lines={4} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (loaded.status === 'failed') {
    return (
      <Page width="prose">
        <PageHeader title="Person" />
        <Alert tone="error">{describePeopleFailure(loaded.failure).message}</Alert>
        <TextLink to="/people" className="self-start">
          Back to people
        </TextLink>
      </Page>
    )
  }

  const record = loaded.value
  const mayManage = hasCapability(current, PEOPLE_MANAGE)

  return (
    <Page width="detail">
      <PageHeader title={record.person.displayName} />
      <ProfileSection record={record} mayManage={mayManage} refresh={refresh} />
      <ContactMethodsSection record={record} mayManage={mayManage} refresh={refresh} />
    </Page>
  )
}
