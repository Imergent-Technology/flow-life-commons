import { useState, type SyntheticEvent } from 'react'

import { describeDiscussionsFailure, isStale, personName } from '../../admin/discussionsWording.ts'
import { shown } from '../../admin/time.ts'
import { editMessage, type LiveMessage, type RemovedMessage } from '../../api/discussions.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { Field } from '../../ui/Field.tsx'
import { type Problem } from '../../ui/problem.ts'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { Textarea } from '../../ui/Textarea.tsx'

/**
 * A message that is still there: who wrote it, where it sits in the thread, when, and whether it has been edited. The thread
 * is FLAT and is read in the server's order (sequence), so nothing here suggests a reply to another message. Edit and Remove
 * are offered only to the message's own author, who is told apart by Person id and never by name, and only to someone who may
 * take part. An edit leaves no history behind it, and the wording never suggests otherwise.
 */
export function LiveMessageItem({
  discussionId,
  message,
  mine,
  mayParticipate,
  editing,
  onEdit,
  onRemove,
  onEdited,
  onCancelEdit,
  onStale,
}: {
  discussionId: string
  message: LiveMessage
  /** Written by the signed-in Person. */
  mine: boolean
  mayParticipate: boolean
  editing: boolean
  onEdit: () => void
  onRemove: () => void
  onEdited: () => void
  onCancelEdit: () => void
  /** The message changed under the writer (removed, or gone): the thread should be re-read and say so. */
  onStale: (message: string) => void
}) {
  const name = personName(message.author)
  const own = mine && mayParticipate
  return (
    <article
      aria-label={`Message ${String(message.sequence)} by ${name}`}
      className="flex flex-col gap-2"
    >
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p className="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1">
          <span className="text-body font-medium wrap-anywhere text-foreground">{name}</span>
          <span className="text-meta text-muted-foreground">
            {message.sequence === 1 ? 'Opening message' : `Message ${String(message.sequence)}`}
          </span>
          <time dateTime={message.createdAt} className="text-meta text-muted-foreground">
            {shown(message.createdAt)}
          </time>
          {message.editedAt !== null ? (
            <span className="text-meta text-muted-foreground">
              Edited <time dateTime={message.editedAt}>{shown(message.editedAt)}</time>
              {message.editedBy !== null && message.editedBy.id !== message.author.id
                ? ` by ${personName(message.editedBy)}`
                : ''}
            </span>
          ) : null}
        </p>
        {own && !editing ? (
          <div className="flex flex-wrap items-center gap-2">
            <Button
              size="sm"
              onClick={onEdit}
              aria-label={`Edit your message ${String(message.sequence)}`}
            >
              Edit
            </Button>
            <Button
              size="sm"
              variant="danger"
              onClick={onRemove}
              aria-label={`Remove your message ${String(message.sequence)}`}
            >
              Remove
            </Button>
          </div>
        ) : null}
      </div>
      {editing ? (
        <EditMessageForm
          discussionId={discussionId}
          message={message}
          onDone={onEdited}
          onCancel={onCancelEdit}
          onStale={onStale}
        />
      ) : (
        <p className="text-body wrap-anywhere whitespace-pre-wrap text-foreground">
          {message.body}
        </p>
      )}
    </article>
  )
}

/**
 * A message that was removed, kept in its place so the thread still reads in order. It says so in words, says who wrote the
 * place and when it went, and shows nothing of what it said: there is nothing to show, and there is no restore.
 */
export function RemovedMessageItem({ message }: { message: RemovedMessage }) {
  return (
    <article
      aria-label={`Message ${String(message.sequence)}, removed`}
      className="flex flex-col gap-1 rounded-md border border-dashed border-border-strong px-3 py-2"
    >
      <p className="text-body text-muted-foreground italic">This message was removed.</p>
      <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-meta text-muted-foreground">
        <span>
          {message.sequence === 1 ? 'Opening message' : `Message ${String(message.sequence)}`} by{' '}
          {personName(message.author)}
        </span>
        <span>
          Written <time dateTime={message.createdAt}>{shown(message.createdAt)}</time>
        </span>
        <span>
          Removed <time dateTime={message.removedAt}>{shown(message.removedAt)}</time>
        </span>
      </p>
    </article>
  )
}

/**
 * Edits the text of the signed-in Person's own message, and only the text: the author, the place in the thread and the time
 * are not on the form and can never be sent. Nothing is sent if the text is as it was.
 */
function EditMessageForm({
  discussionId,
  message,
  onDone,
  onCancel,
  onStale,
}: {
  discussionId: string
  message: LiveMessage
  onDone: () => void
  onCancel: () => void
  onStale: (message: string) => void
}) {
  const [body, setBody] = useState(message.body)
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)

  async function submit() {
    if (body.trim() === message.body) {
      onCancel() // nothing changed: nothing to send
      return
    }
    setPending(true)
    const result = await editMessage(discussionId, message.id, body)
    setPending(false)
    if (result.ok) {
      onDone()
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const next = describeDiscussionsFailure(result.failure, 'message')
    if (isStale(result.failure)) {
      onStale(next.message)
      return
    }
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <form
      aria-label={`Edit your message ${String(message.sequence)}`}
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
      <Field label="Your message" error={problem?.fields.body?.join(' ')}>
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
      <div className="flex flex-wrap gap-3">
        <SubmitButton pending={pending} pendingLabel="Saving…">
          Save
        </SubmitButton>
        <Button disabled={pending} onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  )
}
