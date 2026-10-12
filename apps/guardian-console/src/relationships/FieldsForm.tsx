import { useState, type SyntheticEvent } from 'react'

import { updateRelationshipFields } from '../api/relationships.ts'
import { Alert } from '../ui/Alert.tsx'
import { Button } from '../ui/Button.tsx'
import { Panel } from '../ui/Panel.tsx'
import { Property, PropertyList } from '../ui/PropertyList.tsx'
import { SubmitButton } from '../ui/SubmitButton.tsx'
import { groupedFields, orderedFields } from './definitions.ts'
import { draftFrom, prepareChanges, type Draft } from './fieldEditing.ts'
import { FieldInput } from './FieldInput.tsx'
import { FieldValueView } from './FieldValueView.tsx'
import type { RelationshipData } from './useRelationshipData.ts'
import { describeRelationshipFailure } from './wording.ts'
import type { FieldErrors } from '../api/http.ts'

/**
 * The relationship's own descriptive fields, from the DEFINITION: which fields exist, their order, labels, limits and
 * controls. Reading needs either capability. Editing needs the manage capability and sends only what changed, with the
 * instance and revision that were shown. A field edit changes no status and provisions no role. The server's `422` lands on
 * the field it names; the advisory checks here only save a round trip.
 */
export function FieldsForm({
  data,
  onNotice,
  headingLevel = 2,
}: {
  data: RelationshipData
  onNotice: (message: string) => void
  headingLevel?: 2 | 3
}) {
  const { type, management, view } = data
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState<Draft>({})
  const [pending, setPending] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [problem, setProblem] = useState<{ message: string; attempt: number } | null>(null)

  if (type.fields.length === 0) return null

  // Management carries every value; the view read carries the visible ones. Either is enough to read.
  const values = management?.fields ?? view?.fields ?? {}
  const mayEdit = management !== null && !data.mismatch
  const fields = orderedFields(type.fields)

  function begin() {
    setDraft(draftFrom(fields, values))
    setErrors({})
    setProblem(null)
    setEditing(true)
  }

  async function save() {
    const prepared = prepareChanges(fields, values, draft)
    if (Object.keys(prepared.problems).length > 0) {
      setErrors(Object.fromEntries(Object.entries(prepared.problems).map(([k, v]) => [k, [v]])))
      setProblem(null)
      return
    }
    if (Object.keys(prepared.changes).length === 0) {
      setEditing(false)
      return
    }
    setPending(true)
    const outcome = await data.mutate((shown) =>
      updateRelationshipFields(type.slug, data.personId, {
        relationshipId: shown.relationshipId,
        revision: shown.revision,
        fields: prepared.changes,
      }),
    )
    setPending(false)
    switch (outcome.status) {
      case 'done':
        setEditing(false)
        onNotice('Saved.')
        return
      case 'stale':
        setEditing(false) // the page re-read and explained; the form starts again from what is current
        return
      case 'verify':
        setProblem({
          message: 'Confirm it is you first, then save again.',
          attempt: (problem?.attempt ?? 0) + 1,
        })
        return
      case 'failed': {
        const described = describeRelationshipFailure(outcome.failure, type)
        setErrors(described.fields)
        setProblem({ message: described.message, attempt: (problem?.attempt ?? 0) + 1 })
      }
    }
  }

  return (
    <Panel
      title="Details"
      headingLevel={headingLevel}
      actions={
        mayEdit && !editing ? (
          <Button size="sm" onClick={begin}>
            Edit details
          </Button>
        ) : undefined
      }
    >
      {editing ? (
        <form
          aria-label={`Edit ${type.labels.singular} details`}
          noValidate
          onSubmit={(event: SyntheticEvent) => {
            event.preventDefault()
            void save()
          }}
          className="flex flex-col gap-4"
        >
          {problem !== null && Object.keys(errors).length === 0 ? (
            <Alert key={problem.attempt} tone="error" focusOnMount>
              {problem.message}
            </Alert>
          ) : null}
          {fields.map((field) => (
            <FieldInput
              key={field.key}
              field={field}
              value={draft[field.key] ?? values[field.key]}
              errors={errors[field.key]}
              disabled={pending}
              onChange={(value) => {
                setDraft({ ...draft, [field.key]: value })
              }}
            />
          ))}
          <div className="flex flex-wrap gap-3">
            <SubmitButton pending={pending} pendingLabel="Saving…">
              Save details
            </SubmitButton>
            <Button
              disabled={pending}
              onClick={() => {
                setEditing(false)
              }}
            >
              Cancel
            </Button>
          </div>
        </form>
      ) : (
        <div className="flex flex-col gap-4">
          {groupedFields(type.fields).map((group) => (
            <div key={group.group ?? ''} className="flex flex-col gap-2">
              {group.group === null ? null : (
                <p className="text-label font-medium text-foreground">{group.group}</p>
              )}
              <PropertyList>
                {group.fields.map((field) => (
                  <Property key={field.key} term={field.label}>
                    <FieldValueView field={field} value={values[field.key]} />
                  </Property>
                ))}
              </PropertyList>
            </div>
          ))}
        </div>
      )}
    </Panel>
  )
}
