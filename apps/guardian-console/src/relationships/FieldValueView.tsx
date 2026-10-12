import type { FieldValue, RelationshipField } from '../api/relationships.ts'

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'long', timeZone: 'UTC' })

/** A calendar date has no time zone: read it as UTC and show it in UTC, so it is the same day everywhere. */
function formatDate(value: string): string {
  const parsed = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (parsed === null) return value
  const when = new Date(Date.UTC(Number(parsed[1]), Number(parsed[2]) - 1, Number(parsed[3])))
  return Number.isNaN(when.getTime()) ? value : dateFormat.format(when)
}

/**
 * One field's value, shown according to its definition's value type. A value type this Console has no wording for is
 * shown as the plain text it is, never hidden and never made editable. No value is "Not set".
 */
export function FieldValueView({
  field,
  value,
}: {
  field: RelationshipField
  value: FieldValue | undefined
}) {
  if (value === undefined || value === '') {
    return <span className="text-muted-foreground">Not set</span>
  }
  switch (field.valueType) {
    case 'boolean':
      return <>{value === true ? 'Yes' : 'No'}</>
    case 'date':
      return <>{typeof value === 'string' ? formatDate(value) : String(value)}</>
    case 'long_text':
      return <span className="whitespace-pre-line">{String(value)}</span>
    default:
      return <>{String(value)}</>
  }
}
