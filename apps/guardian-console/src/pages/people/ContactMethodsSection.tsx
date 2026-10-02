import { useState, type SyntheticEvent } from 'react'

import {
  addContactMethod,
  removeContactMethod,
  updateContactMethod,
  type ContactMethod,
  type ContactMethodKind,
  type PersonRecord,
} from '../../api/people.ts'
import { contactKindLabel, describePeopleFailure } from '../../admin/peopleWording.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Badge } from '../../ui/Badge.tsx'
import { Button } from '../../ui/Button.tsx'
import { Checkbox } from '../../ui/Checkbox.tsx'
import { ConfirmDialog, type ConfirmResult } from '../../ui/ConfirmDialog.tsx'
import { Field } from '../../ui/Field.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { type Problem } from '../../ui/problem.ts'
import { Select } from '../../ui/Select.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { TextField } from '../../ui/TextField.tsx'

/** `new` is the add form; a method is the edit form for that method. Only one form is open at a time. */
type Editing = 'new' | ContactMethod | null

/**
 * The emails and phone numbers recorded for the Person (ADR 0034). The server owns every rule: the first method of a kind
 * becomes its primary, making another primary demotes the old one, removing the primary promotes the earliest remaining,
 * and the same value twice for one Person is refused. This only asks, and then re-reads the record, so what is shown is
 * always what the server holds rather than a second, client-side idea of which is primary. Changes are offered to
 * `crm.people.manage` only.
 */
