import { useCallback, useState } from 'react'
import { useParams } from 'react-router'

import { describeDiscussionsFailure } from '../../admin/discussionsWording.ts'
import { getDiscussion } from '../../api/discussions.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { DISCUSSIONS_PARTICIPATE, hasCapability } from '../../auth/capabilities.ts'
import { useBreadcrumbLeaf } from '../../shell/breadcrumb-leaf.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { TextLink } from '../../ui/TextLink.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { DiscussionHeader } from './DiscussionHeader.tsx'
import { ThreadMessages } from './ThreadMessages.tsx'

/**
 * One discussion (ADR 0035): its title and state, then its messages oldest first as a flat thread, then, for someone who may
 * take part and only while it is open, a place to reply. What a person may CHANGE follows `discussions.participate` AND
 * ownership: their own messages, and the title of a discussion they started. Neither is inferred from a name: it is the
 * signed-in Person's id against the author's. The server decides every request; this only chooses what to show.
 */
export function DiscussionPage() {
  const current = useCurrentAccount()
  const { discussionId = '' } = useParams()
  const load = useCallback(
    (signal: AbortSignal) => getDiscussion(discussionId, signal),
    [discussionId],
  )
  const [loaded, replace] = useLoad(load)
  // An unsent reply outlives the form: if the discussion is resolved under the writer, the words are still theirs.
  const [draft, setDraft] = useState('')
  useBreadcrumbLeaf(loaded.status === 'loaded' ? loaded.value.title : undefined)

  // Re-reads the header, for when something (a conflict, a resolution) may have changed what is true about it.
  const refresh = useCallback(async () => {
    const result = await getDiscussion(discussionId)
    if (result.ok) replace(result.value)
  }, [discussionId, replace])

  if (loaded.status === 'loading') {
    return (
      <Page width="detail">
        <SkeletonRegion label="Loading discussion…" visibleLabel>
          <SkeletonText lines={4} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (loaded.status === 'failed') {
    return (
      <Page width="prose">
        <PageHeader title="Discussion" />
        <Alert tone="error">{describeDiscussionsFailure(loaded.failure).message}</Alert>
        <TextLink to="/discussions" className="self-start">
          Back to discussions
        </TextLink>
      </Page>
    )
  }

  const discussion = loaded.value
  const mayParticipate = hasCapability(current, DISCUSSIONS_PARTICIPATE)

  return (
    <Page width="detail">
      <DiscussionHeader
        discussion={discussion}
        mayParticipate={mayParticipate}
        isCreator={discussion.creator?.id === current.person.id}
        onChange={replace}
      />
      <ThreadMessages
        discussion={discussion}
        mayParticipate={mayParticipate}
        me={current.person.id}
        refresh={refresh}
        draft={draft}
        onDraft={setDraft}
      />
    </Page>
  )
}
