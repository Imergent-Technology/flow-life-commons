import { useState, type SyntheticEvent } from 'react'

import {
  describeResourcesFailure,
  isStaleRevision,
  type ResourceProblem,
} from '../../admin/resourcesWording.ts'
import {
  getPack,
  updatePack,
  type ManagedPack,
  type PackChanges,
  type ResourceCategory,
} from '../../api/resources.ts'
import { shown } from '../../admin/time.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { Property, PropertyList } from '../../ui/PropertyList.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import type { Load } from '../../ui/useLoad.ts'
import { FeedbackAlert } from './FeedbackAlert.tsx'
import { PackFields, type PackFieldValues } from './PackFields.tsx'
import { useFeedback } from './useFeedback.ts'
import { useFocusFirstInvalid } from './useFocusFirstInvalid.ts'

const valuesOf = (pack: ManagedPack): PackFieldValues => ({
  title: pack.title,
  summary: pack.summary ?? '',
  isSeries: pack.isSeries,
  categoryId: pack.category?.id ?? '',
})

/** What differs between the form and a Pack: only that is sent. */
function changesFrom(values: PackFieldValues, pack: ManagedPack): PackChanges {
  const changes: PackChanges = {}
  if (values.title !== pack.title) changes.title = values.title
  if (values.summary.trim() !== (pack.summary ?? '')) {
    changes.summary = values.summary.trim() === '' ? null : values.summary
  }
  if (values.isSeries !== pack.isSeries) changes.isSeries = values.isSeries
  if (values.categoryId !== (pack.category?.id ?? '')) {
    changes.categoryId = values.categoryId === '' ? null : values.categoryId
  }
  return changes
}

/**
 * A Pack's authored fields: title, summary, Category and Series (ADR 0037, decisions 11 and 56). They are guarded by the Pack's
 * `revision`: an edit says which revision it was based on, and if someone saved first the server refuses it (`stale_revision`)
 * rather than let one editor overwrite the other. When that happens nothing is saved and nothing is merged: the Pack is read
 * again, the person's edits stay in the form, and they are shown what was saved meanwhile. Saving again then replaces those
 * fields with theirs, on purpose, or they can take the saved version instead.
 */
export function PackDetailsSection({
  pack,
  categories,
  onSaved,
}: {
  pack: ManagedPack
  categories: Load<ResourceCategory[]>
  onSaved: (next: ManagedPack) => void
}) {
  const [values, setValues] = useState<PackFieldValues>(() => valuesOf(pack))
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(ResourceProblem & { attempt: number }) | null>(null)
  const [conflict, setConflict] = useState<{ saved: ManagedPack; attempt: number } | null>(null)
  const { feedback, say, clear } = useFeedback()
  const form = useFocusFirstInvalid(problem?.attempt)

  async function submit() {
    const changes = changesFrom(values, pack)
    if (Object.keys(changes).length === 0) {
      say('info', 'There is nothing to save: no field has changed.')
      return
    }
    setPending(true)
    clear()
    setProblem(null)
    const result = await updatePack(pack.id, pack.revision, changes)
    if (result.ok) {
      setPending(false)
      setConflict(null)
      onSaved(result.value)
      setValues(valuesOf(result.value))
      say('success', 'The Pack’s details were saved.')
      return
    }
    if (result.failure.kind === 'unauthenticated') {
      setPending(false)
      return
    }
    if (isStaleRevision(result.failure)) {
      // Read it again, so the revision is the current one and the person can see what was saved meanwhile.
      const fresh = await getPack(pack.id)
      setPending(false)
      if (fresh.ok) {
        onSaved(fresh.value)
        setConflict((previous) => ({ saved: fresh.value, attempt: (previous?.attempt ?? 0) + 1 }))
        return
      }
      setProblem((previous) => ({
        ...describeResourcesFailure(fresh.failure),
        attempt: (previous?.attempt ?? 0) + 1,
      }))
      return
    }
    setPending(false)
    const next = describeResourcesFailure(result.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <Panel
      title="Details"
      description="The Pack’s title, summary, Category and whether it is a Series."
    >
      <form
        ref={form}
        aria-label="Pack details"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          void submit()
        }}
        className="flex flex-col gap-4"
      >
        <FeedbackAlert feedback={feedback} />
        {conflict !== null ? (
          <Alert key={conflict.attempt} tone="warning" focusOnMount>
            <p className="font-medium">Someone else saved changes to this Pack first.</p>
            <p>
              Your edits are still in the form and have not been saved. The saved version (revision{' '}
              {conflict.saved.revision}, last edited by{' '}
              {conflict.saved.updatedBy.displayName ?? 'an unknown person'} on{' '}
              <time dateTime={conflict.saved.updatedAt}>{shown(conflict.saved.updatedAt)}</time>)
              is:
            </p>
            <PropertyList className="my-2">
              <Property term="Title">{conflict.saved.title}</Property>
              <Property term="Summary">{conflict.saved.summary ?? 'No summary'}</Property>
              <Property term="Category">{conflict.saved.category?.name ?? 'No Category'}</Property>
              <Property term="Series">{conflict.saved.isSeries ? 'Yes' : 'No'}</Property>
            </PropertyList>
            <p>
              Save again to replace those fields with yours, or take the saved version and discard
              your edits.
            </p>
            <div className="mt-2">
              <Button
                size="sm"
                onClick={() => {
                  setValues(valuesOf(conflict.saved))
                  setConflict(null)
                  say('info', 'The form now shows the saved version.')
                }}
              >
                Use the saved version
              </Button>
            </div>
          </Alert>
        ) : null}
        {problem && Object.keys(problem.fields).length === 0 ? (
          <Alert key={problem.attempt} tone="error" focusOnMount>
            <p>{problem.message}</p>
            {problem.items.length > 0 ? (
              <ul className="mt-1 list-disc pl-5">
                {problem.items.map((item) => (
                  <li key={item}>{item}</li>
                ))}
              </ul>
            ) : null}
          </Alert>
        ) : null}
        <PackFields
          values={values}
          onChange={setValues}
          categories={categories}
          problem={problem}
        />
        <SubmitButton pending={pending} pendingLabel="Saving…">
          Save details
        </SubmitButton>
      </form>
    </Panel>
  )
}
