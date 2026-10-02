import { useCallback, useState, type SyntheticEvent } from 'react'

import { describePeopleFailure } from '../../admin/peopleWording.ts'
import { createTag, deleteTag, listTags, renameTag, type Tag } from '../../api/tags.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, PEOPLE_MANAGE } from '../../auth/capabilities.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { ConfirmDialog, type ConfirmResult } from '../../ui/ConfirmDialog.tsx'
import { EmptyState } from '../../ui/EmptyState.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { type Problem } from '../../ui/problem.ts'
import { SkeletonRegion, SkeletonRows } from '../../ui/Skeleton.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { TextField } from '../../ui/TextField.tsx'
import { useLoad } from '../../ui/useLoad.ts'

/**
 * The list of tags Guardians may put on people (ADR 0034). Labels only: a tag grants no access and says nothing about
 * Membership or volunteering, so there is no meaning, colour or grouping here, and no tag is built in. Seeing the list needs
 * `crm.people.view`; creating, renaming and deleting need `crm.people.manage`. A tag that anyone still holds cannot be
 * deleted, and the server says so: nothing is ever removed from a person to make a deletion succeed.
 */
export function TagsPage() {
  const current = useCurrentAccount()
  const mayManage = hasCapability(current, PEOPLE_MANAGE)
  const [reloads, setReloads] = useState(0)
  const [renaming, setRenaming] = useState<Tag | null>(null)
  const [deleting, setDeleting] = useState<Tag | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(
    (signal: AbortSignal) => listTags(signal),
    // `reloads` is not read: a new load function is how a change or "Try again" asks again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [reloads],
  )
  const [tags] = useLoad(load)
  const reload = () => {
    setReloads((n) => n + 1)
  }

  async function confirmDelete(): Promise<ConfirmResult> {
    if (deleting === null) return { kind: 'stay', tone: 'error', message: 'Nothing to delete.' }
    const result = await deleteTag(deleting.id)
    if (result.ok) {
      setNotice(`The tag “${deleting.name}” was deleted.`)
      setDeleting(null)
      reload()
      return { kind: 'done' }
    }
    // Whatever it was, the list may have moved on (someone else tagged or removed it): show it as it is now.
    reload()
    return {
      kind: 'stay',
      tone: 'error',
      message: describePeopleFailure(result.failure, 'tag').message,
    }
  }

  return (
    <Page width="detail">
      <PageHeader
        title="Tags"
        description="Labels for finding and grouping people. A tag grants no access and says nothing about membership or volunteering."
      />

      {notice !== null ? <Alert tone="success">{notice}</Alert> : null}

      {mayManage ? (
        <NewTagForm
          onDone={(name) => {
            setNotice(`The tag “${name}” was created.`)
            reload()
          }}
        />
      ) : null}

      {tags.status === 'loading' ? (
        <SkeletonRegion label="Loading tags…" visibleLabel>
          <SkeletonRows />
        </SkeletonRegion>
      ) : null}
      {tags.status === 'failed' ? (
        <div className="flex flex-col items-start gap-3">
          <Alert tone="error">{describePeopleFailure(tags.failure, 'tag').message}</Alert>
          <Button onClick={reload}>Try again</Button>
        </div>
      ) : null}
      {tags.status === 'loaded' ? (
        tags.value.length === 0 ? (
          <EmptyState title="There are no tags yet.">
            {mayManage
              ? 'Create the first one above.'
              : 'A Guardian who manages people can create them.'}
          </EmptyState>
        ) : (
          <Panel>
            <ul aria-label="Tags" className="flex flex-col divide-y divide-border">
              {tags.value.map((tag) => (
                <li key={tag.id} className="py-3 first:pt-0 last:pb-0">
                  {renaming?.id === tag.id ? (
                    <RenameTagForm
                      tag={tag}
                      onDone={() => {
                        setRenaming(null)
                        setNotice('The tag was renamed.')
                        reload()
                      }}
                      onCancel={() => {
                        setRenaming(null)
                      }}
                    />
                  ) : (
                    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                      <p className="flex flex-wrap items-baseline gap-x-3 text-body text-foreground">
                        <span className="font-medium wrap-anywhere">{tag.name}</span>
                        <span className="text-meta text-muted-foreground">
                          {tag.personCount === 1 ? '1 person' : `${String(tag.personCount)} people`}
                        </span>
                      </p>
                      {mayManage ? (
                        <div className="flex flex-wrap items-center gap-2">
                          <Button
                            size="sm"
                            aria-label={`Rename ${tag.name}`}
                            onClick={() => {
                              setNotice(null)
                              setRenaming(tag)
                            }}
                          >
                            Rename
                          </Button>
                          <Button
                            size="sm"
                            variant="danger"
                            aria-label={`Delete ${tag.name}`}
                            onClick={() => {
                              setNotice(null)
                              setDeleting(tag)
                            }}
                          >
                            Delete
                          </Button>
                        </div>
                      ) : null}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </Panel>
        )
      ) : null}

      {deleting !== null ? (
        <ConfirmDialog
          title="Delete this tag?"
          confirmLabel="Delete"
          destructive
          onConfirm={confirmDelete}
          onCancel={() => {
            setDeleting(null)
          }}
        >
          <p>
            Delete the tag <strong className="wrap-anywhere">{deleting.name}</strong>? It can only
            be deleted while no one holds it; nothing is removed from anyone to make that so.
          </p>
          {deleting.personCount > 0 ? (
            <p>
              It is currently on{' '}
              {deleting.personCount === 1 ? '1 person' : `${String(deleting.personCount)} people`}.
            </p>
          ) : null}
        </ConfirmDialog>
      ) : null}
    </Page>
  )
}

function NewTagForm({ onDone }: { onDone: (name: string) => void }) {
  const [name, setName] = useState('')
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)

  async function submit() {
    setPending(true)
    const result = await createTag(name)
    setPending(false)
    if (result.ok) {
      setProblem(null)
      setName('')
      onDone(result.value.name)
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const next = describePeopleFailure(result.failure, 'tag')
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <form
      aria-label="Create a tag"
      onSubmit={(event: SyntheticEvent) => {
        event.preventDefault()
        void submit()
      }}
      className="flex flex-col gap-3"
    >
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-full sm:w-72">
          <TextField
            label="New tag"
            name="name"
            autoComplete="off"
            value={name}
            onChange={setName}
            errors={problem?.fields.name}
            maxLength={64}
          />
        </div>
        <SubmitButton pending={pending} pendingLabel="Adding…">
          Add tag
        </SubmitButton>
      </div>
      {problem && Object.keys(problem.fields).length === 0 ? (
        <Alert key={problem.attempt} tone="error" focusOnMount>
          {problem.message}
        </Alert>
      ) : null}
    </form>
  )
}

function RenameTagForm({
  tag,
  onDone,
  onCancel,
}: {
  tag: Tag
  onDone: () => void
  onCancel: () => void
}) {
  const [name, setName] = useState(tag.name)
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)

  async function submit() {
    if (name.trim() === tag.name) {
      onCancel() // nothing changed: nothing to send
      return
    }
    setPending(true)
    const result = await renameTag(tag.id, name)
    setPending(false)
    if (result.ok) {
      onDone()
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const next = describePeopleFailure(result.failure, 'tag')
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <form
      aria-label={`Rename ${tag.name}`}
      onSubmit={(event: SyntheticEvent) => {
        event.preventDefault()
        void submit()
      }}
      className="flex flex-col gap-3"
    >
      {problem && Object.keys(problem.fields).length === 0 ? (
        <Alert key={problem.attempt} tone="error" focusOnMount>
          {problem.message}
        </Alert>
      ) : null}
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-full sm:w-72">
          <TextField
            label="Tag name"
            name="name"
            autoComplete="off"
            value={name}
            onChange={setName}
            errors={problem?.fields.name}
            maxLength={64}
          />
        </div>
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
