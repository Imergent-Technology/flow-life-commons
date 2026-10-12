import { useState, type SyntheticEvent } from 'react'

import type { FieldErrors } from '../api/http.ts'
import {
  staleBasicsCurrent,
  updateRelationshipBasics,
  type BasicChanges,
  type CurrentBasics,
} from '../api/relationships.ts'
import { Alert } from '../ui/Alert.tsx'
import { Button } from '../ui/Button.tsx'
import {
  DataTable,
  DataTableBody,
  DataTableCell,
  DataTableHead,
  DataTableHeaderCell,
  DataTableRow,
  DataTableRowHeader,
} from '../ui/DataTable.tsx'
import { Panel } from '../ui/Panel.tsx'
import { Property, PropertyList } from '../ui/PropertyList.tsx'
import { SubmitButton } from '../ui/SubmitButton.tsx'
import { TextField } from '../ui/TextField.tsx'
import { UNNAMED_PERSON } from './definitions.ts'
import type { RelationshipData } from './useRelationshipData.ts'
import { describeRelationshipFailure } from './wording.ts'

type Key = 'displayName' | 'primaryEmail' | 'primaryPhone'
const KEYS: readonly Key[] = ['displayName', 'primaryEmail', 'primaryPhone']
const WIRE_KEY: Record<Key, string> = {
  displayName: 'display_name',
  primaryEmail: 'primary_email',
  primaryPhone: 'primary_phone',
}
const LABEL: Record<Key, string> = {
  displayName: 'Display name',
  primaryEmail: 'Primary email',
  primaryPhone: 'Primary phone',
}

/** What the operator was shown, exactly: the `from` of a change is compared to the server's current value as a string. */
type Baseline = Record<Key, string | null>
type Typed = Record<Key, string>

const asTyped = (baseline: Baseline): Typed => ({
  displayName: baseline.displayName ?? '',
  primaryEmail: baseline.primaryEmail ?? '',
  primaryPhone: baseline.primaryPhone ?? '',
})

/**
 * What the form holds after the server says the details moved: the operator's own edits are kept, and every detail they did
 * not change (it still equals what they were shown) takes the current value. A detail is the operator's only if they typed it.
 */
function rebasedOnto(typed: Typed, shownBefore: Baseline, current: CurrentBasics): Typed {
  const now = asTyped(current)
  const next = { ...typed }
  for (const key of KEYS) {
    if (typed[key].trim() === (shownBefore[key] ?? '').trim()) next[key] = now[key]
  }
  return next
}

/**
 * The three basic details a relationship's manager may keep up (ADR 0038, P5): the display name, the primary email and the
 * primary phone, nothing else. It exists only where the type enables it. Each change is sent as `{from, to}`, where `from`
 * is exactly what the operator was shown (casing and spacing included) and an unchanged detail is left out. Clearing is not
 * offered. The request names the instance and no revision: these are CRM's details, guarded by their own compare-and-set.
 *
 * If they changed in the meantime the server refuses and returns the current values. The operator's own values stay in the
 * form, the current ones are shown beside them, and NOTHING is sent again until they press the button once more: a
 * deliberate decision to replace what is now there.
 */
