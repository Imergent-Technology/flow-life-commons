import { useCallback, useState, type SyntheticEvent } from 'react'
import { Link, useNavigate } from 'react-router'

import { describeResourcesFailure, type ResourceProblem } from '../../admin/resourcesWording.ts'
import { createPack, listCategories } from '../../api/resources.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { PackFields, type PackFieldValues } from './PackFields.tsx'
import { useFocusFirstInvalid } from './useFocusFirstInvalid.ts'

/**
 * Starts a Resource Pack (ADR 0037): always a Draft, with no audience and no Card yet. Those come next, on the Pack's own page,
 * where the server's rules for publishing it are shown beside the things they ask for. Nothing is published or shared by creating one.
 */
export function NewPackPage() {
  const navigate = useNavigate()
  const loadCategories = useCallback((signal: AbortSignal) => listCategories(signal), [])
  const [categories] = useLoad(loadCategories)
  const [values, setValues] = useState<PackFieldValues>({
    title: '',
    summary: '',
    isSeries: false,
    categoryId: '',
  })
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(ResourceProblem & { attempt: number }) | null>(null)
  const form = useFocusFirstInvalid(problem?.attempt)

  async function submit() {
    setPending(true)
    const result = await createPack({
      title: values.title,
      summary: values.summary.trim() === '' ? null : values.summary,
      isSeries: values.isSeries,
      categoryId: values.categoryId === '' ? null : values.categoryId,
    })
    setPending(false)
    if (result.ok) {
      void navigate(`/resources/packs/${result.value.id}`, {
        state: {
          notice:
            'The Resource Pack was created as a Draft. Next, choose its audiences and add its first Card.',
        },
      })
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const next = describeResourcesFailure(result.failure)
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  return (
    <Page width="form">
      <PageHeader
        title="Add a Resource Pack"
        description="It starts as a Draft that only editors can see."
      />
      <form
        ref={form}
        aria-label="Add a Resource Pack"
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
        <PackFields
          values={values}
          onChange={setValues}
          categories={categories}
          problem={problem}
        />
        <div className="flex flex-wrap gap-3">
          <SubmitButton pending={pending} pendingLabel="Creating…">
            Create Resource Pack
          </SubmitButton>
          <Link to="/resources" className={buttonVariants({})}>
            Cancel
          </Link>
        </div>
      </form>
    </Page>
  )
}
