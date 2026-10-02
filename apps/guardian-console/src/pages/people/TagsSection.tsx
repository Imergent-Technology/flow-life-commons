import { useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { describePeopleFailure } from '../../admin/peopleWording.ts'
import type { PersonRecord } from '../../api/people.ts'
import { listTags, setPersonTags, type Tag } from '../../api/tags.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Badge } from '../../ui/Badge.tsx'
import { Button } from '../../ui/Button.tsx'
import { Checkbox } from '../../ui/Checkbox.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { TextLink } from '../../ui/TextLink.tsx'

/**
 * The Person's tags (ADR 0034): labels a Guardian made, shown as words. A tag grants no access and says nothing about
 * Membership or volunteering, and the panel says so in one line. Changing which tags the Person holds is offered to
 * `crm.people.manage` only: it chooses from the vocabulary the server holds and sends the whole chosen set, which is what the
 * API takes. Which tags exist is managed on the Tags page.
 */
export function TagsSection({
  record,
  mayManage,
  refresh,
}: {
  record: PersonRecord
  mayManage: boolean
  refresh: () => Promise<void>
}) {
  const [editing, setEditing] = useState(false)
  const [vocabulary, setVocabulary] = useState<Tag[] | null>(null)
  const [chosen, setChosen] = useState<ReadonlySet<string>>(new Set())
  const [pending, setPending] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [failure, setFailure] = useState<{ message: string; attempt: number } | null>(null)

  function fail(message: string) {
    setFailure((previous) => ({ message, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  async function begin() {
    setNotice(null)
    setFailure(null)
    setChosen(new Set(record.tags.map((tag) => tag.id)))
    setVocabulary(null)
    setEditing(true)
    const result = await listTags()
    if (result.ok) {
      setVocabulary(result.value)
    } else {
      setEditing(false)
      if (result.failure.kind !== 'unauthenticated') {
        fail(describePeopleFailure(result.failure, 'tag').message)
      }
    }
  }

  async function save() {
    const before = new Set(record.tags.map((tag) => tag.id))
    const unchanged = chosen.size === before.size && [...chosen].every((id) => before.has(id))
    if (unchanged) {
      setEditing(false)
      return
    }
    setPending(true)
    const result = await setPersonTags(record.person.id, [...chosen])
    setPending(false)
    if (result.ok) {
      setEditing(false)
      setNotice('Tags saved.')
      await refresh()
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    fail(describePeopleFailure(result.failure, 'tag').message)
    // A tag deleted since the list loaded is the likely reason: show the vocabulary as it is now.
    const fresh = await listTags()
    if (fresh.ok) setVocabulary(fresh.value)
  }

  return (
    <Panel
      title="Tags"
      description="Labels for finding and grouping people. A tag grants no access."
      actions={
        mayManage && !editing ? (
          <Button
            size="sm"
            onClick={() => {
              void begin()
            }}
          >
            Edit tags
          </Button>
        ) : undefined
      }
    >
      <div className="flex flex-col gap-3">
        {notice !== null && !editing ? <Alert tone="success">{notice}</Alert> : null}
        {failure !== null ? (
          <Alert key={failure.attempt} tone="error" focusOnMount>
            {failure.message}
          </Alert>
        ) : null}

        {editing ? (
          vocabulary === null ? (
            <p role="status" className="text-body text-muted-foreground">
              Loading tags…
            </p>
          ) : (
            <form
              aria-label="Edit tags"
              onSubmit={(event: SyntheticEvent) => {
                event.preventDefault()
                void save()
              }}
              className="flex flex-col gap-4"
            >
              {vocabulary.length === 0 ? (
                <p className="text-body text-muted-foreground">
                  There are no tags yet. Create some on the{' '}
                  <TextLink to="/people/tags">Tags page</TextLink>.
                </p>
              ) : (
                <fieldset className="flex flex-col gap-2">
                  <legend className="mb-1 text-label font-medium text-foreground">Tags</legend>
                  <div className="flex flex-col gap-2">
                    {vocabulary.map((tag) => (
                      <Checkbox
                        key={tag.id}
                        label={tag.name}
                        checked={chosen.has(tag.id)}
                        onChange={(event) => {
                          const next = new Set(chosen)
                          if (event.target.checked) next.add(tag.id)
                          else next.delete(tag.id)
                          setChosen(next)
                        }}
                      />
                    ))}
                  </div>
                </fieldset>
              )}
              <div className="flex flex-wrap gap-3">
                <SubmitButton pending={pending} pendingLabel="Saving…">
                  Save tags
                </SubmitButton>
                <Button
                  disabled={pending}
                  onClick={() => {
                    setEditing(false)
                    setFailure(null)
                  }}
                >
                  Cancel
                </Button>
              </div>
            </form>
          )
        ) : record.tags.length === 0 ? (
          <p className="text-body text-muted-foreground">No tags.</p>
        ) : (
          <ul aria-label="Tags" className="flex flex-wrap gap-2">
            {record.tags.map((tag) => (
              <li key={tag.id}>
                <Badge
                  variant="neutral"
                  indicator="none"
                  className="wrap-anywhere whitespace-normal"
                >
                  {tag.name}
                </Badge>
              </li>
            ))}
          </ul>
        )}
        {mayManage && !editing ? (
          <Link
            to="/people/tags"
            className="self-start text-meta text-muted-foreground underline underline-offset-2 hover:text-foreground"
          >
            Manage the list of tags
          </Link>
        ) : null}
      </div>
    </Panel>
  )
}
