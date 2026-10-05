import { useCallback, useState } from 'react'
import { Link, useParams } from 'react-router'

import { describeResourcesFailure } from '../../admin/resourcesWording.ts'
import { getCard, getPack } from '../../api/resources.ts'
import { useBreadcrumbLeaf } from '../../shell/breadcrumb-leaf.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Button } from '../../ui/Button.tsx'
import { DetailLayout, Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { StateBadge, TypeBadge } from './badges.tsx'
import { CardAudiencesSection } from './CardAudiencesSection.tsx'
import { CardDeleteSection } from './CardDeleteSection.tsx'
import { CardDetailsSection } from './CardDetailsSection.tsx'
import { CardPublicationSection } from './CardPublicationSection.tsx'
import { FileCardSection } from './FileCardSection.tsx'
import { Notice } from './FeedbackAlert.tsx'
import { useNotice } from './useNotice.ts'

/**
 * One Card for management (ADR 0037): its Type (fixed), details and content, its file if it is a File Card, its audience and its
 * publication, and permanent deletion. The Pack it is in is read as well, because a Card's audience is chosen from its Pack's. The
 * rich-text editor inside the details is loaded when this page opens, not with the Console.
 */
export function CardPage() {
  const { packId = '', cardId = '' } = useParams()
  // Keyed by the Card: moving to another one starts every section fresh, with none of the last Card's unsaved input.
  return <CardView key={`${packId}/${cardId}`} packId={packId} cardId={cardId} />
}

function CardView({ packId, cardId }: { packId: string; cardId: string }) {
  const notice = useNotice()
  const [reloads, setReloads] = useState(0)
  const loadCard = useCallback(
    (signal: AbortSignal) => getCard(packId, cardId, signal),
    // `reloads` is not read: a new load function is how a reload asks again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [packId, cardId, reloads],
  )
  const loadPack = useCallback(
    (signal: AbortSignal) => getPack(packId, signal),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [packId, reloads],
  )
  const [card, replaceCard] = useLoad(loadCard)
  const [pack] = useLoad(loadPack)
  useBreadcrumbLeaf(card.status === 'loaded' ? card.value.title : undefined)

  if (card.status === 'loading' || pack.status === 'loading') {
    return (
      <Page width="detail">
        <SkeletonRegion label="Loading the Card…" visibleLabel>
          <SkeletonText lines={5} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (card.status === 'failed' || pack.status === 'failed') {
    const failure =
      card.status === 'failed' ? card.failure : pack.status === 'failed' ? pack.failure : null
    return (
      <Page width="detail">
        <PageHeader title="Card" />
        <div className="flex flex-col items-start gap-3">
          {failure !== null ? (
            <Alert tone="error">{describeResourcesFailure(failure, 'card').message}</Alert>
          ) : null}
          <div className="flex flex-wrap gap-3">
            {failure?.kind !== 'not-found' ? (
              <Button
                onClick={() => {
                  setReloads((n) => n + 1)
                }}
              >
                Try again
              </Button>
            ) : null}
            <Link to={`/resources/packs/${packId}`} className={buttonVariants({})}>
              Back to the Pack
            </Link>
          </div>
        </div>
      </Page>
    )
  }

  const current = card.value

  return (
    <Page width="detail">
      <PageHeader
        title={current.title}
        status={
          <>
            <TypeBadge type={current.type} />
            <StateBadge state={current.state} />
          </>
        }
        description={
          <span className="wrap-anywhere">
            In{' '}
            <Link to={`/resources/packs/${packId}`} className="underline underline-offset-2">
              {pack.value.title}
            </Link>
          </span>
        }
      />
      <Notice text={notice} />
      <DetailLayout
        aside={
          <>
            <CardPublicationSection card={current} onChange={replaceCard} />
            <CardAudiencesSection
              card={current}
              packAudiences={pack.value.audiences}
              onChange={replaceCard}
            />
          </>
        }
      >
        <CardDetailsSection card={current} onSaved={replaceCard} />
        {current.type === 'file' && current.file !== null ? (
          <FileCardSection card={current} file={current.file} onReplaced={replaceCard} />
        ) : null}
        <CardDeleteSection card={current} />
      </DetailLayout>
    </Page>
  )
}
