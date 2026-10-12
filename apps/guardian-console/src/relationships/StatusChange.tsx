import { useState } from 'react'

import { changeRelationshipStatus } from '../api/relationships.ts'
import { Button } from '../ui/Button.tsx'
import { Checkbox } from '../ui/Checkbox.tsx'
import { ConfirmDialog } from '../ui/ConfirmDialog.tsx'
import { Panel } from '../ui/Panel.tsx'
import { choiceToSend, initialChoice, unavailableExplanation } from './defaultRole.ts'
import { personName, stateLabel, stateQualifies } from './definitions.ts'
import { confirmResultFor } from './outcome.ts'
import { RelationshipStatusBadge } from './RelationshipStatusBadge.tsx'
import type { RelationshipData } from './useRelationshipData.ts'

/**
 * Changing a relationship's status. The statuses offered are the transitions the SERVER says are allowed from the current
 * one (the management read); the labels are the definition's. Each change goes through a deliberate confirmation, and the
 * request names the instance and the revision that were shown. Recent verification is the shared prompt: the server asks
 * for it where the definition requires it (a type that can grant authority), and the action is not repeated for the
 * operator once they have proved it.
 *
 * Entering a state that counts, for a type with a default role, includes that role's choice (ADR 0038, K5). Leaving one never
 * sends a role decision: the access is withdrawn by the server, and the dialog says so.
 */
export function StatusChange({
  data,
  onNotice,
  headingLevel = 2,
}: {
  data: RelationshipData
  onNotice: (message: string) => void
  headingLevel?: 2 | 3
}) {
  const { type, management } = data
  const [target, setTarget] = useState<string | null>(null)

  if (management === null) return null
  const transitions = management.transitions
  const mayChange = !data.mismatch && transitions.length > 0

  return (
    <Panel title="Status" headingLevel={headingLevel}>
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-2 text-body text-foreground">
          <span>Current status:</span>
          <RelationshipStatusBadge type={type} status={management.status} />
        </div>
        {transitions.length === 0 ? (
          <p className="text-body text-muted-foreground">No status changes are available.</p>
        ) : (
          <div role="group" aria-label="Change status" className="flex flex-wrap gap-2">
            {transitions.map((key) => (
              <Button
                key={key}
                disabled={!mayChange}
                onClick={() => {
                  setTarget(key)
                }}
              >
                Change to {stateLabel(type, key)}
              </Button>
            ))}
          </div>
        )}
      </div>
      {target === null ? null : (
        <StatusChangeDialog
          key={target}
          data={data}
          target={target}
          onClose={() => {
            setTarget(null)
          }}
          onNotice={onNotice}
        />
      )}
    </Panel>
  )
}

function StatusChangeDialog({
  data,
  target,
  onClose,
  onNotice,
}: {
  data: RelationshipData
  target: string
  onClose: () => void
  onNotice: (message: string) => void
}) {
  const { type, management } = data
  const role = type.defaultRole
  const entering = stateQualifies(type, target)
  const leaving = management !== null && stateQualifies(type, management.status)
  // A decision about the default role exists only when entering a state that counts, for a type that has one.
  const decides = role !== null && entering
  const option = initialChoice({
    mayProvision: type.actions.provisionDefaultRole,
    stored: management?.defaultRole?.decision ?? null,
  })
  const [checked, setChecked] = useState(option.available && option.checked)

  if (management === null) return null
  const name = personName(management.person)
  const from = stateLabel(type, management.status)
  const to = stateLabel(type, target)

  return (
    <ConfirmDialog
      title={`Change ${name} to ${to}?`}
      confirmLabel={`Change to ${to}`}
      onCancel={onClose}
      onConfirm={async () =>
        confirmResultFor(
          await data.mutate((shown) =>
            changeRelationshipStatus(type.slug, data.personId, {
              relationshipId: shown.relationshipId,
              revision: shown.revision,
              status: target,
              ...(decides && { defaultRole: choiceToSend(option, checked) }),
            }),
          ),
          {
            type,
            onDone: () => {
              onClose()
              onNotice(`${name} is now ${to}.`)
            },
            onStale: onClose,
          },
        )
      }
    >
      <p>
        {name}&rsquo;s {type.labels.singular.toLowerCase()} status will change from{' '}
        <strong>{from}</strong> to <strong>{to}</strong>. It is recorded in the history.
      </p>
      {role !== null && leaving && !entering ? (
        <p>
          Any {role.displayName} access given through this relationship is withdrawn. Roles granted
          directly are not affected.
        </p>
      ) : null}
      {decides ? (
        <div className="flex flex-col gap-2">
          <Checkbox
            label={`Grant ${role.displayName} access`}
            checked={option.available && checked}
            disabled={!option.available}
            onChange={(event) => {
              setChecked(event.target.checked)
            }}
          />
          {option.available ? (
            <p className="text-meta text-muted-foreground">
              {checked
                ? `${name} will be given ${role.displayName} access, through this relationship.`
                : `${name} will not be given ${role.displayName} access now.`}
            </p>
          ) : (
            <p className="text-meta text-muted-foreground">
              {unavailableExplanation(role.displayName)}
            </p>
          )}
        </div>
      ) : null}
    </ConfirmDialog>
  )
}
