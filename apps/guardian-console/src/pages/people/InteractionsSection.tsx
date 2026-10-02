import { useCallback, useState, type SyntheticEvent } from 'react'

import { instantFromLocal } from '../../admin/membershipTerm.ts'
import { interactionKindLabel, describePeopleFailure } from '../../admin/peopleWording.ts'
import { localInputFrom, shown } from '../../admin/time.ts'
import {
  editInteraction,
  listInteractions,
  recordInteraction,
  removeInteraction,
  type Interaction,
  type InteractionChanges,
  type InteractionKind,
} from '../../api/interactions.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Badge } from '../../ui/Badge.tsx'
import { Button } from '../../ui/Button.tsx'
import { ConfirmDialog, type ConfirmResult } from '../../ui/ConfirmDialog.tsx'
import { EmptyState } from '../../ui/EmptyState.tsx'
import { Field } from '../../ui/Field.tsx'
import { Input } from '../../ui/Input.tsx'
import { Pagination } from '../../ui/Pagination.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { type Problem } from '../../ui/problem.ts'
import { Select } from '../../ui/Select.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { Textarea } from '../../ui/Textarea.tsx'
import { useLoad } from '../../ui/useLoad.ts'

const PER_PAGE = 10

const KINDS: readonly InteractionKind[] = ['note', 'call', 'email', 'meeting']

/** `new` is the record form; an interaction is the edit form for it. Only one form is open at a time. */
type Editing = 'new' | Interaction | null

/**
 * A Person's notes and interactions (ADR 0034): what Guardians recorded about them, newest first by when it happened. The
 * order, the paging and the author are the server's. There are no private notes: everyone who may view people sees every
 * one, which the panel says once. Recording, correcting and removing are offered to `crm.people.manage` only. A removal is
 * permanent and an edit leaves no history, and the wording never suggests otherwise.
 */
