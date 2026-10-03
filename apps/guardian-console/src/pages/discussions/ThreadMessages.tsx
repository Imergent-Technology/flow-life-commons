import { useCallback, useState } from 'react'

import { describeDiscussionsFailure, isStale } from '../../admin/discussionsWording.ts'
import {
  listMessages,
  removeMessage,
  type Discussion,
  type LiveMessage,
} from '../../api/discussions.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { ConfirmDialog, type ConfirmResult } from '../../ui/ConfirmDialog.tsx'
import { EmptyState } from '../../ui/EmptyState.tsx'
import { Pagination } from '../../ui/Pagination.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { LiveMessageItem, RemovedMessageItem } from './MessageItem.tsx'
import { ReplyComposer } from './ReplyComposer.tsx'

const PER_PAGE = 25

interface Feedback {
  tone: 'success' | 'error'
  text: string
  /** Whether the outcome takes keyboard focus (an error always does). */
  focus: boolean
  attempt: number
}

/**
 * A discussion's messages, in the server's order (sequence, oldest first: never re-sorted by time) and a bounded page at a
 * time, with a reply form after them for someone who may take part. A removed message stays as a placeholder in its place. After
 * a change the thread is re-read rather than patched, so what is on screen is what the server holds; after a conflict the
 * discussion's header is re-read too, so a stale "Open" cannot linger.
 */
export function ThreadMessages({
  discussion,
  mayParticipate,
  me,
  refresh,
  draft,
  onDraft,
}: {
  discussion: Discussion
  mayParticipate: boolean
  /** The signed-in Person's id: what "mine" means. Never a name. */
  me: string
  refresh: () => Promise<void>
  draft: string
  onDraft: (text: string) => void
}) {
  const [page, setPage] = useState(1)
  // Bumped by a change or "Try again": a new load function is a new request for the current page.
  const [reloads, setReloads] = useState(0)
  const [editingId, setEditingId] = useState<string | null>(null)
  const [removing, setRemoving] = useState<LiveMessage | null>(null)
  const [feedback, setFeedback] = useState<Feedback | null>(null)

  const load = useCallback(
    (signal: AbortSignal) =>
      listMessages({ discussionId: discussion.id, page, perPage: PER_PAGE, signal }),
    // `reloads` is not read: a new load function is how a change or "Try again" asks for the page again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [discussion.id, page, reloads],
  )
  const [loaded] = useLoad(load)

  const reload = () => {
    setReloads((n) => n + 1)
  }
  // `focus`: an outcome that follows a control which then goes (the edit form closes, a removed message loses its buttons) takes
  // focus, so a keyboard user is not dropped at the top of the page. "Reply posted." leaves it where it is: the box is still there.
  const say = (tone: Feedback['tone'], text: string, focus = tone === 'error') => {
    setFeedback((previous) => ({ tone, text, focus, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  /** A message changed under the writer: say so, close what was open on it and re-read both the thread and the header. */
  function stale(text: string) {
    setEditingId(null)
    say('error', text)
    reload()
    void refresh()
  }

  async function confirmRemove(): Promise<ConfirmResult> {
    if (removing === null) return { kind: 'stay', tone: 'error', message: 'Nothing to remove.' }
    const result = await removeMessage(discussion.id, removing.id)
    if (result.ok) {
      setRemoving(null)
      say('success', 'Your message was removed.', true)
      reload()
      return { kind: 'done' }
    }
    const problem = describeDiscussionsFailure(result.failure, 'message')
    if (isStale(result.failure)) {
      setRemoving(null)
      stale(problem.message)
      return { kind: 'done' }
    }
    return { kind: 'stay', tone: 'error', message: problem.message }
  }

  return (
    <>
      <Panel title="Messages">
        <div className="flex flex-col gap-4">
          {feedback !== null ? (
            <Alert key={feedback.attempt} tone={feedback.tone} focusOnMount={feedback.focus}>
              {feedback.text}
            </Alert>
          ) : null}
          {loaded.status === 'loading' ? (
            <SkeletonRegion label="Loading messages…" visibleLabel>
              <SkeletonText lines={4} />
            </SkeletonRegion>
          ) : null}
          {loaded.status === 'failed' ? (
            <div className="flex flex-col items-start gap-3">
              <Alert tone="error">{describeDiscussionsFailure(loaded.failure).message}</Alert>
              <Button onClick={reload}>Try again</Button>
            </div>
          ) : null}
          {loaded.status === 'loaded' ? (
            <>
              {loaded.value.messages.length === 0 ? (
                <EmptyState title="There are no messages on this page." />
              ) : (
                <ol aria-label="Messages" className="flex flex-col divide-y divide-border">
                  {loaded.value.messages.map((message) => (
                    <li key={message.id} className="py-4 first:pt-0 last:pb-0">
                      {message.removed ? (
                        <RemovedMessageItem message={message} />
                      ) : (
                        <LiveMessageItem
                          discussionId={discussion.id}
                          message={message}
                          mine={message.author.id === me}
                          mayParticipate={mayParticipate}
                          editing={editingId === message.id}
                          onEdit={() => {
                            setFeedback(null)
                            setEditingId(message.id)
                          }}
                          onRemove={() => {
                            setFeedback(null)
                            setRemoving(message)
                          }}
                          onEdited={() => {
                            setEditingId(null)
                            say('success', 'Saved.', true)
                            reload()
                          }}
                          onCancelEdit={() => {
                            setEditingId(null)
                          }}
                          onStale={stale}
                        />
                      )}
                    </li>
                  ))}
                </ol>
              )}
              {loaded.value.lastPage > 1 ? (
                <Pagination
                  page={loaded.value.page}
                  lastPage={loaded.value.lastPage}
                  total={loaded.value.total}
                  noun={{ one: 'message', other: 'messages' }}
                  onPageChange={(next) => {
                    setEditingId(null)
                    setPage(next)
                  }}
                />
              ) : null}
            </>
          ) : null}
        </div>
      </Panel>

      {mayParticipate ? (
        <ReplyComposer
          discussionId={discussion.id}
          open={discussion.state === 'open'}
          draft={draft}
          onDraft={onDraft}
          onPosted={(message) => {
            onDraft('')
            say('success', 'Reply posted.')
            // The reply is the newest message: show the page it landed on.
            setPage(Math.max(1, Math.ceil(message.sequence / PER_PAGE)))
            reload()
          }}
          onResolvedUnderfoot={(failure) => {
            say('error', describeDiscussionsFailure(failure).message)
            reload()
            void refresh()
          }}
        />
      ) : null}

      {removing !== null ? (
        <ConfirmDialog
          title="Remove your message?"
          confirmLabel="Remove message"
          destructive
          onConfirm={confirmRemove}
          onCancel={() => {
            setRemoving(null)
          }}
        >
          <p>
            The text will be removed for everyone. A placeholder, “This message was removed.”, stays
            in its place so the discussion still reads in order. This cannot be undone.
          </p>
          <blockquote className="line-clamp-4 border-l-2 border-border-strong pl-3 wrap-anywhere whitespace-pre-wrap text-muted-foreground">
            {removing.body}
          </blockquote>
        </ConfirmDialog>
      ) : null}
    </>
  )
}
