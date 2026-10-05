import type { ResourceProblem } from '../../admin/resourcesWording.ts'
import type { ResourceCategory } from '../../api/resources.ts'
import { Checkbox } from '../../ui/Checkbox.tsx'
import { Field } from '../../ui/Field.tsx'
import { Input } from '../../ui/Input.tsx'
import { Select } from '../../ui/Select.tsx'
import { Textarea } from '../../ui/Textarea.tsx'
import type { Load } from '../../ui/useLoad.ts'
import { TextLink } from '../../ui/TextLink.tsx'

export const TITLE_MAX = 200
export const SUMMARY_MAX = 300

export interface PackFieldValues {
  title: string
  summary: string
  isSeries: boolean
  categoryId: string
}

/**
 * The authored fields of a Resource Pack, shared by creating one and editing one (ADR 0037, decision 11): a title, an optional
 * summary of its own (a Pack's summary is never derived), a Category (optional while it is a Draft; a Published Pack must keep
 * one, and the server says so), and whether it is a Series. A Series only changes how the Cards are presented (Previous and Next,
 * and "n of m"): it implies no progress tracking and locks nothing. Audiences are not here: they have their own section.
 */
export function PackFields({
  values,
  onChange,
  categories,
  problem,
}: {
  values: PackFieldValues
  onChange: (next: PackFieldValues) => void
  categories: Load<ResourceCategory[]>
  problem: ResourceProblem | null
}) {
  const fields = problem?.fields ?? {}
  return (
    <>
      <Field label="Title" error={fields.title?.join(' ')}>
        {(control) => (
          <Input
            {...control}
            name="title"
            value={values.title}
            required
            maxLength={TITLE_MAX}
            autoComplete="off"
            onChange={(event) => {
              onChange({ ...values, title: event.target.value })
            }}
          />
        )}
      </Field>
      <Field
        label="Summary"
        hint={`Optional. A line that says what the Pack is for, up to ${String(SUMMARY_MAX)} characters.`}
        error={fields.summary?.join(' ')}
      >
        {(control) => (
          <Textarea
            {...control}
            name="summary"
            value={values.summary}
            maxLength={SUMMARY_MAX}
            rows={3}
            onChange={(event) => {
              onChange({ ...values, summary: event.target.value })
            }}
          />
        )}
      </Field>
      <Field
        label="Category"
        hint="A Pack is filed under one Category, and needs one before it can be published."
        error={
          fields.category_id?.join(' ') ??
          (categories.status === 'failed' ? 'The Categories could not be loaded.' : undefined)
        }
      >
        {(control) => (
          <Select
            {...control}
            name="category_id"
            value={values.categoryId}
            disabled={categories.status !== 'loaded'}
            onChange={(event) => {
              onChange({ ...values, categoryId: event.target.value })
            }}
          >
            <option value="">No Category</option>
            {categories.status === 'loaded'
              ? categories.value.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.name}
                  </option>
                ))
              : null}
          </Select>
        )}
      </Field>
      {categories.status === 'loaded' && categories.value.length === 0 ? (
        <p className="text-meta text-muted-foreground">
          There are no Categories yet. <TextLink to="/resources/categories">Create one</TextLink> to
          file this Pack under it.
        </p>
      ) : null}
      <Checkbox
        label="This Pack is a Series"
        hint="A Series emphasises Previous and Next, and each Card’s place (for example 2 of 5). It tracks no progress and locks nothing: anyone can open any Card they can see."
        checked={values.isSeries}
        onChange={(event) => {
          onChange({ ...values, isSeries: event.target.checked })
        }}
      />
    </>
  )
}
