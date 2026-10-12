import { useState, type SyntheticEvent } from 'react'
import { Link, useNavigate } from 'react-router'

import { useAdminAction } from '../admin/useAdminAction.ts'
import type { FieldErrors } from '../api/http.ts'
import {
  duplicateAdvice,
  establishRelationship,
  type Candidate,
  type DuplicateAdvice,
  type PersonRef,
  type RelationshipType,
} from '../api/relationships.ts'
import { Alert } from '../ui/Alert.tsx'
import { buttonVariants } from '../ui/button-variants.ts'
import { Button } from '../ui/Button.tsx'
import { Checkbox } from '../ui/Checkbox.tsx'
import { ConfirmDialog, type ConfirmResult } from '../ui/ConfirmDialog.tsx'
import { Field } from '../ui/Field.tsx'
import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { Panel } from '../ui/Panel.tsx'
import { Select } from '../ui/Select.tsx'
import { TextField } from '../ui/TextField.tsx'
import { choiceToSend, initialChoice, unavailableExplanation } from './defaultRole.ts'
import { hasFeature, personName, stateLabel, stateQualifies, withArticle } from './definitions.ts'
import { draftFrom, prepareChanges, type Draft } from './fieldEditing.ts'
import { FieldInput } from './FieldInput.tsx'
import { IntakeSearch } from './IntakeSearch.tsx'
import { describeRelationshipFailure } from './wording.ts'

type Subject = { kind: 'existing'; person: PersonRef } | { kind: 'new' }

const blankPerson = { displayName: '', email: '', phone: '' }
const orNull = (text: string): string | null => (text.trim() === '' ? null : text.trim())

const matchLabels: Record<string, string> = { email: 'same email', display_name: 'same name' }

/**
 * Recording a person in a relationship type (ADR 0038, C6), as a guided workflow rather than a second registration screen.
 *
 *   1. Find the person. Names that several people share are never choosable by name alone.
 *   2. Choose an existing person, which opens a confirmation that names them (it is what sends `confirm_existing_person`),
 *      or add someone new where the definition allows it, with CRM's duplicate advice if they look already known.
 *   3. Choose the initial status (the definition's initial states), fill in the optional details and, for a state that
 *      counts in a type with a default role, make the access decision (K5).
 *   4. A final confirmation says who, what status and whether access will be granted. Only then is anything sent, and a
 *      request that is still pending is never presented as a recording.
 *
 * Every part of this (labels, states, fields, features, the default role) comes from the served definition.
 */
