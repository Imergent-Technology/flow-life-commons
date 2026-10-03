import { useState, type SyntheticEvent } from 'react'
import { Link, useNavigate } from 'react-router'

import { describeDiscussionsFailure } from '../../admin/discussionsWording.ts'
import { startDiscussion } from '../../api/discussions.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Field } from '../../ui/Field.tsx'
import { Input } from '../../ui/Input.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { type Problem } from '../../ui/problem.ts'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { Textarea } from '../../ui/Textarea.tsx'

/**
 * Starts a discussion: a title and the opening message, and nothing else (ADR 0035). There is no author to choose (it is
 * whoever is signed in), and no category, priority, audience or assignee, because discussions have none. The server judges
 * the text; on success the new discussion opens.
 */
export function StartDiscussionPage() {
  const navigate = useNavigate()
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)

  async function submit() {
    setPending(true)
    const result = await startDiscussion({ title, body })
    setPending(false)
    if (result.ok) {
      void navigate(`/discussions/${result.value.id}`)
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const next = describeDiscussionsFailure(result.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <Page width="form">
      <PageHeader
        title="Start a discussion"
        description="Everyone who can view discussions will be able to read it."
      />
      <form
        aria-label="Start a discussion"
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
        <Field label="Opening message" error={problem?.fields.body?.join(' ')}>
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
          <SubmitButton pending={pending} pendingLabel="Starting…">
            Start discussion
          </SubmitButton>
          <Link to="/discussions" className={buttonVariants({})}>
            Cancel
          </Link>
        </div>
      </form>
    </Page>
  )
}
