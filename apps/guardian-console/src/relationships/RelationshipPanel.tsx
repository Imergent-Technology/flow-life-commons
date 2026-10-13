import { useState } from 'react'

import { shown } from '../admin/time.ts'
import { Alert } from '../ui/Alert.tsx'
import { Button } from '../ui/Button.tsx'
import { Panel } from '../ui/Panel.tsx'
import { Property, PropertyList } from '../ui/PropertyList.tsx'
import { BasicsForm } from './BasicsForm.tsx'
import { ConflictNotice } from './ConflictNotice.tsx'
import { DefaultRolePanel } from './DefaultRolePanel.tsx'
import { DeleteRelationship } from './DeleteRelationship.tsx'
import { hasFeature, personName, UNNAMED_PERSON } from './definitions.ts'
import { relationshipExtensions } from './extensions.ts'
import { FieldsForm } from './FieldsForm.tsx'
import { RelationshipHistory } from './RelationshipHistory.tsx'
import { RelationshipStatusBadge } from './RelationshipStatusBadge.tsx'
import { StatusChange } from './StatusChange.tsx'
import type { RelationshipData } from './useRelationshipData.ts'

/**
 * Everything the operator may see and do about ONE relationship, for the capabilities they hold. It is the generic panel:
 * the record page and the Person record's tabs both render it, and it holds nothing about any type. What appears is decided
 * by the served definition (labels, states, fields, features, default role) and by what the reads returned:
 *
 *   - view only:   summary, details, contact basics, default-role state and history, with no controls;
 *   - manage only: summary and controls from the management read, with no history and no provenance;
 *   - both:        the two combined once, with no panel shown twice.
 *
 * A type may add a section of its own through `relationshipExtensions`; none does in G10.
 */
export function RelationshipPanel({
  data,
  onDeleted,
  headingLevel = 2,
}: {
  data: RelationshipData
  onDeleted: () => void
  headingLevel?: 2 | 3
}) {
  const { type, view, management } = data
  const [notice, setNotice] = useState<{ text: string; attempt: number } | null>(null)
  const announce = (text: string) => {
    setNotice((previous) => ({ text, attempt: (previous?.attempt ?? 0) + 1 }))
  }
  const Extension = relationshipExtensions[type.type]?.Section

  const status = management?.status ?? view?.status.key ?? null
  const person = management?.person ?? view?.person ?? null
  // Contact basics: editable where the type allows it, otherwise read-only from the view read.
  const editableBasics = Boolean(management?.basics) && hasFeature(type, 'people.edit_basics')
  const readOnlyBasics = !editableBasics ? (view?.basics ?? null) : null

  return (
    <div className="flex flex-col gap-6">
      {data.conflict === null ? null : (
        <ConflictNotice
          conflict={data.conflict}
          singular={type.labels.singular}
          onDismiss={data.dismissConflict}
        />
      )}
      {data.mismatch ? (
        <Alert tone="warning">
          <p>
            This {type.labels.singular.toLowerCase()} changed while the page was loading, so changes
            are switched off until it is read again.
          </p>
          <div className="mt-2">
            <Button
              size="sm"
              onClick={() => {
                void data.reload()
              }}
            >
              Reload
            </Button>
          </div>
        </Alert>
      ) : null}
      {notice === null ? null : (
        <Alert key={notice.attempt} tone="success">
          {notice.text}
        </Alert>
      )}

      <Panel title={type.labels.singular} headingLevel={headingLevel}>
        <PropertyList>
          <Property term="Person">{person === null ? UNNAMED_PERSON : personName(person)}</Property>
          {status === null ? null : (
            <Property term="Status">
              <RelationshipStatusBadge type={type} status={status} />
            </Property>
          )}
          {view === null ? null : (
            <>
              <Property term="Status since">{shown(view.status.since)}</Property>
              <Property term="Status set by">{personName(view.status.by)}</Property>
              <Property term="Recorded">
                {shown(view.createdAt)} by {personName(view.createdBy)}
              </Property>
            </>
          )}
        </PropertyList>
        {readOnlyBasics === null ? null : (
          <div className="mt-3">
            <PropertyList>
              <Property term="Primary email">{readOnlyBasics.primaryEmail ?? '—'}</Property>
              <Property term="Primary phone">{readOnlyBasics.primaryPhone ?? '—'}</Property>
            </PropertyList>
          </div>
        )}
      </Panel>

      <StatusChange data={data} onNotice={announce} headingLevel={headingLevel} />
      <FieldsForm data={data} onNotice={announce} headingLevel={headingLevel} />
      <BasicsForm data={data} onNotice={announce} headingLevel={headingLevel} />
      <DefaultRolePanel data={data} onNotice={announce} headingLevel={headingLevel} />
      {Extension === undefined ? null : (
        <Extension type={type} personId={data.personId} view={view} management={management} />
      )}
      {view === null ? null : (
        <RelationshipHistory type={type} record={view} headingLevel={headingLevel} />
      )}
      <DeleteRelationship data={data} onDeleted={onDeleted} headingLevel={headingLevel} />
    </div>
  )
}
