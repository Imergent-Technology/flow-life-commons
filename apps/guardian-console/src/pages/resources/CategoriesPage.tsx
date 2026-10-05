import { useCallback, useState, type SyntheticEvent } from 'react'

import { describeResourcesFailure, isOrderMismatch } from '../../admin/resourcesWording.ts'
import {
  createCategory,
  deleteCategory,
  listCategories,
  listPacks,
  renameCategory,
  reorderCategories,
  reorderPacks,
  type ManagedPack,
  type ResourceCategory,
} from '../../api/resources.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { ConfirmDialog, type ConfirmResult } from '../../ui/ConfirmDialog.tsx'
import { EmptyState } from '../../ui/EmptyState.tsx'
import { Field } from '../../ui/Field.tsx'
import { Input } from '../../ui/Input.tsx'
import { Modal } from '../../ui/Modal.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import type { Problem } from '../../ui/problem.ts'
import { SkeletonRegion, SkeletonRows } from '../../ui/Skeleton.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { StateBadge } from './badges.tsx'
import { FeedbackAlert } from './FeedbackAlert.tsx'
import { useFeedback } from './useFeedback.ts'
import { useFocusFirstInvalid } from './useFocusFirstInvalid.ts'
import { ReorderList, type ReorderOutcome } from './ReorderList.tsx'

const NAME_MAX = 80

/**
 * Resource Categories (ADR 0037, decisions 6-10): the names Resource Packs are filed under. A Category is a name and an order
 * and nothing more: not an audience, and no authorization. Creating, renaming, ordering and deleting an EMPTY one are routine
 * (`resources.manage`, no recent verification). A Category that any Pack still holds, in any state, cannot be deleted: the
 * server refuses, and no Pack is ever moved to make a deletion succeed.
 */
