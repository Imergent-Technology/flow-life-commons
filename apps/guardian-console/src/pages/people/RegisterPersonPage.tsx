import { useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import {
  duplicateCandidates,
  registerPerson,
  type DuplicateCandidate,
  type NewContactMethod,
  type PersonRecord,
} from '../../api/people.ts'
import type { FieldErrors } from '../../api/http.ts'
import { describePeopleFailure, matchedOnLabel } from '../../admin/peopleWording.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Button } from '../../ui/Button.tsx'
import { Field } from '../../ui/Field.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { type Problem } from '../../ui/problem.ts'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { Textarea } from '../../ui/Textarea.tsx'
import { TextField } from '../../ui/TextField.tsx'

const blank = { displayName: '', howWeKnow: '', affiliation: '', email: '', phone: '' }

const orNull = (text: string): string | null => (text.trim() === '' ? null : text.trim())

/**
 * Adds a NEW Person with no Account, invitation, sign-in, Membership or access (ADR 0015, ADR 0034), and what is known
 * about them. If the server thinks they may already be in the directory it says so and creates nothing; that is advice only.
 * The Guardian either goes to look at the candidates or states, deliberately, that this is a distinct person: the same
 * request is then sent with `confirm_distinct`. Nothing is ever merged or adopted.
 */
export function RegisterPersonPage() {
  const [form, setForm] = useState(blank)
  const [pending, setPending] = useState<'register' | 'distinct' | null>(null)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const [candidates, setCandidates] = useState<DuplicateCandidate[] | null>(null)
  const [result, setResult] = useState<PersonRecord | null>(null)

  async function submit(confirmDistinct: boolean) {
    setPending(confirmDistinct ? 'distinct' : 'register')
    setCandidates(null)
    const contactMethods: NewContactMethod[] = []
    if (form.email.trim() !== '') contactMethods.push({ kind: 'email', value: form.email })
    if (form.phone.trim() !== '') contactMethods.push({ kind: 'phone', value: form.phone })

    const outcome = await registerPerson({
      displayName: form.displayName.trim(),
      howWeKnow: orNull(form.howWeKnow),
      affiliation: orNull(form.affiliation),
      contactMethods,
      confirmDistinct,
    })
    setPending(null)

    if (outcome.ok) {
      setProblem(null)
      setResult(outcome.value)
      return
    }
    if (outcome.failure.kind === 'unauthenticated') return // the session ended; the boundary handles it
    const advice = duplicateCandidates(outcome.failure)
    if (advice !== null) {
      setProblem(null)
      setCandidates(advice)
      return
    }
    const next = describePeopleFailure(outcome.failure)
    // The server names a method by its place in the list we sent; the form names it by its box.
    const fields: FieldErrors = { ...next.fields }
    contactMethods.forEach((method, index) => {
      const errors = next.fields[`contact_methods.${String(index)}.value`]
      if (errors !== undefined) fields[method.kind] = errors
    })
    setProblem((previous) => ({
      message: next.message,
      fields,
      attempt: (previous?.attempt ?? 0) + 1,
    }))
  }

  if (result !== null) {
    return (
      <Page width="form">
        <PageHeader title="Person added" />
        <Alert tone="success" focusOnMount>
          {result.person.displayName} was added to the directory. They have no account and no
          sign-in.
        </Alert>
        <div className="flex flex-wrap gap-3">
          <Link
            to={`/people/${result.person.id}`}
            className={buttonVariants({ variant: 'secondary' })}
          >
            Open the person
          </Link>
          <Button
            onClick={() => {
              setResult(null)
              setForm(blank)
            }}
          >
            Add another person
          </Button>
        </div>
      </Page>
    )
  }

  return (
    <Page width="form">
      <PageHeader
        title="Add a person"
        description="This adds someone to the directory. It does not create a Console account, an invitation or any way to sign in."
      />
      {problem && Object.keys(problem.fields).length === 0 ? (
        <Alert key={problem.attempt} tone="error" focusOnMount>
          {problem.message}
        </Alert>
      ) : null}
      {candidates !== null ? (
        <Panel
          tone="default"
          title="This person may already be in the directory"
          description="Nothing has been added yet. Check these people first. If this is someone new, say so and they will be added as a separate person."
        >
          <div role="alert" className="flex flex-col gap-4">
            <ul aria-label="Possible duplicates" className="flex flex-col gap-2">
              {candidates.map((candidate) => (
                <li key={candidate.id} className="text-body text-foreground">
                  <Link
                    to={`/people/${candidate.id}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium underline underline-offset-2"
                  >
                    {candidate.displayName}
                  </Link>{' '}
                  <span className="text-muted-foreground">
                    ({matchedOnLabel(candidate.matchedOn)})
                  </span>
                </li>
              ))}
            </ul>
            <div className="flex flex-wrap gap-3">
              <Button
                variant="primary"
                pending={pending === 'distinct'}
                pendingLabel="Adding…"
                onClick={() => {
                  void submit(true)
                }}
              >
                This is a different person: add anyway
              </Button>
              <Button
                disabled={pending !== null}
                onClick={() => {
                  setCandidates(null)
                }}
              >
                Go back and change the details
              </Button>
            </div>
          </div>
        </Panel>
      ) : null}
      <Panel>
        <form
          aria-label="Add a person"
          onSubmit={(event: SyntheticEvent) => {
            event.preventDefault()
            void submit(false)
          }}
          className="flex flex-col gap-4"
        >
          <TextField
            label="Display name"
            name="display_name"
            autoComplete="off"
            value={form.displayName}
            onChange={(displayName) => {
              setForm({ ...form, displayName })
            }}
            errors={problem?.fields.display_name}
            maxLength={255}
          />
          <TextField
            label="Email"
            name="email"
            type="email"
            autoComplete="off"
            value={form.email}
            onChange={(email) => {
              setForm({ ...form, email })
            }}
            errors={problem?.fields.email}
            hint="Optional. Recorded as a contact method, not as a sign-in."
            required={false}
            maxLength={255}
          />
          <TextField
            label="Phone"
            name="phone"
            autoComplete="off"
            value={form.phone}
            onChange={(phone) => {
              setForm({ ...form, phone })
            }}
            errors={problem?.fields.phone}
            hint="Optional."
            required={false}
            maxLength={64}
          />
          <Field label="How we know them" error={problem?.fields.how_we_know?.join(' ')}>
            {(control) => (
              <Textarea
                {...control}
                name="how_we_know"
                value={form.howWeKnow}
                onChange={(event) => {
                  setForm({ ...form, howWeKnow: event.target.value })
                }}
                maxLength={2000}
              />
            )}
          </Field>
          <TextField
            label="Affiliation"
            name="affiliation"
            autoComplete="off"
            value={form.affiliation}
            onChange={(affiliation) => {
              setForm({ ...form, affiliation })
            }}
            errors={problem?.fields.affiliation}
            required={false}
            maxLength={255}
          />
          <div className="flex flex-wrap gap-3">
            <SubmitButton pending={pending === 'register'} pendingLabel="Adding…">
              Add person
            </SubmitButton>
            <Link to="/people" className={buttonVariants({ variant: 'secondary' })}>
              Cancel
            </Link>
          </div>
        </form>
      </Panel>
    </Page>
  )
}
