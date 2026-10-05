import { useCallback, useState } from 'react'
import { Link, useParams } from 'react-router'

import { describeResourcesFailure } from '../../admin/resourcesWording.ts'
import { shown } from '../../admin/time.ts'
import { getPack, listCategories, type ManagedPack } from '../../api/resources.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Button } from '../../ui/Button.tsx'
import { Page, DetailLayout } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { useBreadcrumbLeaf } from '../../shell/breadcrumb-leaf.ts'
import { StateBadge } from './badges.tsx'
import { PackAudiencesSection } from './PackAudiencesSection.tsx'
import { PackCardsSection } from './PackCardsSection.tsx'
import { PackDeleteSection } from './PackDeleteSection.tsx'
import { PackDetailsSection } from './PackDetailsSection.tsx'
import { PackPreviewSection } from './PackPreviewSection.tsx'
import { PackPublicationSection } from './PackPublicationSection.tsx'
import { Notice } from './FeedbackAlert.tsx'
import { useNotice } from './useNotice.ts'

/**
 * One Resource Pack for management (ADR 0037): its details, its audiences, its publication, its Cards in order, a preview as an
 * audience, and permanent deletion. Every section changes one thing and hands the server's answer back here, so the whole page
 * always shows the Pack as the server last said it. Where a section's own form holds unsaved input, that stays put.
 */
export function PackPage() {
  const { packId = '' } = useParams()
  // Keyed by the Pack: moving to another one starts every section fresh, with none of the last Pack's unsaved input.
  return <PackView key={packId} packId={packId} />
}

function PackView({ packId }: { packId: string }) {
  const notice = useNotice()
  const [reloads, setReloads] = useState(0)
  const load = useCallback(
    (signal: AbortSignal) => getPack(packId, signal),
    // `reloads` is not read: a new load function is how a reload asks again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [packId, reloads],
  )
  const [pack, replace] = useLoad(load)
  // Reads the Pack again IN PLACE (after a reorder was refused as stale): swapping the load would blank the page and, with it, any
  // section's unsaved input.
  const refresh = useCallback(async () => {
    const fresh = await getPack(packId)
    if (fresh.ok) replace(fresh.value)
  }, [packId, replace])
  const loadCategories = useCallback((signal: AbortSignal) => listCategories(signal), [])
  const [categories] = useLoad(loadCategories)

  useBreadcrumbLeaf(pack.status === 'loaded' ? pack.value.title : undefined)

  if (pack.status === 'loading') {
    return (
      <Page width="detail">
        <SkeletonRegion label="Loading the Resource Pack…" visibleLabel>
          <SkeletonText lines={5} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (pack.status === 'failed') {
    return (
      <Page width="detail">
        <PageHeader title="Resource Pack" />
        <div className="flex flex-col items-start gap-3">
          <Alert tone="error">{describeResourcesFailure(pack.failure, 'pack').message}</Alert>
          <div className="flex flex-wrap gap-3">
            {pack.failure.kind !== 'not-found' ? (
              <Button
                onClick={() => {
                  setReloads((n) => n + 1)
                }}
              >
                Try again
              </Button>
            ) : null}
            <Link to="/resources" className={buttonVariants({})}>
              All Resource Packs
            </Link>
          </div>
        </div>
      </Page>
    )
  }

  const current = pack.value
  // Every Pack-changing answer is the Pack as one Pack is shown (Cards included); keep the Cards if one ever arrives without.
  const onChange = (next: ManagedPack) => {
    replace({ ...next, cards: next.cards ?? current.cards })
  }

  return (
    <Page width="detail">
      <PageHeader
        title={current.title}
        status={<StateBadge state={current.state} />}
        description={
          <>
            Last edited by {current.updatedBy.displayName ?? 'an unknown person'} on{' '}
            <time dateTime={current.updatedAt}>{shown(current.updatedAt)}</time>
          </>
        }
      />
      <Notice text={notice} />
      <DetailLayout
        aside={
          <>
            <PackPublicationSection pack={current} onChange={onChange} />
            <PackAudiencesSection pack={current} onChange={onChange} />
          </>
        }
      >
        <PackDetailsSection pack={current} categories={categories} onSaved={onChange} />
        <PackCardsSection
          pack={current}
          onChange={onChange}
          onReload={() => {
            void refresh()
          }}
        />
        <PackPreviewSection packId={current.id} />
        <PackDeleteSection pack={current} />
      </DetailLayout>
    </Page>
  )
}
