import { useCallback, useState, type SyntheticEvent } from 'react'

import type { Result } from '../api/http.ts'
import {
  findCandidates,
  type Candidate,
  type CandidateList,
  type RelationshipType,
} from '../api/relationships.ts'
import { Alert } from '../ui/Alert.tsx'
import { Button } from '../ui/Button.tsx'
import { Field } from '../ui/Field.tsx'
import { Input } from '../ui/Input.tsx'
import { Panel } from '../ui/Panel.tsx'
import { SkeletonRegion, SkeletonText } from '../ui/Skeleton.tsx'
import { TextLink } from '../ui/TextLink.tsx'
import { useLoad } from '../ui/useLoad.ts'
import { choiceFor, MIN_QUERY, sharedNames } from './candidates.ts'
import { personName, stateLabel } from './definitions.ts'
import { describeRelationshipFailure } from './wording.ts'

const matchWords: Record<string, string> = {
  display_name: 'matched the name',
  email: 'matched the email address',
  phone: 'matched the phone number',
}

/**
 * Step one of intake: find the person, so nobody is recorded twice (ADR 0038, C6). The search is the relationship-specific
 * lookup and shows only what it returns: a name, what matched, and the person's status in THIS type. Nothing about any
 * contact method, Account, other relationship or role is ever on this screen.
 */
export function IntakeSearch({
  type,
  canAddNew,
  onSelect,
  onAddNew,
}: {
  type: RelationshipType
  canAddNew: boolean
  onSelect: (candidate: Candidate) => void
  onAddNew: () => void
}) {
  const [typed, setTyped] = useState('')
  const [submitted, setSubmitted] = useState('')
  const [tooShort, setTooShort] = useState(false)
  const [attempt, setAttempt] = useState(0)

  const load = useCallback(
    (signal: AbortSignal): Promise<Result<CandidateList>> =>
      submitted === ''
        ? Promise.resolve({ ok: true, value: { candidates: [], truncated: false } })
        : findCandidates(type.slug, submitted, signal),
    // `attempt` is not read: a new load function is how "Try again" asks for the same search again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [type.slug, submitted, attempt],
  )
  const [found] = useLoad(load)

  const shared = found.status === 'loaded' ? sharedNames(found.value.candidates) : new Set<string>()
  const truncated = found.status === 'loaded' && found.value.truncated
  const anyAmbiguous =
    found.status === 'loaded' &&
    found.value.candidates.some((c) => choiceFor(c, shared, truncated).kind === 'ambiguous')

  return (
    <Panel
      title="Find the person"
      description="Search by name, or by an exact email address or phone number, to check they are not already here."
    >
      <div className="flex flex-col gap-4">
        <form
          role="search"
          aria-label="Find the person"
          onSubmit={(event: SyntheticEvent) => {
            event.preventDefault()
            const trimmed = typed.trim()
            if (trimmed.length < MIN_QUERY) {
              setTooShort(true)
              return
            }
            setTooShort(false)
            setSubmitted(trimmed)
          }}
          className="flex flex-wrap items-end gap-3"
        >
          <div className="w-full sm:w-80">
            <Field
              label="Name, email or phone"
              error={tooShort ? `Type at least ${String(MIN_QUERY)} characters.` : undefined}
            >
              {(control) => (
                <Input
                  {...control}
                  type="search"
                  value={typed}
                  onChange={(event) => {
                    setTyped(event.target.value)
                  }}
                  maxLength={255}
                  autoComplete="off"
                />
              )}
            </Field>
          </div>
          <Button type="submit" className="max-sm:w-full">
            Search
          </Button>
        </form>

        {submitted !== '' && found.status === 'loading' ? (
          <SkeletonRegion label="Searching…" visibleLabel>
            <SkeletonText lines={2} />
          </SkeletonRegion>
        ) : null}
        {found.status === 'failed' ? (
          <div className="flex flex-col items-start gap-3">
            <Alert tone="error">{describeRelationshipFailure(found.failure, type).message}</Alert>
            <Button
              onClick={() => {
                setAttempt((n) => n + 1)
              }}
            >
              Try again
            </Button>
          </div>
        ) : null}
        {submitted !== '' && found.status === 'loaded' ? (
          <div className="flex flex-col gap-3">
            {found.value.candidates.length === 0 ? (
              <p role="status" className="text-body text-muted-foreground">
                No one matches &ldquo;{submitted}&rdquo;.
              </p>
            ) : (
              <ul
                aria-label="People found"
                className="flex flex-col divide-y divide-border rounded-md border border-border"
              >
                {found.value.candidates.map((candidate) => {
                  const choice = choiceFor(candidate, shared, found.value.truncated)
                  return (
                    <li
                      key={candidate.person.id}
                      className="flex flex-wrap items-center justify-between gap-3 p-3"
                    >
                      <div className="min-w-0 text-body">
                        <p className="font-medium wrap-anywhere text-foreground">
                          {personName(candidate.person)}
                        </p>
                        <p className="text-meta text-muted-foreground">
                          {matchWords[candidate.matchedOn] ?? candidate.matchedOn}
                          {choice.kind === 'already'
                            ? ` · already ${stateLabel(type, choice.status)}`
                            : ''}
                        </p>
                      </div>
                      {choice.kind === 'choose' ? (
                        <Button
                          size="sm"
                          aria-label={`Choose ${personName(candidate.person)}`}
                          onClick={() => {
                            onSelect(candidate)
                          }}
                        >
                          Choose
                        </Button>
                      ) : choice.kind === 'already' ? (
                        <TextLink
                          to={`/relationships/${type.slug}/${candidate.person.id}`}
                          aria-label={`Open ${personName(candidate.person)}'s record`}
                        >
                          Open the record
                        </TextLink>
                      ) : null}
                    </li>
                  )
                })}
              </ul>
            )}
            {anyAmbiguous ? (
              <Alert tone="info">
                {truncated
                  ? 'More people match than are shown, and a name alone cannot tell them apart.'
                  : 'More than one person has the same name, and a name alone cannot tell them apart.'}{' '}
                Search again with an exact email address or phone number to choose the right one
                {canAddNew ? ', or add them as someone new' : ''}.
              </Alert>
            ) : null}
            {found.value.truncated && !anyAmbiguous ? (
              <p role="status" className="text-meta text-muted-foreground">
                More people match than are shown. Add more to your search to narrow it.
              </p>
            ) : null}
          </div>
        ) : null}

        {canAddNew ? (
          <div>
            <Button onClick={onAddNew}>Add someone new</Button>
          </div>
        ) : null}
      </div>
    </Panel>
  )
}
