import { describe, expect, it } from 'vitest'

import { listRelationshipTypes, type RelationshipType } from '../api/relationships.ts'
import { FakeApi, json } from '../test/fakeApi.ts'
import {
  typesOf,
  wireApprenticeType,
  wireGuardianType,
  wireVolunteerType,
  type Wire,
} from '../test/relationships.ts'
import { PEOPLE_VIEW } from '../auth/capabilities.ts'
import {
  breadcrumbs,
  locate,
  navigation,
  visibleNavigation,
  type NavSection,
} from './navigation.ts'
import {
  intakeLabel,
  relationshipGroup,
  withRelationshipNavigation,
} from './relationshipNavigation.ts'

async function served(...wires: Wire[]): Promise<RelationshipType[]> {
  new FakeApi().on('GET /api/v1/admin/relationship-types', json(typesOf(...wires))).install()
  const result = await listRelationshipTypes()
  if (!result.ok) throw new Error('The fixture types did not parse.')
  return result.value
}

const only = (actions: Partial<Record<'view' | 'manage', boolean>>) => ({
  actions: { view: false, manage: false, delete: true, provision_default_role: false, ...actions },
})

const labelsOf = (sections: NavSection[]) =>
  sections.flatMap((s) =>
    (s.groups ?? []).flatMap((g) => g.items.map((i) => `${g.label}: ${i.label}`)),
  )

describe('the Relationships group is built from the served definitions', () => {
  it('has a directory for every type that may be viewed and a way to record every type that may be managed', async () => {
    const group = relationshipGroup(await served(wireGuardianType(), wireVolunteerType()))
    expect(group?.label).toBe('Relationships')
    expect(group?.items.map((item) => [item.label, item.to])).toEqual([
      ['Guardians', '/relationships/guardians'],
      ['Volunteers', '/relationships/volunteers'],
      ['Record a Guardian', '/relationships/guardians/new'],
      ['Record a Volunteer', '/relationships/volunteers/new'],
    ])
  })

  it('takes every word from the definition: an unknown third type needs no change here', async () => {
    const types = await served(wireVolunteerType(), wireApprenticeType(), wireGuardianType())
    const group = relationshipGroup(types)
    // Ordered by the definitions' own presentation position, not by name.
    expect(group?.items.slice(0, 3).map((item) => item.label)).toEqual([
      'Guardians',
      'Volunteers',
      'Apprentices',
    ])
    expect(group?.items.at(-1)).toMatchObject({
      label: 'Record an Apprentice',
      to: '/relationships/apprentices/new',
    })
    // Rename the same type and the entries follow; nothing is remembered about it.
    const renamed = await served(
      wireApprenticeType({
        slug: 'journeys',
        labels: { singular: 'Journeyer', plural: 'Journeyers', description: 'd', help: null },
      }),
    )
    expect(relationshipGroup(renamed)?.items.map((i) => [i.label, i.to])).toEqual([
      ['Journeyers', '/relationships/journeys'],
      ['Record a Journeyer', '/relationships/journeys/new'],
    ])
  })

  it('gives a manager who cannot view the way to record and no directory, and a viewer the directory alone', async () => {
    const group = relationshipGroup(
      await served(
        wireGuardianType(only({ manage: true })),
        wireVolunteerType(only({ view: true })),
      ),
    )
    expect(group?.items.map((item) => item.label)).toEqual(['Volunteers', 'Record a Guardian'])
  })

  it('is absent when the operator may do nothing with any type', async () => {
    expect(relationshipGroup(await served(wireGuardianType(only({}))))).toBeNull()
    expect(relationshipGroup([])).toBeNull()
  })

  it('joins the People section, and leaves the rest of the navigation as it was', async () => {
    const types = await served(wireVolunteerType())
    const sections = withRelationshipNavigation(navigation, types)
    const people = sections.find((s) => s.id === 'people')
    expect(people?.groups?.map((g) => g.label)).toEqual(['People', 'Relationships'])
    expect(sections.filter((s) => s.id !== 'people')).toEqual(
      navigation.filter((s) => s.id !== 'people'),
    )
    expect(withRelationshipNavigation(navigation, [])).toEqual(navigation)
  })

  it('shows a relationship manager without CRM access a People section holding only the Relationships group', async () => {
    const sections = visibleNavigation(
      withRelationshipNavigation(navigation, await served(wireVolunteerType())),
      () => false,
    )
    expect(labelsOf(sections)).toEqual([
      'Relationships: Volunteers',
      'Relationships: Record a Volunteer',
    ])
  })

  it('does not depend on the signed-in person holding any capability but the ones the server describes', async () => {
    // Not even People view: the entries are the definitions' alone, and nothing here reads who the operator is.
    const sections = visibleNavigation(
      withRelationshipNavigation(navigation, await served(wireVolunteerType())),
      (capability) => capability === PEOPLE_VIEW,
    )
    expect(labelsOf(sections)).toContain('People: All people')
    expect(labelsOf(sections)).toContain('Relationships: Volunteers')
  })

  it('locates a person beneath a directory, and the intake page as its own entry', async () => {
    const sections = withRelationshipNavigation(navigation, await served(wireVolunteerType()))
    const record = locate(sections, '/relationships/volunteers/01J00000000000000000PERSON1')
    expect(record?.item?.label).toBe('Volunteers')
    expect(record?.detail).toBe(true)
    // `new` is the intake entry itself, never a person beneath the directory.
    const intake = locate(sections, '/relationships/volunteers/new')
    expect(intake?.item?.label).toBe('Record a Volunteer')
    expect(intake?.detail).toBe(false)
    expect(breadcrumbs(record, 'Ada Lovelace')).toEqual([
      { label: 'Relationships', to: '/relationships/volunteers' },
      { label: 'Ada Lovelace' },
    ])
    expect(breadcrumbs(intake)).toEqual([
      { label: 'Relationships', to: '/relationships/volunteers' },
      { label: 'Record a Volunteer' },
    ])
  })

  it('words the intake entry with the right article for any label', async () => {
    const [type] = await served(wireApprenticeType())
    expect(type && intakeLabel(type)).toBe('Record an Apprentice')
  })
})
