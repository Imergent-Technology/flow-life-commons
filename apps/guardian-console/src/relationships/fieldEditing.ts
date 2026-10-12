import type {
  FieldChanges,
  FieldValue,
  FieldValues,
  RelationshipField,
} from '../api/relationships.ts'

// How a field form turns what the operator typed into the partial update the API accepts (ADR 0038, H): a field that was not
// touched is left OUT, a changed value is sent as it is, and a value emptied on purpose is sent as `null`. Validation here is
// advisory (the server's `422` is shown on the field and is the only verdict); it only avoids obvious round trips.

/** The value types this Console has a control for. A field of any other type is shown and never edited. */
const EDITABLE_TYPES: readonly string[] = ['text', 'long_text', 'date', 'boolean', 'choice']

export const isEditableField = (field: RelationshipField): boolean =>
  EDITABLE_TYPES.includes(field.valueType)

/** What the controls hold. Booleans are checkboxes, everything else is text ('' is "not set"). */
export type Draft = Record<string, FieldValue>

export function draftFrom(fields: readonly RelationshipField[], values: FieldValues): Draft {
  const draft: Draft = {}
  for (const field of fields) {
    if (!isEditableField(field)) continue
    const value = values[field.key]
    draft[field.key] =
      field.valueType === 'boolean' ? value === true : typeof value === 'string' ? value : ''
  }
  return draft
}

export interface PreparedChanges {
  changes: FieldChanges
  /** Advisory problems, by field key. A form with any is not sent. */
  problems: Record<string, string>
}

export function prepareChanges(
  fields: readonly RelationshipField[],
  original: FieldValues,
  draft: Draft,
): PreparedChanges {
  const changes: FieldChanges = {}
  const problems: Record<string, string> = {}

  for (const field of fields) {
    if (!isEditableField(field)) continue
    const typed = draft[field.key]

    if (field.valueType === 'boolean') {
      const before = original[field.key] === true
      const now = typed === true
      if (now !== before) changes[field.key] = now
      continue
    }

    const before = typeof original[field.key] === 'string' ? (original[field.key] as string) : ''
    const now = typeof typed === 'string' ? typed.trim() : ''
    if (now === '') {
      if (field.required) problems[field.key] = 'This field is required.'
      else if (before !== '') changes[field.key] = null // cleared on purpose
      continue
    }
    if (field.maxLength !== null && now.length > field.maxLength) {
      problems[field.key] = `Use at most ${String(field.maxLength)} characters.`
      continue
    }
    if (field.valueType === 'choice' && !field.options.includes(now)) {
      problems[field.key] = 'Choose one of the options.'
      continue
    }
    if (now !== before) changes[field.key] = now
  }
  return { changes, problems }
}

/** The latest date a "not after today" field accepts: today at UTC+14, the zone furthest ahead (ADR 0038, F7). Advisory. */
export function latestAllowedDate(
  field: RelationshipField,
  now: Date = new Date(),
): string | undefined {
  if (field.valueType !== 'date' || field.notAfter !== 'today') return undefined
  return new Date(now.getTime() + 14 * 60 * 60 * 1000).toISOString().slice(0, 10)
}
