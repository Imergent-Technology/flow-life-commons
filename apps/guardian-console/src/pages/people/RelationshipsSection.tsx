import { useState } from 'react'
import { Link } from 'react-router'

import type { RelationshipType } from '../../api/relationships.ts'
import { personName, withArticle } from '../../relationships/definitions.ts'
import { useRelationshipTypes } from '../../relationships/relationship-types-context.ts'
import { RelationshipPanel } from '../../relationships/RelationshipPanel.tsx'
import { useRelationshipData } from '../../relationships/useRelationshipData.ts'
import { describeRelationshipFailure } from '../../relationships/wording.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Button } from '../../ui/Button.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { Tabs } from '../../ui/Tabs.tsx'

/**
 * The Person record's Relationships section (ADR 0038, C5): one tab for each type this operator may view or manage, in the
 * definitions' own order. The tabs come from the served types alone, so a manager of one type never learns from this page
 * that the person holds another. Each tab is the generic panel; where the person lacks the type and the operator may manage
 * it, the tab offers to record them. Membership, Account and security data are not here and are never asked for.
 */
export function RelationshipsSection({
  personId,
  personDisplayName,
}: {
  personId: string
  personDisplayName: string
}) {
  const { state, reload } = useRelationshipTypes()
  const [chosen, setChosen] = useState<string | null>(null)

  if (state.status === 'loading') {
    return (
      <SkeletonRegion label="Loading relationships…">
        <SkeletonText lines={2} />
      </SkeletonRegion>
    )
  }
  if (state.status === 'failed') {
    return (
      <Panel title="Relationships">
        <div className="flex flex-col items-start gap-3">
          <Alert tone="error">{describeRelationshipFailure(state.failure).message}</Alert>
          <Button onClick={reload}>Try again</Button>
        </div>
      </Panel>
    )
  }

  const types = state.types.filter((type) => type.actions.view || type.actions.manage)
  if (types.length === 0) return null
  const selected = types.find((type) => type.slug === chosen) ?? types[0]
  if (selected === undefined) return null

  return (
    <Panel title="Relationships">
      <Tabs
        label="Relationships"
        tabs={types.map((type) => ({ id: type.slug, label: type.labels.singular }))}
        selected={selected.slug}
        onSelect={setChosen}
      >
        <RelationshipTab
          key={selected.slug}
          type={selected}
          personId={personId}
          personDisplayName={personDisplayName}
        />
      </Tabs>
    </Panel>
  )
}

function RelationshipTab({
  type,
  personId,
  personDisplayName,
}: {
  type: RelationshipType
  personId: string
  personDisplayName: string
}) {
  const data = useRelationshipData(type, personId)
  const [deleted, setDeleted] = useState(false)

  if (data.status === 'loading') {
    return (
      <SkeletonRegion label={`Loading ${type.labels.singular.toLowerCase()}…`}>
        <SkeletonText lines={3} />
      </SkeletonRegion>
    )
  }
  if (data.status === 'failed') {
    return (
      <div className="flex flex-col items-start gap-3">
        <Alert tone="error">
          {data.failure === null
            ? 'Something unexpected happened. Try again.'
            : describeRelationshipFailure(data.failure, type).message}
        </Alert>
        <Button
          onClick={() => {
            void data.reload()
          }}
        >
          Try again
        </Button>
      </div>
    )
  }
  if (data.status === 'not-found') {
    return (
      <div className="flex flex-col items-start gap-3">
        {deleted ? (
          <Alert tone="success">
            {`${personName({ id: personId, displayName: personDisplayName })}'s ${type.labels.singular.toLowerCase()} was deleted permanently.`}
          </Alert>
        ) : null}
        <p className="text-body wrap-anywhere text-muted-foreground">
          {personDisplayName} is not recorded as {withArticle(type.labels.singular)}.
        </p>
        {type.actions.manage ? (
          // The person goes with the link, so intake opens on its existing-person confirmation. Nothing is recorded by it.
          <Link
            to={`/relationships/${type.slug}/new`}
            state={{ person: { id: personId, displayName: personDisplayName } }}
            className={buttonVariants({ variant: 'secondary' })}
          >
            Record as {type.labels.singular}
          </Link>
        ) : null}
      </div>
    )
  }
  return (
    <RelationshipPanel
      data={data}
      headingLevel={3}
      onDeleted={() => {
        setDeleted(true)
      }}
    />
  )
}