export function RelationshipIntake({
  type,
  preselected = null,
}: {
  type: RelationshipType
  /** A person the operator came from (the Person record). They still go through the existing-person confirmation. */
  preselected?: PersonRef | null
}) {
  const navigate = useNavigate()
  const run = useAdminAction()
  const noun = type.labels.singular
  const canLookup = hasFeature(type, 'people.lookup')
  const canAddNew = hasFeature(type, 'people.create_person')

  const [subject, setSubject] = useState<Subject | null>(null)
  const [confirming, setConfirming] = useState<PersonRef | null>(preselected)
  const [person, setPerson] = useState(blankPerson)
  const [status, setStatus] = useState('')
  const [draft, setDraft] = useState<Draft>(() => draftFrom(type.fields, {}))
  const [touched, setTouched] = useState<boolean | null>(null)
  const [review, setReview] = useState(false)
  const [distinct, setDistinct] = useState(false)
  const [advice, setAdvice] = useState<DuplicateAdvice[] | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [banner, setBanner] = useState<{
    message: string
    attempt: number
    existsFor?: string
  } | null>(null)

  const decides = type.defaultRole !== null && status !== '' && stateQualifies(type, status)
  const option = initialChoice({ mayProvision: type.actions.provisionDefaultRole, stored: null })
  const checked = option.available && (touched ?? option.checked)
  const subjectName =
    subject === null
      ? ''
      : subject.kind === 'existing'
        ? personName(subject.person)
        : person.displayName.trim()

  function say(message: string, existsFor?: string) {
    setBanner((previous) => ({
      message,
      attempt: (previous?.attempt ?? 0) + 1,
      ...(existsFor !== undefined && { existsFor }),
    }))
  }

  function choose(candidate: Candidate) {
    setConfirming(candidate.person)
  }

  /** Advisory checks before anything is confirmed. The server judges every one of them again. */
  function validate(): FieldErrors {
    const found: FieldErrors = {}
    if (subject?.kind === 'new' && person.displayName.trim() === '') {
      found['new_person.display_name'] = ['Enter a name.']
    }
    if (status === '') found.status = ['Choose a status.']
    for (const [key, message] of Object.entries(prepareChanges(type.fields, {}, draft).problems)) {
      found[key] = [message]
    }
    return found
  }

  function openReview(event: SyntheticEvent) {
    event.preventDefault()
    const found = validate()
    setErrors(found)
    setBanner(null)
    if (Object.keys(found).length === 0) setReview(true)
  }

  async function record(): Promise<ConfirmResult> {
    if (subject === null) return { kind: 'done' }
    const { changes } = prepareChanges(type.fields, {}, draft)
    const outcome = await run(() =>
      establishRelationship(type.slug, {
        subject:
          subject.kind === 'existing'
            ? { kind: 'existing', personId: subject.person.id }
            : {
                kind: 'new',
                person: {
                  displayName: person.displayName.trim(),
                  email: orNull(person.email),
                  phone: orNull(person.phone),
                },
                confirmDistinct: distinct,
              },
        status,
        fields: changes,
        // Sent only for a state that counts in a type with a default role, and then always explicitly (K5, K7).
        ...(decides && { defaultRole: choiceToSend(option, checked) }),
      }),
    )

    if (outcome.status === 'done') {
      const who = personName(outcome.value.person)
      void navigate(`/relationships/${type.slug}/${outcome.value.person.id}`, {
        state: { notice: `${who} was recorded as ${withArticle(noun)}.` },
      })
      return { kind: 'done' }
    }
    if (outcome.status === 'verify') {
      return {
        kind: 'stay',
        tone: 'info',
        message: outcome.verified
          ? 'You are verified. Nothing has been recorded yet: confirm again to continue.'
          : 'Verification was cancelled. Nothing has been recorded.',
      }
    }

    const { failure } = outcome
    const similar = duplicateAdvice(failure)
    if (similar !== null) {
      setReview(false)
      setAdvice(similar)
      return { kind: 'done' }
    }
    if (failure.kind === 'conflict' && failure.code === 'relationship_exists') {
      setReview(false)
      say(
        describeRelationshipFailure(failure, type).message,
        subject.kind === 'existing' ? subject.person.id : undefined,
      )
      return { kind: 'done' }
    }
    if (failure.kind === 'invalid' && Object.keys(failure.errors).length > 0) {
      // The server's word for a field lands on that field, back on the form.
      setReview(false)
      setErrors(failure.errors)
      say(describeRelationshipFailure(failure, type).message)
      return { kind: 'done' }
    }
    return {
      kind: 'stay',
      tone: 'error',
      message: describeRelationshipFailure(failure, type).message,
    }
  }

  const nothingToDo = !canLookup && !canAddNew

  return (
    <Page width="form">
      <PageHeader
        title={`Record ${withArticle(noun)}`}
        description={`Find the person first, so nobody is recorded twice. Nothing is recorded until you confirm.`}
      />

      {banner === null ? null : (
        <Alert key={banner.attempt} tone="error" focusOnMount>
          <p>{banner.message}</p>
          {banner.existsFor === undefined ? null : (
            <p className="mt-1">
              <Link
                to={`/relationships/${type.slug}/${banner.existsFor}`}
                className="font-medium underline underline-offset-2"
              >
                Open their record
              </Link>
            </p>
          )}
        </Alert>
      )}

      {nothingToDo ? (
        <Alert tone="info">
          People cannot be recorded as {type.labels.plural.toLowerCase()} from here.
        </Alert>
      ) : null}

      {advice !== null ? (
        <Panel
          title="This person may already be known"
          description="Nothing has been recorded yet. Check these people first. If this is someone new, say so and they will be added as a separate person."
        >
          <div role="alert" className="flex flex-col gap-4">
            <ul aria-label="Possible duplicates" className="flex flex-col gap-2">
              {advice.map((candidate) => (
                <li
                  key={candidate.id}
                  className="flex flex-wrap items-center gap-x-3 gap-y-1 text-body text-foreground"
                >
                  <span className="font-medium wrap-anywhere">{candidate.displayName}</span>
                  <span className="text-muted-foreground">
                    ({candidate.matchedOn.map((m) => matchLabels[m] ?? m).join(', ')})
                  </span>
                  <Button
                    size="sm"
                    aria-label={`Record ${candidate.displayName} instead`}
                    onClick={() => {
                      setAdvice(null)
                      setConfirming({ id: candidate.id, displayName: candidate.displayName })
                    }}
                  >
                    Record this person instead
                  </Button>
                </li>
              ))}
            </ul>
            <div className="flex flex-wrap gap-3">
              <Button
                variant="primary"
                onClick={() => {
                  setDistinct(true)
                  setAdvice(null)
                  setReview(true)
                }}
              >
                This is a different person: record anyway
              </Button>
              <Button
                onClick={() => {
                  setAdvice(null)
                }}
              >
                Go back and change the details
              </Button>
            </div>
          </div>
        </Panel>
      ) : null}

      {subject === null && !nothingToDo ? (
        canLookup ? (
          <IntakeSearch
            type={type}
            canAddNew={canAddNew}
            onSelect={choose}
            onAddNew={() => {
              setSubject({ kind: 'new' })
              setDistinct(false)
            }}
          />
        ) : (
          <Panel title="Add someone new">
            <Button
              onClick={() => {
                setSubject({ kind: 'new' })
              }}
            >
              Add someone new
            </Button>
          </Panel>
        )
      ) : null}

      {subject === null ? null : (
        <form
          aria-label={`Record ${withArticle(noun)}`}
          onSubmit={openReview}
          noValidate
          className="flex flex-col gap-6"
        >
          <Panel title={subject.kind === 'existing' ? 'Person' : 'New person'}>
            {subject.kind === 'existing' ? (
              <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-body font-medium text-foreground">
                  {personName(subject.person)}
                </p>
                <Button
                  onClick={() => {
                    setSubject(null)
                    setErrors({})
                  }}
                >
                  Choose someone else
                </Button>
              </div>
            ) : (
              <div className="flex flex-col gap-4">
                <TextField
                  label="Display name"
                  name="display_name"
                  autoComplete="off"
                  value={person.displayName}
                  onChange={(displayName) => {
                    setPerson({ ...person, displayName })
                  }}
                  errors={errors['new_person.display_name']}
                  maxLength={255}
                />
                <TextField
                  label="Email"
                  name="email"
                  type="email"
                  autoComplete="off"
                  value={person.email}
                  onChange={(email) => {
                    setPerson({ ...person, email })
                  }}
                  errors={errors['new_person.email']}
                  hint="Optional. Recorded as a contact method, not as a sign-in."
                  required={false}
                  maxLength={255}
                />
                <TextField
                  label="Phone"
                  name="phone"
                  autoComplete="off"
                  value={person.phone}
                  onChange={(phone) => {
                    setPerson({ ...person, phone })
                  }}
                  errors={errors['new_person.phone']}
                  hint="Optional."
                  required={false}
                  maxLength={64}
                />
                <div>
                  <Button
                    onClick={() => {
                      setSubject(null)
                      setErrors({})
                    }}
                  >
                    Search again instead
                  </Button>
                </div>
              </div>
            )}
          </Panel>

          <Panel title="Status">
            <div className="flex flex-col gap-4">
              <Field label="Initial status" error={errors.status?.join(' ')}>
                {(control) => (
                  <Select
                    {...control}
                    name="status"
                    value={status}
                    onChange={(event) => {
                      setStatus(event.target.value)
                      setTouched(null)
                    }}
                  >
                    <option value="">Choose…</option>
                    {type.initialStates.map((key) => (
                      <option key={key} value={key}>
                        {stateLabel(type, key)}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
              {decides && type.defaultRole !== null ? (
                <div className="flex flex-col gap-1">
                  <Checkbox
                    label={`Grant ${type.defaultRole.displayName} access`}
                    checked={checked}
                    disabled={!option.available}
                    onChange={(event) => {
                      setTouched(event.target.checked)
                    }}
                  />
                  <p className="text-meta text-muted-foreground">
                    {option.available
                      ? `Lets them into the Console with ${type.defaultRole.displayName} access, once they have an Account. You can change this later from the record.`
                      : unavailableExplanation(type.defaultRole.displayName)}
                  </p>
                </div>
              ) : null}
            </div>
          </Panel>

          {type.fields.length === 0 ? null : (
            <Panel title="Details" description="All optional.">
              <div className="flex flex-col gap-4">
                {[...type.fields]
                  .sort((a, b) => a.order - b.order || a.key.localeCompare(b.key))
                  .map((field) => (
                    <FieldInput
                      key={field.key}
                      field={field}
                      value={draft[field.key]}
                      errors={errors[field.key]}
                      onChange={(value) => {
                        setDraft({ ...draft, [field.key]: value })
                      }}
                    />
                  ))}
              </div>
            </Panel>
          )}

          <div className="flex flex-wrap gap-3">
            <Button type="submit" variant="primary">
              Review and record
            </Button>
            {type.actions.view ? (
              <Link
                to={`/relationships/${type.slug}`}
                className={buttonVariants({ variant: 'secondary' })}
              >
                Cancel
              </Link>
            ) : null}
          </div>
        </form>
      )}

      {confirming === null ? null : (
        <ConfirmDialog
          title={`Record ${personName(confirming)}?`}
          confirmLabel="Continue"
          onCancel={() => {
            setConfirming(null)
          }}
          onConfirm={() => {
            setSubject({ kind: 'existing', person: confirming })
            setConfirming(null)
            setErrors({})
            setBanner(null)
            return Promise.resolve({ kind: 'done' } as const)
          }}
        >
          <p>
            {personName(confirming)} is already in the directory. Recording them as{' '}
            {withArticle(noun)} creates a {noun.toLowerCase()} relationship for them
            {hasFeature(type, 'people.edit_basics')
              ? ', and lets you keep their name, primary email and primary phone up to date from that record'
              : ''}
            .
          </p>
          <p>Nothing is recorded yet. You choose their status and confirm on the next step.</p>
        </ConfirmDialog>
      )}

      {review && subject !== null ? (
        <ConfirmDialog
          title={`Record ${subjectName === '' ? 'this person' : subjectName} as ${withArticle(noun)}?`}
          confirmLabel="Record"
          onCancel={() => {
            setReview(false)
          }}
          onConfirm={record}
        >
          <dl className="flex flex-col gap-1">
            <div>
              <dt className="inline text-muted-foreground">Person: </dt>
              <dd className="inline font-medium">
                {subjectName === '' ? '—' : subjectName}
                {subject.kind === 'new' ? ' (new)' : ''}
              </dd>
            </div>
            <div>
              <dt className="inline text-muted-foreground">Status: </dt>
              <dd className="inline font-medium">{stateLabel(type, status)}</dd>
            </div>
            {decides && type.defaultRole !== null ? (
              <div>
                <dt className="inline text-muted-foreground">Access: </dt>
                <dd className="inline font-medium">
                  {!option.available
                    ? `${type.defaultRole.displayName} access will not be granted: you do not have permission to assign roles.`
                    : checked
                      ? `${type.defaultRole.displayName} access will be granted.`
                      : `${type.defaultRole.displayName} access will not be granted.`}
                </dd>
              </div>
            ) : null}
          </dl>
          <p>Nothing is recorded until you press Record.</p>
        </ConfirmDialog>
      ) : null}
    </Page>
  )
}
