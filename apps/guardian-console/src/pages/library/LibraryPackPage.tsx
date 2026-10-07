import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams, useSearchParams } from 'react-router'

import { getLibraryPack, type DeliveredPack } from '../../api/resourceLibrary.ts'
import { useBreadcrumbLeaf } from '../../shell/breadcrumb-leaf.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { DetailLayout, Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { TextLink } from '../../ui/TextLink.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { CardNavigation, SeriesControls } from './CardNavigation.tsx'
import { LibraryCardBody } from './LibraryCardBody.tsx'
import { describeLibraryFailure, NOT_FOUND_MESSAGE } from './wording.ts'

/**
 * One Resource Pack as a Guardian may read it (ADR 0037, decisions 45-47). The Pack arrives whole from the library endpoint, with
 * only the Cards this viewer may see, so choosing a Card is immediate and asks for nothing more. Whether it is read simply or
 * browsed is decided by what arrived: one Card is shown plainly, with no browsing controls; more than one gets direct navigation by
 * title, and a Series also gets Previous and Next. Nothing here can tell that another Card exists.
 *
 * The Card being read is in the address (`?card=`), so refresh, Back and Forward and a shared link all keep their place. A Card
 * that is not among those delivered is not an error and says nothing about itself: the first Card is read instead. A Pack that is
 * not available to this viewer, for whatever reason, is one ordinary "could not be found".
 */
export function LibraryPackPage() {
  const { packId = '' } = useParams()
  // Keyed by the Pack: moving to another one starts fresh.
  return <PackView key={packId} packId={packId} />
}

function PackView({ packId }: { packId: string }) {
  const [attempt, setAttempt] = useState(0)
  const load = useCallback(
    (signal: AbortSignal) => getLibraryPack(packId, signal),
    // `attempt` is not read: a new load function is how "Try again" asks again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [packId, attempt],
  )
  const [pack] = useLoad(load)
  useBreadcrumbLeaf(pack.status === 'loaded' ? pack.value.title : undefined)

  if (pack.status === 'loading') {
    return (
      <Page width="detail">
        <SkeletonRegion label="Loading the Resource…" visibleLabel>
          <SkeletonText lines={5} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (pack.status === 'failed') {
    const notFound = pack.failure.kind === 'not-found'
    return (
      <Page width="prose">
        <PageHeader
          title={notFound ? 'Resource not found' : 'Resource'}
          {...(notFound && { description: NOT_FOUND_MESSAGE })}
        />
        {notFound ? null : (
          <Alert tone="error">{describeLibraryFailure(pack.failure).message}</Alert>
        )}
        <div className="flex flex-wrap items-center gap-3">
          {notFound ? null : (
            <Button
              onClick={() => {
                setAttempt((n) => n + 1)
              }}
            >
              Try again
            </Button>
          )}
          <TextLink to="/resource-library">Back to the Resource Library</TextLink>
        </div>
      </Page>
    )
  }

  return <PackReader pack={pack.value} />
}

function PackReader({ pack }: { pack: DeliveredPack }) {
  const [params] = useSearchParams()
  const { cards } = pack
  const requested = params.get('card')
  const first = cards[0]
  // A Card that was not delivered is not found and not explained: the first one is read.
  const current = cards.find((card) => card.id === requested) ?? first
  const several = cards.length > 1

  // Moving to another Card puts focus on its title, so the change is announced and the person is where the new Card begins. Not on
  // arrival (the page's own heading has focus then).
  const titleRef = useRef<HTMLHeadingElement>(null)
  const shown = useRef(current?.id)
  useEffect(() => {
    if (current === undefined || shown.current === current.id) return
    shown.current = current.id
    titleRef.current?.focus()
  }, [current])

  if (current === undefined) return null

  // A single Card whose title is the Pack's says nothing more as a second heading.
  const sameTitle = current.title.trim().toLowerCase() === pack.title.trim().toLowerCase()
  const showTitle = several || !sameTitle

  const reading = (
    <article
      {...(showTitle
        ? { 'aria-labelledby': `card-${current.id}` }
        : { 'aria-label': current.title })}
      className="flex flex-col gap-4"
    >
      {showTitle ? (
        <h2
          id={`card-${current.id}`}
          ref={titleRef}
          tabIndex={-1}
          className="font-display text-section font-medium wrap-anywhere text-foreground outline-none"
        >
          {current.title}
        </h2>
      ) : null}
      <LibraryCardBody key={current.id} card={current} />
      {several && pack.isSeries ? <SeriesControls cards={cards} currentId={current.id} /> : null}
    </article>
  )

  return (
    <Page width="detail">
      <PageHeader
        title={pack.title}
        description={
          <>
            {pack.summary !== null && pack.summary !== '' ? (
              <span className="block wrap-anywhere">{pack.summary}</span>
            ) : null}
            <span className="block text-meta wrap-anywhere">In {pack.category.name}</span>
          </>
        }
      />
      {several ? (
        <DetailLayout aside={<CardNavigation cards={cards} currentId={current.id} />}>
          {reading}
        </DetailLayout>
      ) : (
        reading
      )}
    </Page>
  )
}
