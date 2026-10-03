import { useState, type SyntheticEvent } from 'react'

import { describeDiscussionsFailure, personName } from '../../admin/discussionsWording.ts'
import { shown } from '../../admin/time.ts'
import {
  reopenDiscussion,
  resolveDiscussion,
  retitleDiscussion,
  type Discussion,
} from '../../api/discussions.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { Field } from '../../ui/Field.tsx'
import { Input } from '../../ui/Input.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { type Problem } from '../../ui/problem.ts'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { StateBadge } from './StateBadge.tsx'

interface Feedback {
  tone: 'success' | 'error'
  text: string
  attempt: number
}

/**
 * The top of a discussion: its title, whether it is open or resolved and who started it, and the three things that change
 * the discussion itself. Resolving and reopening are for anyone who may take part. Retitling is for the person who started it,
 * and is shown to no one else: the capability alone is not enough. Resolved means "no new replies", not "frozen": the title can
 * still be corrected, and nothing here speaks of archiving. Retitling is not activity, so the discussion does not move.
 */
export function DiscussionHeader({
  discussion,
  mayParticipate,
  isCreator,
  onChange,
}: {
  discussion: Discussion
  mayParticipate: boolean
  isCreator: boolean
  onChange: (next: Discussion) => void
}) {
  const [retitling, setRetitling] = useState(false)
  const [pending, setPending] = useState(false)
  const [feedback, setFeedback] = useState<Feedback | null>(null)
  const open = discussion.state === 'open'

  function say(tone: Feedback['tone'], text: string) {
    setFeedback((previous) => ({ tone, text, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  async function change(action: 'resolve' | 'reopen') {
    setPending(true)
    setFeedback(null)
    const result = await (action === 'resolve'
      ? resolveDiscussion(discussion.id)
      : reopenDiscussion(discussion.id))
    setPending(false)
    if (result.ok) {
      onChange(result.value)
      say('success', action === 'resolve' ? 'Marked as resolved.' : 'Reopened.')
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    say('error', describeDiscussionsFailure(result.failure).message)
  }

  return (
    <>
      <PageHeader
        title={discussion.title}
        status={<StateBadge state={discussion.state} />}
        description={
          <>
            Started by {personName(discussion.creator)} on{' '}
            <time dateTime={discussion.createdAt}>{shown(discussion.createdAt)}</time>
          </>
        }
        action={
          mayParticipate ? (
            <div className="flex flex-wrap items-center gap-2">
              {isCreator && !retitling ? (
                <Button
                  onClick={() => {
                    setFeedback(null)
                    setRetitling(true)
                  }}
                >
                  Edit title
                </Button>
              ) : null}
              <Button
                disabled={pending}
                onClick={() => {
                  void change(open ? 'resolve' : 'reopen')
                }}
              >
                {open ? 'Mark as resolved' : 'Reopen discussion'}
              </Button>
            </div>
          ) : undefined
        }
      />
      {!open ? (
        <p className="text-body text-muted-foreground">
          {discussion.resolvedAt !== null ? (
            <>
              Resolved by {personName(discussion.resolvedBy)} on{' '}
              <time dateTime={discussion.resolvedAt}>{shown(discussion.resolvedAt)}</time>.{' '}
            </>
          ) : null}
          It takes no new replies, but it stays here to read.
        </p>
      ) : null}
      {feedback !== null ? (
        <Alert key={feedback.attempt} tone={feedback.tone} focusOnMount={feedback.tone === 'error'}>
          {feedback.text}
        </Alert>
      ) : null}
      {retitling ? (
        <RetitleForm
          discussion={discussion}
          onDone={(next) => {
            setRetitling(false)
            onChange(next)
            say('success', 'Title saved.')
          }}
          onCancel={() => {
            setRetitling(false)
          }}
        />
      ) : null}
    </>
  )
}

/** Corrects the title. Nothing is sent if the title is as it was: there is nothing to say. */
function RetitleForm({
  discussion,
  onDone,
  onCancel,
}: {
  discussion: Discussion
  onDone: (next: Discussion) => void
  onCancel: () => void
}) {
  const [title, setTitle] = useState(discussion.title)
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)

  async function submit() {
    if (title.trim() === discussion.title) {
      onCancel()
      return
    }
    setPending(true)
    const result = await retitleDiscussion(discussion.id, title)
    setPending(false)
    if (result.ok) {
      onDone(result.value)
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const next = describeDiscussionsFailure(result.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <form
      aria-label="Edit the title"
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
      <Field label="Title" error={problem?.fields.title?.join(' ')}>
        {(control) => (
          <Input
            {...control}
            name="title"
            value={title}
            required
            onChange={(event) => {
              setTitle(event.target.value)
            }}
          />
        )}
      </Field>
      <div className="flex flex-wrap gap-3">
        <SubmitButton pending={pending} pendingLabel="Saving…">
          Save title
        </SubmitButton>
        <Button disabled={pending} onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  )
}