export function InteractionsSection({
  personId,
  personName,
  mayManage,
}: {
  personId: string
  personName: string
  mayManage: boolean
}) {
  const [page, setPage] = useState(1)
  // Bumped by a change or "Try again": a new load function is a new request for the current page.
  const [reloads, setReloads] = useState(0)
  const [editing, setEditing] = useState<Editing>(null)
  const [removing, setRemoving] = useState<Interaction | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(
    (signal: AbortSignal) => listInteractions({ personId, page, perPage: PER_PAGE, signal }),
    // `reloads` is not read: a new load function is how a change or "Try again" asks for the page again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [personId, page, reloads],
  )
  const [loaded] = useLoad(load)

  const reload = () => {
    setReloads((n) => n + 1)
  }

  async function confirmRemove(): Promise<ConfirmResult> {
    if (removing === null) return { kind: 'stay', tone: 'error', message: 'Nothing to remove.' }
    const result = await removeInteraction(personId, removing.id)
    if (result.ok) {
      const wasOnlyOneHere = loaded.status === 'loaded' && loaded.value.interactions.length === 1
      setRemoving(null)
      setNotice(`The ${interactionKindLabel(removing.kind).toLowerCase()} was removed.`)
      // The last one on a later page leaves that page empty: step back rather than show nothing.
      if (wasOnlyOneHere && page > 1) setPage(page - 1)
      reload()
      return { kind: 'done' }
    }
    return {
      kind: 'stay',
      tone: 'error',
      message: describePeopleFailure(result.failure, 'interaction').message,
    }
  }

  return (
    <Panel
      title="Notes and interactions"
      description="Visible to everyone who can view people. Write them as though the person could one day ask to read them."
      actions={
        mayManage && editing === null ? (
          <Button
            size="sm"
            onClick={() => {
              setNotice(null)
              setEditing('new')
            }}
          >
            Record a note
          </Button>
        ) : undefined
      }
    >
      <div className="flex flex-col gap-4">
        {notice !== null ? <Alert tone="success">{notice}</Alert> : null}

        {editing === 'new' ? (
          <InteractionForm
            personId={personId}
            onDone={() => {
              setEditing(null)
              setNotice('Recorded.')
              setPage(1) // the newest are first
              reload()
            }}
            onCancel={() => {
              setEditing(null)
            }}
          />
        ) : null}

        {loaded.status === 'loading' ? (
          <SkeletonRegion label="Loading notes…" visibleLabel>
            <SkeletonText lines={3} />
          </SkeletonRegion>
        ) : null}
        {loaded.status === 'failed' ? (
          <div className="flex flex-col items-start gap-3">
            <Alert tone="error">
              {describePeopleFailure(loaded.failure, 'interaction').message}
            </Alert>
            <Button onClick={reload}>Try again</Button>
          </div>
        ) : null}
        {loaded.status === 'loaded' ? (
          <>
            {loaded.value.interactions.length === 0 ? (
              <EmptyState title="No notes or interactions yet." />
            ) : (
              <ul
                aria-label="Notes and interactions"
                className="flex flex-col divide-y divide-border"
              >
                {loaded.value.interactions.map((interaction) => (
                  <li key={interaction.id} className="py-4 first:pt-0 last:pb-0">
                    {editing !== null && editing !== 'new' && editing.id === interaction.id ? (
                      <InteractionForm
                        personId={personId}
                        interaction={interaction}
                        onDone={() => {
                          setEditing(null)
                          setNotice('Saved.')
                          reload()
                        }}
                        onCancel={() => {
                          setEditing(null)
                        }}
                      />
                    ) : (
                      <InteractionRow
                        interaction={interaction}
                        mayManage={mayManage}
                        onEdit={() => {
                          setNotice(null)
                          setEditing(interaction)
                        }}
                        onRemove={() => {
                          setNotice(null)
                          setRemoving(interaction)
                        }}
                      />
                    )}
                  </li>
                ))}
              </ul>
            )}
            {loaded.value.lastPage > 1 ? (
              <Pagination
                page={loaded.value.page}
                lastPage={loaded.value.lastPage}
                total={loaded.value.total}
                noun={{ one: 'note', other: 'notes' }}
                onPageChange={(next) => {
                  setEditing(null)
                  setPage(next)
                }}
              />
            ) : null}
          </>
        ) : null}
      </div>

      {removing !== null ? (
        <ConfirmDialog
          title={`Remove this ${interactionKindLabel(removing.kind).toLowerCase()}?`}
          confirmLabel="Remove"
          destructive
          onConfirm={confirmRemove}
          onCancel={() => {
            setRemoving(null)
          }}
        >
          <p>This permanently removes it from {personName}’s record. It cannot be restored.</p>
          <blockquote className="line-clamp-4 border-l-2 border-border-strong pl-3 wrap-anywhere whitespace-pre-wrap text-muted-foreground">
            {removing.body}
          </blockquote>
        </ConfirmDialog>
      ) : null}
    </Panel>
  )
}

function InteractionRow({
  interaction,
  mayManage,
  onEdit,
  onRemove,
}: {
  interaction: Interaction
  mayManage: boolean
  onEdit: () => void
  onRemove: () => void
}) {
  const kind = interactionKindLabel(interaction.kind)
  const when = shown(interaction.occurredAt)
  return (
    <article aria-label={`${kind}, ${when}`} className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <Badge variant="neutral" indicator="none">
            {kind}
          </Badge>
          <time dateTime={interaction.occurredAt} className="text-body font-medium text-foreground">
            {when}
          </time>
        </p>
        {mayManage ? (
          <div className="flex flex-wrap items-center gap-2">
            <Button
              size="sm"
              onClick={onEdit}
              aria-label={`Edit the ${kind.toLowerCase()} from ${when}`}
            >
              Edit
            </Button>
            <Button
              size="sm"
              variant="danger"
              onClick={onRemove}
              aria-label={`Remove the ${kind.toLowerCase()} from ${when}`}
            >
              Remove
            </Button>
          </div>
        ) : null}
      </div>
      <p className="text-body wrap-anywhere whitespace-pre-wrap text-foreground">
        {interaction.body}
      </p>
      <p className="text-meta text-muted-foreground">
        Recorded by {interaction.author?.displayName ?? 'someone no longer in the directory'}
        {interaction.updatedBy !== null
          ? ` · Last edited by ${interaction.updatedBy.displayName}, ${shown(interaction.updatedAt)}`
          : ''}
      </p>
    </article>
  )
}