export function CategoriesPage() {
  const [reloads, setReloads] = useState(0)
  const [renaming, setRenaming] = useState<string | null>(null)
  const [deleting, setDeleting] = useState<ResourceCategory | null>(null)
  const [ordering, setOrdering] = useState<ResourceCategory | null>(null)
  const { feedback, say, clear } = useFeedback()

  const load = useCallback(
    (signal: AbortSignal) => listCategories(signal),
    // `reloads` is not read: a new load function is how a change or "Try again" asks again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [reloads],
  )
  const [categories, replace] = useLoad(load)
  const reload = () => {
    setReloads((n) => n + 1)
  }

  async function onReorder(ids: string[]): Promise<ReorderOutcome> {
    const result = await reorderCategories(ids)
    if (result.ok) {
      replace(result.value)
      return { ok: true }
    }
    if (isOrderMismatch(result.failure)) {
      // Read it again IN PLACE: swapping the load would blank the page, and the message below with it.
      const fresh = await listCategories()
      if (fresh.ok) replace(fresh.value)
    }
    return { ok: false, message: describeResourcesFailure(result.failure, 'category').message }
  }

  async function confirmDelete(): Promise<ConfirmResult> {
    if (deleting === null) return { kind: 'stay', tone: 'error', message: 'Nothing to delete.' }
    const result = await deleteCategory(deleting.id)
    if (result.ok) {
      say('success', `The Category “${deleting.name}” was deleted.`)
      setDeleting(null)
      reload()
      return { kind: 'done' }
    }
    // Whatever it was, the list may have moved on (a Pack was filed under it, or it was already deleted).
    reload()
    return {
      kind: 'stay',
      tone: 'error',
      message: describeResourcesFailure(result.failure, 'category').message,
    }
  }

  return (
    <Page width="detail">
      <PageHeader
        title="Categories"
        description="The names Resource Packs are filed under, in the order they are listed. A Category grants no access: who sees a Pack is decided by the Pack’s audiences."
      />

      <FeedbackAlert feedback={feedback} />

      <NewCategoryForm
        onDone={(name) => {
          say('success', `The Category “${name}” was created.`)
          reload()
        }}
      />

      {categories.status === 'loading' ? (
        <SkeletonRegion label="Loading Categories…" visibleLabel>
          <SkeletonRows />
        </SkeletonRegion>
      ) : null}
      {categories.status === 'failed' ? (
        <div className="flex flex-col items-start gap-3">
          <Alert tone="error">
            {describeResourcesFailure(categories.failure, 'category').message}
          </Alert>
          <Button onClick={reload}>Try again</Button>
        </div>
      ) : null}
      {categories.status === 'loaded' ? (
        categories.value.length === 0 ? (
          <EmptyState title="There are no Categories yet.">
            Create the first one above. A Resource Pack needs a Category before it can be published.
          </EmptyState>
        ) : (
          <ReorderList
            label="Categories"
            onReorder={onReorder}
            items={categories.value.map((category) => ({
              id: category.id,
              name: category.name,
              children:
                renaming === category.id ? (
                  <RenameCategoryForm
                    category={category}
                    onDone={(name) => {
                      setRenaming(null)
                      say('success', `The Category was renamed to “${name}”.`)
                      reload()
                    }}
                    onCancel={() => {
                      setRenaming(null)
                    }}
                  />
                ) : (
                  <>
                    <p className="flex flex-wrap items-baseline gap-x-3 text-body text-foreground">
                      <span className="font-medium wrap-anywhere">{category.name}</span>
                      <span className="text-meta text-muted-foreground">
                        {category.packCount === 1
                          ? '1 Pack'
                          : `${String(category.packCount)} Packs`}
                      </span>
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                      <Button
                        size="sm"
                        aria-label={`Rename ${category.name}`}
                        onClick={() => {
                          clear()
                          setRenaming(category.id)
                        }}
                      >
                        Rename
                      </Button>
                      <Button
                        size="sm"
                        aria-label={`Order the Packs in ${category.name}`}
                        disabled={category.packCount < 2}
                        onClick={() => {
                          clear()
                          setOrdering(category)
                        }}
                      >
                        Order Packs
                      </Button>
                      <Button
                        size="sm"
                        variant="danger"
                        aria-label={`Delete ${category.name}`}
                        onClick={() => {
                          clear()
                          setDeleting(category)
                        }}
                      >
                        Delete
                      </Button>
                    </div>
                  </>
                ),
            }))}
          />
        )
      ) : null}

      {deleting !== null ? (
        <ConfirmDialog
          title="Delete this Category?"
          confirmLabel="Delete"
          destructive
          onConfirm={confirmDelete}
          onCancel={() => {
            setDeleting(null)
          }}
        >
          <p>
            Delete the Category <strong className="wrap-anywhere">{deleting.name}</strong>? It can
            only be deleted while no Resource Pack is filed under it; nothing is moved or removed to
            make that so.
          </p>
          {deleting.packCount > 0 ? (
            <p>
              It currently holds{' '}
              {deleting.packCount === 1 ? '1 Pack' : `${String(deleting.packCount)} Packs`}, so the
              server will refuse.
            </p>
          ) : null}
        </ConfirmDialog>
      ) : null}

      {ordering !== null ? (
        <OrderPacksDialog
          category={ordering}
          onClose={() => {
            setOrdering(null)
          }}
        />
      ) : null}
    </Page>
  )
}