export function ContactMethodsSection({
  record,
  mayManage,
  refresh,
}: {
  record: PersonRecord
  mayManage: boolean
  refresh: () => Promise<void>
}) {
  const personId = record.person.id
  const [editing, setEditing] = useState<Editing>(null)
  const [removing, setRemoving] = useState<ContactMethod | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [failure, setFailure] = useState<{ message: string; attempt: number } | null>(null)

  function fail(message: string) {
    setFailure((previous) => ({ message, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  async function makePrimary(method: ContactMethod) {
    setNotice(null)
    setFailure(null)
    const result = await updateContactMethod(personId, method.id, { makePrimary: true })
    if (result.ok) {
      setNotice(
        `${method.value} is now the primary ${contactKindLabel(method.kind).toLowerCase()}.`,
      )
      await refresh()
    } else if (result.failure.kind !== 'unauthenticated') {
      fail(describePeopleFailure(result.failure).message)
    }
  }

  async function confirmRemove(): Promise<ConfirmResult> {
    if (removing === null) return { kind: 'stay', tone: 'error', message: 'Nothing to remove.' }
    const result = await removeContactMethod(personId, removing.id)
    if (result.ok) {
      setNotice(`${removing.value} was removed.`)
      setRemoving(null)
      void refresh()
      return { kind: 'done' }
    }
    return { kind: 'stay', tone: 'error', message: describePeopleFailure(result.failure).message }
  }

  return (
    <Panel
      title="Contact methods"
      description="Emails and phone numbers recorded for this person. These are not a sign-in address."
      actions={
        mayManage && editing === null ? (
          <Button
            size="sm"
            onClick={() => {
              setNotice(null)
              setFailure(null)
              setEditing('new')
            }}
          >
            Add contact method
          </Button>
        ) : undefined
      }
    >
      <div className="flex flex-col gap-3">
        {notice !== null ? <Alert tone="success">{notice}</Alert> : null}
        {failure !== null ? (
          <Alert key={failure.attempt} tone="error" focusOnMount>
            {failure.message}
          </Alert>
        ) : null}

        {editing === 'new' ? (
          <MethodForm
            personId={personId}
            existing={record.contactMethods}
            onDone={(message) => {
              setEditing(null)
              setNotice(message)
              void refresh()
            }}
            onCancel={() => {
              setEditing(null)
            }}
          />
        ) : null}

        {record.contactMethods.length === 0 && editing !== 'new' ? (
          <p className="text-body text-muted-foreground">No contact methods recorded.</p>
        ) : null}

        {record.contactMethods.length > 0 ? (
          <ul aria-label="Contact methods" className="flex flex-col divide-y divide-border">
            {record.contactMethods.map((method) => (
              <li key={method.id} className="py-3 first:pt-0 last:pb-0">
                {editing !== null && editing !== 'new' && editing.id === method.id ? (
                  <MethodForm
                    personId={personId}
                    method={method}
                    existing={record.contactMethods}
                    onDone={(message) => {
                      setEditing(null)
                      setNotice(message)
                      void refresh()
                    }}
                    onCancel={() => {
                      setEditing(null)
                    }}
                  />
                ) : (
                  <MethodRow
                    method={method}
                    mayManage={mayManage}
                    onEdit={() => {
                      setNotice(null)
                      setFailure(null)
                      setEditing(method)
                    }}
                    onMakePrimary={() => {
                      void makePrimary(method)
                    }}
                    onRemove={() => {
                      setNotice(null)
                      setFailure(null)
                      setRemoving(method)
                    }}
                  />
                )}
              </li>
            ))}
          </ul>
        ) : null}
      </div>

      {removing !== null ? (
        <ConfirmDialog
          title="Remove this contact method?"
          confirmLabel="Remove"
          destructive
          onConfirm={confirmRemove}
          onCancel={() => {
            setRemoving(null)
          }}
        >
          <p>
            Remove the {contactKindLabel(removing.kind).toLowerCase()}{' '}
            <strong className="wrap-anywhere">{removing.value}</strong> from{' '}
            {record.person.displayName}?
          </p>
          {removing.isPrimary ? (
            <p>
              It is the primary {contactKindLabel(removing.kind).toLowerCase()}. If they have
              another, the earliest one recorded becomes the primary.
            </p>
          ) : null}
        </ConfirmDialog>
      ) : null}
    </Panel>
  )
}

function MethodRow({
  method,
  mayManage,
  onEdit,
  onMakePrimary,
  onRemove,
}: {
  method: ContactMethod
  mayManage: boolean
  onEdit: () => void
  onMakePrimary: () => void
  onRemove: () => void
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
      <div className="flex min-w-0 flex-col gap-0.5">
        <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-body text-foreground">
          <span className="text-muted-foreground">{contactKindLabel(method.kind)}</span>
          <span className="font-medium wrap-anywhere">{method.value}</span>
          {method.isPrimary ? <Badge variant="accent">Primary</Badge> : null}
        </p>
        {method.label !== null ? (
          <p className="text-meta wrap-anywhere text-muted-foreground">{method.label}</p>
        ) : null}
      </div>
      {mayManage ? (
        <div className="flex flex-wrap items-center gap-2">
          {method.isPrimary ? null : (
            <Button size="sm" onClick={onMakePrimary} aria-label={`Make ${method.value} primary`}>
              Make primary
            </Button>
          )}
          <Button size="sm" onClick={onEdit} aria-label={`Edit ${method.value}`}>
            Edit
          </Button>
          <Button
            size="sm"
            variant="danger"
            onClick={onRemove}
            aria-label={`Remove ${method.value}`}
          >
            Remove
          </Button>
        </div>
      ) : null}
    </div>
  )
}

/**
 * Add (no `method`) or edit (a `method`) one contact method. An edit changes the value and the label only: the kind never
 * changes, and promoting to primary is its own action on the row, so this form never asks the server for something it
 * refuses (un-setting the primary).
 */
function MethodForm({
  personId,
  method,
  existing,
  onDone,
  onCancel,
}: {
  personId: string
  method?: ContactMethod
  existing: readonly ContactMethod[]
  onDone: (message: string) => void
  onCancel: () => void
}) {
  const adding = method === undefined
  const [kind, setKind] = useState<ContactMethodKind>('email')
  const [value, setValue] = useState(method?.value ?? '')
  const [label, setLabel] = useState(method?.label ?? '')
  const [makePrimary, setMakePrimary] = useState(false)
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)

  const kindWord = contactKindLabel(adding ? kind : method.kind).toLowerCase()
  const hasOfKind = existing.some((m) => m.kind === kind)

  async function submit() {
    setPending(true)
    const trimmedLabel = label.trim()
    const result = adding
      ? await addContactMethod(personId, {
          kind,
          value,
          label: trimmedLabel === '' ? null : trimmedLabel,
          isPrimary: hasOfKind && makePrimary,
        })
      : await updateContactMethod(personId, method.id, {
          ...(value.trim() !== method.value && { value }),
          ...(trimmedLabel !== (method.label ?? '') && {
            label: trimmedLabel === '' ? null : trimmedLabel,
          }),
        })
    setPending(false)
    if (result.ok) {
      onDone(adding ? 'Contact method added.' : 'Contact method saved.')
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const next = describePeopleFailure(result.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <form
      aria-label={adding ? 'Add contact method' : `Edit ${method.value}`}
      onSubmit={(event: SyntheticEvent) => {
        event.preventDefault()
        if (!adding && value.trim() === method.value && label.trim() === (method.label ?? '')) {
          onCancel() // nothing changed: nothing to send
          return
        }
        void submit()
      }}
      className="flex flex-col gap-4 rounded-md border border-border bg-muted/40 p-3"
    >
      {problem && Object.keys(problem.fields).length === 0 ? (
        <Alert key={problem.attempt} tone="error" focusOnMount>
          {problem.message}
        </Alert>
      ) : null}
      {adding ? (
        <Field label="Kind">
          {(control) => (
            <Select
              {...control}
              name="kind"
              value={kind}
              onChange={(event) => {
                setKind(event.target.value as ContactMethodKind)
              }}
            >
              <option value="email">Email</option>
              <option value="phone">Phone</option>
            </Select>
          )}
        </Field>
      ) : null}
      <TextField
        label={adding ? 'Email or phone number' : contactKindLabel(method.kind)}
        name="value"
        autoComplete="off"
        value={value}
        onChange={setValue}
        errors={problem?.fields.value}
        maxLength={255}
      />
      <TextField
        label="Label"
        name="label"
        autoComplete="off"
        value={label}
        onChange={setLabel}
        errors={problem?.fields.label}
        hint="Optional, for example “work” or “mobile”."
        required={false}
        maxLength={64}
      />
      {adding ? (
        hasOfKind ? (
          <Checkbox
            label={`Make this the primary ${kindWord}`}
            checked={makePrimary}
            onChange={(event) => {
              setMakePrimary(event.target.checked)
            }}
          />
        ) : (
          <p className="text-meta text-muted-foreground">
            This will be the primary {kindWord}, as it is the first.
          </p>
        )
      ) : null}
      <div className="flex flex-wrap gap-3">
        <SubmitButton pending={pending} pendingLabel="Saving…">
          {adding ? 'Add contact method' : 'Save'}
        </SubmitButton>
        <Button disabled={pending} onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  )
}
