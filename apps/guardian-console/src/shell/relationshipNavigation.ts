import type { RelationshipType } from '../api/relationships.ts'
import { withArticle } from '../relationships/definitions.ts'
import type { NavGroup, NavItem, NavSection } from './navigation.ts'

/** The section the Relationships group joins. It is a place in the Console, not a relationship type. */
const HOME_SECTION = 'people'

/** "Record a Volunteer": the intake entry's words come from the definition's singular label, never from this file. */
export function intakeLabel(type: RelationshipType): string {
  return `Record ${withArticle(type.labels.singular)}`
}

/**
 * The Relationships group, built only from what the server served. A directory for every type the operator may VIEW, and
 * a way to record one for every type they may MANAGE. The two are independent: managing is not viewing, so a manager who
 * cannot view gets the intake entry and no directory. Nothing here names a type, a slug or a role; the signed-in Person's
 * own relationships play no part. The server decides every request these entries lead to.
 */
export function relationshipGroup(types: readonly RelationshipType[]): NavGroup | null {
  const ordered = [...types].sort(
    (a, b) => a.position - b.position || a.labels.plural.localeCompare(b.labels.plural),
  )
  const directories: NavItem[] = ordered
    .filter((type) => type.actions.view)
    .map((type) => ({
      label: type.labels.plural,
      to: `/relationships/${type.slug}`,
      detail: { pattern: `/relationships/${type.slug}/:personId`, label: type.labels.singular },
    }))
  const intakes: NavItem[] = ordered
    .filter((type) => type.actions.manage)
    .map((type) => ({ label: intakeLabel(type), to: `/relationships/${type.slug}/new` }))

  const items = [...directories, ...intakes]
  return items.length === 0 ? null : { label: 'Relationships', items }
}

/** The static navigation with the Relationships group added to the People section. */
export function withRelationshipNavigation(
  sections: readonly NavSection[],
  types: readonly RelationshipType[],
): NavSection[] {
  const group = relationshipGroup(types)
  if (group === null) return [...sections]
  return sections.map((section) =>
    section.id === HOME_SECTION && section.groups !== undefined
      ? { ...section, groups: [...section.groups, group] }
      : section,
  )
}