function NewCategoryForm({ onDone }: { onDone: (name: string) => void }) {
  const [name, setName] = useState('')
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const form = useFocusFirstInvalid(problem?.attempt)

  async function submit() {
    setPending(true)
    const result = await createCategory(name)
    setPending(false)
    if (result.ok) {
      setProblem(null)
      setName('')
      onDone(result.value.name)
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const next = describeResourcesFailure(result.failure, 'category')
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <form
      ref={form}
      aria-label="Create a Category"
      onSubmit={(event: SyntheticEvent) => {
        event.preventDefault()
        void submit()
      }}
      className="flex flex-col gap-3"
    >
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-full sm:w-80">
          <Field label="New Category" error={problem?.fields.name?.join(' ')}>
            {(control) => (
              <Input
                {...control}
                name="name"
                value={name}
                maxLength={NAME_MAX}
                autoComplete="off"
                required
                onChange={(event) => {
                  setName(event.target.value)
                }}
              />
            )}
          </Field>
        </div>
        <SubmitButton pending={pending} pendingLabel="Adding…">
          Add Category
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

function RenameCategoryForm({
  category,
  onDone,
  onCancel,
}: {
  category: ResourceCategory
  onDone: (name: string) => void
  onCancel: () => void
}) {
  const [name, setName] = useState(category.name)
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(Problem & { attempt: number }) | null>(null)
  const form = useFocusFirstInvalid(problem?.attempt)

  async function submit() {
    if (name.trim() === category.name) {
      onCancel() // nothing changed: nothing to send
      return
    }
    setPending(true)
    const result = await renameCategory(category.id, name)
    setPending(false)
    if (result.ok) {
      onDone(result.value.name)
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const next = describeResourcesFailure(result.failure, 'category')
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <form
      ref={form}
      aria-label={`Rename ${category.name}`}
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
          <Field label="Category name" error={problem?.fields.name?.join(' ')}>
            {(control) => (
              <Input
                {...control}
                name="name"
                value={name}
                maxLength={NAME_MAX}
                autoComplete="off"
                required
                autoFocus
                onChange={(event) => {
                  setName(event.target.value)
                }}
              />
            )}
          </Field>
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

/**
 * The Packs of one Category, in the order they are listed, reorderable. The server needs the COMPLETE set of the Category's
 * Packs (all states) to reorder it, so only a Category whose Packs fit one page of 100 is reorderable here.
 */
function OrderPacksDialog({
  category,
  onClose,
}: {
  category: ResourceCategory
  onClose: () => void
}) {
  const load = useCallback(
    (signal: AbortSignal) => listPacks({ page: 1, perPage: 100, category: category.id, signal }),
    [category.id],
  )
  const [packs, replace] = useLoad(load)

  async function onReorder(ids: string[]): Promise<ReorderOutcome> {
    const result = await reorderPacks(category.id, ids)
    if (result.ok) {
      replace({
        packs: result.value,
        page: 1,
        lastPage: 1,
        total: result.value.length,
      })
      return { ok: true }
    }
    if (isOrderMismatch(result.failure)) {
      const fresh = await listPacks({ page: 1, perPage: 100, category: category.id })
      if (fresh.ok) replace(fresh.value)
    }
    return { ok: false, message: describeResourcesFailure(result.failure, 'category').message }
  }

  return (
    <Modal title={`Order the Packs in ${category.name}`} onClose={onClose}>
      {packs.status === 'loading' ? (
        <SkeletonRegion label="Loading Packs…" visibleLabel>
          <SkeletonRows rows={3} />
        </SkeletonRegion>
      ) : null}
      {packs.status === 'failed' ? (
        <Alert tone="error">{describeResourcesFailure(packs.failure, 'category').message}</Alert>
      ) : null}
      {packs.status === 'loaded' ? (
        packs.value.lastPage > 1 ? (
          <Alert tone="warning">
            This Category holds more than 100 Packs, which is more than can be ordered here.
          </Alert>
        ) : (
          <ReorderList
            label={`Packs in ${category.name}`}
            onReorder={onReorder}
            items={packs.value.packs.map((pack: ManagedPack) => ({
              id: pack.id,
              name: pack.title,
              children: (
                <>
                  <span className="font-medium wrap-anywhere text-foreground">{pack.title}</span>
                  <span>
                    <StateBadge state={pack.state} />
                  </span>
                </>
              ),
            }))}
          />
        )
      ) : null}
      <div className="flex justify-end">
        <Button variant="secondary" onClick={onClose}>
          Done
        </Button>
      </div>
    </Modal>
  )
}
