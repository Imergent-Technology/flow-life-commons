import type { FieldValue, RelationshipField } from '../api/relationships.ts'
import { Checkbox } from '../ui/Checkbox.tsx'
import { Field } from '../ui/Field.tsx'
import { Input } from '../ui/Input.tsx'
import { Select } from '../ui/Select.tsx'
import { Textarea } from '../ui/Textarea.tsx'
import { isEditableField, latestAllowedDate } from './fieldEditing.ts'
import { FieldValueView } from './FieldValueView.tsx'

/**
 * The control for one field, chosen by its DEFINITION's value type (`text`, `long_text`, `date`, `boolean`, `choice`). The
 * length, required marking and help come from the definition too. A field whose type this Console has no control for is
 * shown as read-only text and says so: it never falls back to a free-form box, which would let arbitrary data into a
 * field whose rules the Console cannot state. The server validates every value; `errors` is its word for this field.
 */
export function FieldInput({
  field,
  value,
  onChange,
  errors,
  disabled = false,
}: {
  field: RelationshipField
  value: FieldValue | undefined
  onChange: (value: FieldValue) => void
  errors?: readonly string[] | undefined
  disabled?: boolean
}) {
  const error = errors !== undefined && errors.length > 0 ? errors.join(' ') : undefined
  const hint = field.help ?? undefined
  const required = field.required ? ' (required)' : ''

  if (!isEditableField(field)) {
    return (
      <div className="flex flex-col gap-1">
        <p className="text-label font-medium text-foreground">{field.label}</p>
        <p className="text-body text-foreground">
          <FieldValueView field={field} value={value} />
        </p>
        <p className="text-meta text-muted-foreground">
          This kind of field cannot be edited in this version of the Console.
        </p>
      </div>
    )
  }

  if (field.valueType === 'boolean') {
    return (
      <div className="flex flex-col gap-1">
        <Checkbox
          label={field.label}
          hint={hint}
          checked={value === true}
          disabled={disabled}
          onChange={(event) => {
            onChange(event.target.checked)
          }}
        />
        {error === undefined ? null : <p className="text-label text-danger">{error}</p>}
      </div>
    )
  }

  const text = typeof value === 'string' ? value : ''
  return (
    <Field label={`${field.label}${required}`} hint={hint} error={error}>
      {(control) => {
        switch (field.valueType) {
          case 'long_text':
            return (
              <Textarea
                {...control}
                name={field.key}
                value={text}
                disabled={disabled}
                maxLength={field.maxLength ?? undefined}
                onChange={(event) => {
                  onChange(event.target.value)
                }}
              />
            )
          case 'date':
            return (
              <Input
                {...control}
                name={field.key}
                type="date"
                value={text}
                disabled={disabled}
                max={latestAllowedDate(field)}
                onChange={(event) => {
                  onChange(event.target.value)
                }}
              />
            )
          case 'choice':
            return (
              <Select
                {...control}
                name={field.key}
                value={text}
                disabled={disabled}
                onChange={(event) => {
                  onChange(event.target.value)
                }}
              >
                <option value="">{field.required ? 'Choose…' : 'Not set'}</option>
                {field.options.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </Select>
            )
          default:
            return (
              <Input
                {...control}
                name={field.key}
                type="text"
                value={text}
                disabled={disabled}
                maxLength={field.maxLength ?? undefined}
                autoComplete="off"
                onChange={(event) => {
                  onChange(event.target.value)
                }}
              />
            )
        }
      }}
    </Field>
  )
}