export function BasicsForm({
  data,
  onNotice,
  headingLevel = 2,
}: {
  data: RelationshipData
  onNotice: (message: string) => void
  headingLevel?: 2 | 3
}) {
  const { type, management } = data
  const [editing, setEditing] = useState(false)
  const [baseline, setBaseline] = useState<Baseline | null>(null)
  const [typed, setTyped] = useState<Typed>({ displayName: '', primaryEmail: '', primaryPhone: '' })
  const [stale, setStale] = useState<CurrentBasics | null>(null)
  const [pending, setPending] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [problem, setProblem] = useState<{ message: string; attempt: number } | null>(null)

  if (!management?.basics) return null
  const basics = management.basics
  const shownNow: Baseline = {
    displayName: management.person.displayName,
    primaryEmail: basics.primaryEmail,
    primaryPhone: basics.primaryPhone,
  }
  const mayEdit = !data.mismatch

  function begin() {
    setBaseline(shownNow)
    setTyped(asTyped(shownNow))
    setStale(null)
    setErrors({})
    setProblem(null)
    setEditing(true)
  }

  async function save() {
    if (baseline === null || management === null) return
    const changes: BasicChanges = {}
    const advisory: FieldErrors = {}
    for (const key of KEYS) {
      const to = typed[key].trim()
      const from = baseline[key]
      if (to === '') {
        // Clearing is not offered; leaving a detail that was never set empty is simply no change.
        if (from !== null && from !== '') advisory[WIRE_KEY[key]] = ['This cannot be cleared here.']
        continue
      }
      if (to !== (from ?? '')) changes[key] = { from, to }
    }
    if (Object.keys(advisory).length > 0) {
      setErrors(advisory)
      setProblem(null)
      return
    }
    if (Object.keys(changes).length === 0) {
      setEditing(false)
      return
    }
    setPending(true)
    const outcome = await data.mutate((shown) =>
      updateRelationshipBasics(type.slug, data.personId, {
        relationshipId: shown.relationshipId,
        changes,
      }),
    )
    setPending(false)
    switch (outcome.status) {
      case 'done':
        setEditing(false)
        setStale(null)
        onNotice('Contact details saved.')
        return
      case 'stale':
        setEditing(false)
        return
      case 'verify':
        setProblem({
          message: 'Confirm it is you first, then save again.',
          attempt: (problem?.attempt ?? 0) + 1,
        })
        return
      case 'failed': {
        const current = staleBasicsCurrent(outcome.failure)
        if (current !== null) {
          // Keep only what the operator actually changed. A detail still equal to what they were shown was merely prefilled,
          // so it takes the current value: otherwise the next Save would send a reversion of someone else's change as if it
          // were theirs. Compare against what is there now; send nothing until they decide again.
          setStale(current)
          setTyped(rebasedOnto(typed, baseline, current))
          setBaseline({
            displayName: current.displayName,
            primaryEmail: current.primaryEmail,
            primaryPhone: current.primaryPhone,
          })
          setErrors({})
          setProblem(null)
          return
        }
        const described = describeRelationshipFailure(outcome.failure, type)
        setErrors(described.fields)
        setProblem({ message: described.message, attempt: (problem?.attempt ?? 0) + 1 })
      }
    }
  }

  return (
    <Panel
      title="Contact details"
      description="Name, primary email and primary phone. Other contact information is kept in People."
      headingLevel={headingLevel}
      actions={
        mayEdit && !editing ? (
          <Button size="sm" onClick={begin}>
            Edit contact details
          </Button>
        ) : undefined
      }
    >
      {editing ? (
        <form
          aria-label="Edit contact details"
          noValidate
          onSubmit={(event: SyntheticEvent) => {
            event.preventDefault()
            void save()
          }}
          className="flex flex-col gap-4"
        >
          {stale === null ? null : (
            <div className="flex flex-col gap-3">
              <Alert tone="warning" focusOnMount>
                These details were changed by someone else while you were editing. Your changes have
                not been saved. Your values are still in the form; the current ones are shown below.
                Saving again replaces them with yours.
              </Alert>
              <DataTable caption="Your values beside the current values">
                <DataTableHead>
                  <tr>
                    <DataTableHeaderCell>Detail</DataTableHeaderCell>
                    <DataTableHeaderCell>Your value</DataTableHeaderCell>
                    <DataTableHeaderCell>Current value</DataTableHeaderCell>
                  </tr>
                </DataTableHead>
                <DataTableBody>
                  {KEYS.map((key) => (
                    <DataTableRow key={key}>
                      <DataTableRowHeader>{LABEL[key]}</DataTableRowHeader>
                      <DataTableCell label="Your value">
                        {typed[key] === '' ? '—' : typed[key]}
                      </DataTableCell>
                      <DataTableCell label="Current value">{stale[key] ?? '—'}</DataTableCell>
                    </DataTableRow>
                  ))}
                </DataTableBody>
              </DataTable>
            </div>
          )}
          {problem !== null && Object.keys(errors).length === 0 ? (
            <Alert key={problem.attempt} tone="error" focusOnMount>
              {problem.message}
            </Alert>
          ) : null}
          {KEYS.map((key) => (
            <TextField
              key={key}
              label={LABEL[key]}
              name={WIRE_KEY[key]}
              type={key === 'primaryEmail' ? 'email' : 'text'}
              autoComplete="off"
              value={typed[key]}
              onChange={(value) => {
                setTyped({ ...typed, [key]: value })
              }}
              errors={errors[WIRE_KEY[key]]}
              required={false}
              maxLength={key === 'primaryPhone' ? 64 : 255}
              disabled={
                pending || (key === 'displayName' && management.person.displayName === null)
              }
            />
          ))}
          <div className="flex flex-wrap gap-3">
            <SubmitButton pending={pending} pendingLabel="Saving…">
              Save contact details
            </SubmitButton>
            <Button
              disabled={pending}
              onClick={() => {
                setEditing(false)
                setStale(null)
              }}
            >
              Cancel
            </Button>
          </div>
        </form>
      ) : (
        <PropertyList>
          <Property term="Display name">{management.person.displayName ?? UNNAMED_PERSON}</Property>
          <Property term="Primary email">{basics.primaryEmail ?? '—'}</Property>
          <Property term="Primary phone">{basics.primaryPhone ?? '—'}</Property>
        </PropertyList>
      )}
    </Panel>
  )
}
