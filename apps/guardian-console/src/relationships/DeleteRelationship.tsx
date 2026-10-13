import { useState } from 'react'

import { deleteRelationship } from '../api/relationships.ts'
import { Button } from '../ui/Button.tsx'
import { ConfirmDialog } from '../ui/ConfirmDialog.tsx'
import { Panel } from '../ui/Panel.tsx'
import { personName } from './definitions.ts'
import { confirmResultFor } from './outcome.ts'
import type { RelationshipData } from './useRelationshipData.ts'

/**
 * The danger zone: permanent deletion (ADR 0038, F12), last on the record. Deliberate confirmation first, then the shared
 * recent-verification prompt where the server asks for it. The request names the instance and the revision that were shown
 * (as query parameters, since a DELETE body is unreliable), so a deletion meant for one relationship can never land on a
 * relationship recorded in its place. The confirmation says exactly what goes and what stays.
 */
export function DeleteRelationship({
  data,
  onDeleted,
  headingLevel = 2,
}: {
  data: RelationshipData
  /** Where the page goes next, or what a tab shows. Called after the server has deleted the relationship. */
  onDeleted: () => void
  headingLevel?: 2 | 3
}) {
  const { type, management } = data
  const [open, setOpen] = useState(false)

  if (management === null || !type.actions.delete) return null
  const name = personName(management.person)
  const noun = type.labels.singular.toLowerCase()
  const role = type.defaultRole

  return (
    <Panel
      tone="danger"
      title="Delete permanently"
      description={`Remove this ${noun} from ${name}. This cannot be undone.`}
      headingLevel={headingLevel}
    >
      <Button
        variant="danger"
        disabled={data.mismatch}
        onClick={() => {
          setOpen(true)
        }}
      >
        Delete this {noun}…
      </Button>
      {open ? (
        <ConfirmDialog
          title={`Delete ${name}'s ${noun} permanently?`}
          confirmLabel="Delete permanently"
          destructive
          onCancel={() => {
            setOpen(false)
          }}
          onConfirm={async () =>
            confirmResultFor(
              await data.remove((shown) =>
                deleteRelationship(type.slug, data.personId, {
                  relationshipId: shown.relationshipId,
                  revision: shown.revision,
                }),
              ),
              {
                type,
                onDone: () => {
                  setOpen(false)
                  onDeleted()
                },
                onStale: () => {
                  setOpen(false)
                },
              },
            )
          }
        >
          <p>This permanently removes:</p>
          <ul className="list-disc pl-5">
            <li>this {noun};</li>
            <li>its details;</li>
            <li>its history.</li>
          </ul>
          <p>It cannot be undone. It does not delete:</p>
          <ul className="list-disc pl-5">
            <li>{name} as a person;</li>
            <li>their contact information;</li>
            <li>their other relationships;</li>
            <li>roles that were granted to them directly.</li>
          </ul>
          {role === null ? null : (
            <p>
              {role.displayName} access that was given through this {noun} is withdrawn.
            </p>
          )}
        </ConfirmDialog>
      ) : null}
    </Panel>
  )
}
