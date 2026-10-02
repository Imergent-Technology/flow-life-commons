import { useState, type SyntheticEvent } from 'react'

import { updatePerson, type PersonChanges, type PersonRecord } from '../../api/people.ts'
import { describePeopleFailure } from '../../admin/peopleWording.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { Field } from '../../ui/Field.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { type Problem } from '../../ui/problem.ts'
import { Property, PropertyList } from '../../ui/PropertyList.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { Textarea } from '../../ui/Textarea.tsx'
import { TextField } from '../../ui/TextField.tsx'

const NOT_RECORDED = <span className="text-muted-foreground">Not recorded</span>

/** What the form holds, as text: a missing profile field is an empty box, and an empty box means "nothing recorded". */
interface Draft {
  displayName: string
  howWeKnow: string
  affiliation: string
}

const draftOf = (record: PersonRecord): Draft => ({
  displayName: record.person.displayName,
  howWeKnow: record.profile.howWeKnow ?? '',
  affiliation: record.profile.affiliation ?? '',
})

/**
 * Only what the Guardian actually changed. The API is a partial update: a field that is sent is changed and one that is not
 * is left alone, so a field the form did not touch is never sent, and cannot overwrite what someone else changed since the
 * page loaded. A cleared profile field is sent as null.
 */
function changesFrom(record: PersonRecord, draft: Draft): PersonChanges {
  const before = draftOf(record)
  const changes: PersonChanges = {}
  if (draft.displayName.trim() !== before.displayName)
    changes.displayName = draft.displayName.trim()
  if (draft.howWeKnow.trim() !== before.howWeKnow) {
    changes.howWeKnow = draft.howWeKnow.trim() === '' ? null : draft.howWeKnow.trim()
  }
  if (draft.affiliation.trim() !== before.affiliation) {
    changes.affiliation = draft.affiliation.trim() === '' ? null : draft.affiliation.trim()
  }
  return changes
}

/**
 * What CRM holds about the Person beyond their name: how Flow Life knows them and who they are affiliated with. The name
 * itself is Identity's: correcting it goes through the same endpoint, which hands it to Identity, and needs no proof of
 * identity because it changes no one's access. Editing is offered to `crm.people.manage` only.
 */
export function ProfileSection({
  record,
  mayManage,
  refresh,
}: {
  record: PersonRecord
  mayManage: boolean
  refresh: () => Promise<void>
}) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState<Draft>(() => draftOf(record))
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  function begin() {
    setDraft(draftOf(record))
    setProblem(null)
    setNotice(null)
    setEditing(true)
  }

  async function save() {
    const changes = changesFrom(record, draft)
    if (Object.keys(changes).length === 0) {
      setEditing(false)
      setNotice('Nothing was changed.')
      return
    }
    setPending(true)
    const result = await updatePerson(record.person.id, changes)
    setPending(false)
    if (result.ok) {
      setProblem(null)
      setEditing(false)
      setNotice('Saved.')
      await refresh()
      return
    }
    if (result.failure.kind === 'unauthenticated') return // the session ended; the boundary handles it
    const next = describePeopleFailure(result.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <Panel
      title="Profile"
      actions={
        mayManage && !editing ? (
          <Button size="sm" onClick={begin}>
            Edit profile
          </Button>
        ) : undefined
      }
    >
      {notice !== null && !editing ? (
        <div className="mb-3">
          <Alert tone="success">{notice}</Alert>
        </div>
      ) : null}
      {editing ? (
        <form
          aria-label="Edit profile"
          onSubmit={(event: SyntheticEvent) => {
            event.preventDefault()
            void save()
          }}
          className="flex flex-col gap-4"
        >
          {problem && Object.keys(problem.fields).length === 0 ? (
            <Alert key={problem.attempt} tone="error" focusOnMount>
              {problem.message}
            </Alert>
          ) : null}
          <TextField
            label="Display name"
            name="display_name"
            autoComplete="off"
            value={draft.displayName}
            onChange={(displayName) => {
              setDraft({ ...draft, displayName })
            }}
            errors={problem?.fields.display_name}
            maxLength={255}
          />
          <Field label="How we know them" error={problem?.fields.how_we_know?.join(' ')}>
            {(control) => (
              <Textarea
                {...control}
                name="how_we_know"
                value={draft.howWeKnow}
                onChange={(event) => {
                  setDraft({ ...draft, howWeKnow: event.target.value })
                }}
                maxLength={2000}
              />
            )}
          </Field>
          <TextField
            label="Affiliation"
            name="affiliation"
            autoComplete="off"
            value={draft.affiliation}
            onChange={(affiliation) => {
              setDraft({ ...draft, affiliation })
            }}
            errors={problem?.fields.affiliation}
            required={false}
            maxLength={255}
          />
          <div className="flex flex-wrap gap-3">
            <SubmitButton pending={pending} pendingLabel="Saving…">
              Save profile
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
        <PropertyList>
          <Property term="How we know them">
            {record.profile.howWeKnow === null ? (
              NOT_RECORDED
            ) : (
              <span className="whitespace-pre-line">{record.profile.howWeKnow}</span>
            )}
          </Property>
          <Property term="Affiliation">{record.profile.affiliation ?? NOT_RECORDED}</Property>
        </PropertyList>
      )}
    </Panel>
  )
}
