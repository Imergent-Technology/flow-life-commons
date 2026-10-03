import { useState, type SyntheticEvent } from 'react'

import { describeDiscussionsFailure } from '../../admin/discussionsWording.ts'
import { replyToDiscussion, type Message } from '../../api/discussions.ts'
import type { Failure } from '../../api/http.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Field } from '../../ui/Field.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { type Problem } from '../../ui/problem.ts'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { Textarea } from '../../ui/Textarea.tsx'

/**
 * Where a participant replies. While the discussion is open it is one box and one button: there is no author to choose, no one
 * to address, no message to nest under and nothing to attach. Once it is resolved the form gives way to a plain explanation
 * rather than vanishing without one, and an unsent reply is kept. If the discussion is resolved while someone is writing
 * (the server answers `discussion_resolved`), the reply is NOT added, the draft stays, and the caller re-reads the discussion
 * so the page says what is now true.
 */
export function ReplyComposer({
  discussionId,
  open,
  draft,
  onDraft,
  onPosted,
  onResolvedUnderfoot,
}: {
  discussionId: string
  open: boolean
  draft: string
  onDraft: (text: string) => void
  onPosted: (message: Message) => void
  /** The reply was refused because the discussion is no longer open. */
  onResolvedUnderfoot: (failure: Failure) => void
}) {
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)

  if (!open) {
    return (
      <Panel title="Replies are closed">
        <p className="text-body text-muted-foreground">
          This discussion is resolved, so it takes no new replies. Reopen it to carry on the
          conversation.
          {draft.trim() !== '' ? ' The reply you had started is kept.' : ''}
        </p>
      </Panel>
    )
  }

  async function submit() {
    setPending(true)
    const result = await replyToDiscussion(discussionId, draft)
    setPending(false)
    if (result.ok) {
      setProblem(null)
      onPosted(result.value)
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    if (result.failure.kind === 'conflict' && result.failure.code === 'discussion_resolved') {
      onResolvedUnderfoot(result.failure)
      return
    }
    const next = describeDiscussionsFailure(result.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <Panel title="Reply">
      <form
        aria-label="Reply to this discussion"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          void submit()
        }}
        className="flex flex-col gap-4"
      >
        {problem && Object.keys(problem.fields).length === 0 ? (
          <Alert key={problem.attempt} tone="error" focusOnMount>
            {problem.message}
          </Alert>
        ) : null}
        <Field label="Your reply" error={problem?.fields.body?.join(' ')}>
          {(control) => (
            <Textarea
              {...control}
              name="body"
              value={draft}
              required
              onChange={(event) => {
                onDraft(event.target.value)
              }}
            />
          )}
        </Field>
        <div className="flex flex-wrap gap-3">
          <SubmitButton pending={pending} pendingLabel="Posting…">
            Post reply
          </SubmitButton>
        </div>
      </form>
    </Panel>
  )
}
