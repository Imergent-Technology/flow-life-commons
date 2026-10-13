import type {
  PersonRef,
  RelationshipField,
  RelationshipState,
  RelationshipType,
} from '../api/relationships.ts'

// Small readers over a served definition. They look things UP; they decide nothing. A state, a field or a label the
// definition does not hold is shown by its key rather than guessed at.

export const UNNAMED_PERSON = 'Unnamed person'

export const personName = (person: PersonRef): string => person.displayName ?? UNNAMED_PERSON

export function stateOf(type: RelationshipType, key: string): RelationshipState | undefined {
  return type.states.find((state) => state.key === key)
}

/** The state's own label, or its key when the definition does not know the state (a stale page, a future state). */
export const stateLabel = (type: RelationshipType, key: string): string =>
  stateOf(type, key)?.label ?? key

/** Whether the state counts, as the definition says. An unknown state counts for nothing. */
export const stateQualifies = (type: RelationshipType, key: string): boolean =>
  stateOf(type, key)?.qualifies === true

export const hasFeature = (type: RelationshipType, feature: string): boolean =>
  type.features.includes(feature)

/** The fields in the definition's own order (then by key), grouped in the order their groups first appear. */
export function orderedFields(fields: readonly RelationshipField[]): RelationshipField[] {
  return [...fields].sort((a, b) => a.order - b.order || a.key.localeCompare(b.key))
}

export interface FieldGroup {
  group: string | null
  fields: RelationshipField[]
}

export function groupedFields(fields: readonly RelationshipField[]): FieldGroup[] {
  const groups: FieldGroup[] = []
  for (const field of orderedFields(fields)) {
    const existing = groups.find((g) => g.group === field.group)
    if (existing === undefined) groups.push({ group: field.group, fields: [field] })
    else existing.fields.push(field)
  }
  return groups
}

const article = (word: string) => (/^[aeiou]/i.test(word) ? 'an' : 'a')

/** "a Volunteer" / "an Apprentice": a label from the definition, with the article it needs. */
export const withArticle = (word: string): string => `${article(word)} ${word}`