/**
 * Record (no `interaction`) or correct (an `interaction`) one note. The kind defaults to a note and the time to now, so the
 * ordinary path is one box and one button. The author is never asked for: the server takes it from the session. A correction
 * sends only what changed, and nothing at all if nothing did.
 */
function InteractionForm({
  personId,
  interaction,
  onDone,
  onCancel,
}: {
  personId: string
  interaction?: Interaction
  onDone: () => void
  onCancel: () => void
}) {
  const recording = interaction === undefined
  const originalWhen = interaction === undefined ? '' : localInputFrom(interaction.occurredAt)
  const [kind, setKind] = useState<InteractionKind>(
    KINDS.find((k) => k === interaction?.kind) ?? 'note',
  )
  const [body, setBody] = useState(interaction?.body ?? '')
  const [when, setWhen] = useState(originalWhen)
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)

  function fail(next: Problem) {
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  async function submit() {
    const occurredAt = instantFromLocal(when)
    if (when !== '' && occurredAt === null) {
      fail({
        message: 'Enter a valid date and time.',
        fields: { occurred_at: ['Enter a valid date and time.'] },
      })
      return
    }

    let result
    if (recording) {
      setPending(true)
      result = await recordInteraction(personId, {
        body,
        kind,
        ...(occurredAt !== null && { occurredAt }),
      })
    } else {
      const changes: InteractionChanges = {
        ...(kind !== interaction.kind && { kind }),
        ...(body.trim() !== interaction.body && { body }),
        // Compared as the form shows it: a time left alone must not be re-sent (it would lose its seconds).
        ...(occurredAt !== null && when !== originalWhen && { occurredAt }),
      }
      if (Object.keys(changes).length === 0) {
        onCancel() // nothing changed: nothing to send
        return
      }
      setPending(true)
      result = await editInteraction(personId, interaction.id, changes)
    }
    setPending(false)
    if (result.ok) {
      onDone()
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    fail(describePeopleFailure(result.failure, 'interaction'))
  }

  return (
    <form
      aria-label={recording ? 'Record a note' : 'Edit this note'}
      onSubmit={(event: SyntheticEvent) => {
        event.preventDefault()
        void submit()
      }}
      className="flex flex-col gap-4 rounded-md border border-border bg-muted/40 p-3"
    >
      {problem && Object.keys(problem.fields).length === 0 ? (
        <Alert key={problem.attempt} tone="error" focusOnMount>
          {problem.message}
        </Alert>
      ) : null}
      <div className="w-full sm:w-48">
        <Field label="Kind">
          {(control) => (
            <Select
              {...control}
              name="kind"
              value={kind}
              onChange={(event) => {
                setKind(event.target.value as InteractionKind)
              }}
            >
              {KINDS.map((k) => (
                <option key={k} value={k}>
                  {interactionKindLabel(k)}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </div>
      <Field label="Details" error={problem?.fields.body?.join(' ')}>
        {(control) => (
          <Textarea
            {...control}
            name="body"
            value={body}
            required
            onChange={(event) => {
              setBody(event.target.value)
            }}
          />
        )}
      </Field>
      <div className="w-full sm:w-64">
        <Field
          label="When it happened"
          hint={recording ? 'Optional. Leave blank for now.' : undefined}
          error={problem?.fields.occurred_at?.join(' ')}
        >
          {(control) => (
            <Input
              {...control}
              name="occurred_at"
              type="datetime-local"
              value={when}
              onChange={(event) => {
                setWhen(event.target.value)
              }}
            />
          )}
        </Field>
      </div>
      <div className="flex flex-wrap gap-3">
        <SubmitButton pending={pending} pendingLabel="Saving…">
          {recording ? 'Record' : 'Save'}
        </SubmitButton>
        <Button disabled={pending} onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  )
}
