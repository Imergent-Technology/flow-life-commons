import { accountFor, json, type FakeApi } from './fakeApi.ts'
import { serveOperator } from './admin.ts'
import type { CurrentAccount } from '../api/auth.ts'

// The Relationships API as the server sends it (snake_case, openapi/openapi.yaml). Test fixtures may name the two production
// types; the Console's own source may not (relationships/boundaries.test.ts). A third, invented type with different fields and
// capabilities keeps the generic components honest: nothing a test proves may rest on a type's name.

export const REL_ID = '01J000000000000000RELATION1'
export const REL_ID_2 = '01J000000000000000RELATION2'
export const PERSON = '01J00000000000000000PERSON1'
export const PERSON_2 = '01J00000000000000000PERSON2'
export const OPERATOR_PERSON = '01J000000000000000OPERATOR1'

export type Wire = Record<string, unknown>

const actions = (overrides: Partial<Record<string, boolean>> = {}) => ({
  view: true,
  manage: true,
  delete: true,
  provision_default_role: false,
  ...overrides,
})

/** A type with a default role, two states, a verified lifecycle and no basic-detail editing (the Guardian-like shape). */
export function wireGuardianType(overrides: Wire = {}): Wire {
  return {
    type: 'guardian',
    slug: 'guardians',
    version: 1,
    labels: {
      singular: 'Guardian',
      plural: 'Guardians',
      description: 'People recognized as Guardians.',
      help: null,
    },
    states: [
      {
        key: 'active',
        label: 'Active',
        description: 'Currently serving.',
        tone: 'positive',
        qualifies: true,
      },
      {
        key: 'inactive',
        label: 'Inactive',
        description: 'Stepped down.',
        tone: 'neutral',
        qualifies: false,
      },
    ],
    initial_states: ['active', 'inactive'],
    transitions: { active: ['inactive'], inactive: ['active'] },
    fields: [
      {
        key: 'recognized_on',
        value_type: 'date',
        max_length: null,
        not_after: 'today',
        required: false,
        visibility: 'view',
        label: 'Guardian since',
        help: 'When this person became a Guardian, if known.',
        order: 1,
        group: null,
        options: [],
      },
      {
        key: 'stewardship',
        value_type: 'text',
        max_length: 200,
        not_after: null,
        required: false,
        visibility: 'view',
        label: 'Area of stewardship',
        help: null,
        order: 2,
        group: null,
        options: [],
      },
    ],
    features: ['directory', 'people.lookup', 'people.create_person', 'people.read_basics'],
    presentation: { position: 10 },
    default_role: { display_name: 'Guardian Initiate' },
    actions: actions({ provision_default_role: true }),
    ...overrides,
  }
}

/** Three states changed directly, no default role, basic-detail editing (the Volunteer-like shape). */
export function wireVolunteerType(overrides: Wire = {}): Wire {
  return {
    type: 'volunteer',
    slug: 'volunteers',
    version: 1,
    labels: {
      singular: 'Volunteer',
      plural: 'Volunteers',
      description: 'People who volunteer.',
      help: null,
    },
    states: [
      {
        key: 'pending',
        label: 'Pending',
        description: 'Not yet started.',
        tone: 'caution',
        qualifies: false,
      },
      {
        key: 'active',
        label: 'Active',
        description: 'Volunteering.',
        tone: 'positive',
        qualifies: true,
      },
      {
        key: 'inactive',
        label: 'Inactive',
        description: 'Paused.',
        tone: 'neutral',
        qualifies: false,
      },
    ],
    initial_states: ['pending', 'active'],
    transitions: {
      pending: ['active', 'inactive'],
      active: ['inactive', 'pending'],
      inactive: ['active', 'pending'],
    },
    fields: [
      {
        key: 'interests',
        value_type: 'long_text',
        max_length: 500,
        not_after: null,
        required: false,
        visibility: 'view',
        label: 'Interests and skills',
        help: null,
        order: 1,
        group: null,
        options: [],
      },
      {
        key: 'availability',
        value_type: 'long_text',
        max_length: 500,
        not_after: null,
        required: false,
        visibility: 'view',
        label: 'Availability',
        help: null,
        order: 2,
        group: null,
        options: [],
      },
    ],
    features: [
      'directory',
      'people.lookup',
      'people.create_person',
      'people.read_basics',
      'people.edit_basics',
    ],
    presentation: { position: 20 },
    default_role: null,
    actions: actions(),
    ...overrides,
  }
}

/**
 * A type nothing in the Console has ever seen: its own words, a tone and a field type from a later vocabulary, a required
 * choice, a boolean and a date, no default role, and only the lookup feature (it cannot add a new person or read basics).
 */
export function wireApprenticeType(overrides: Wire = {}): Wire {
  return {
    type: 'apprentice',
    slug: 'apprentices',
    version: 3,
    labels: {
      singular: 'Apprentice',
      plural: 'Apprentices',
      description: 'People learning a craft here.',
      help: 'Apprentices work with a mentor.',
    },
    states: [
      {
        key: 'applied',
        label: 'Applied',
        description: 'Waiting.',
        tone: 'radiant',
        qualifies: false,
      },
      {
        key: 'training',
        label: 'In training',
        description: 'Learning.',
        tone: 'positive',
        qualifies: true,
      },
      {
        key: 'graduated',
        label: 'Graduated',
        description: 'Finished.',
        tone: 'neutral',
        qualifies: false,
      },
    ],
    initial_states: ['applied'],
    transitions: { applied: ['training'], training: ['graduated', 'applied'], graduated: [] },
    fields: [
      {
        key: 'track',
        value_type: 'choice',
        max_length: null,
        not_after: null,
        required: true,
        visibility: 'manage',
        label: 'Track',
        help: null,
        order: 1,
        group: 'Programme',
        options: ['wood', 'metal', 'clay'],
      },
      {
        key: 'mentor',
        value_type: 'text',
        max_length: 40,
        not_after: null,
        required: false,
        visibility: 'view',
        label: 'Mentor',
        help: 'Who guides them.',
        order: 2,
        group: 'Programme',
        options: [],
      },
      {
        key: 'started_on',
        value_type: 'date',
        max_length: null,
        not_after: null,
        required: false,
        visibility: 'view',
        label: 'Started on',
        help: null,
        order: 3,
        group: null,
        options: [],
      },
      {
        key: 'own_tools',
        value_type: 'boolean',
        max_length: null,
        not_after: null,
        required: false,
        visibility: 'view',
        label: 'Brings their own tools',
        help: null,
        order: 4,
        group: null,
        options: [],
      },
      {
        key: 'signature',
        value_type: 'handwriting',
        max_length: null,
        not_after: null,
        required: false,
        visibility: 'view',
        label: 'Signature',
        help: null,
        order: 5,
        group: null,
        options: [],
      },
    ],
    features: ['people.lookup'],
    presentation: { position: 30 },
    default_role: null,
    actions: actions(),
    ...overrides,
  }
}

export const typesOf = (...types: Wire[]) => ({ data: types })

const person = (id: string, name: string | null) => ({ id, display_name: name })

export const GWEN = person(OPERATOR_PERSON, 'Gwen Operator')
export const ADA = person(PERSON, 'Ada Lovelace')

export function wireDefaultRole(overrides: Wire = {}): Wire {
  return {
    display_name: 'Guardian Initiate',
    decision: null,
    decided_by: null,
    decided_at: null,
    effective: false,
    ...overrides,
  }
}

/** The management read (manage capability): revision and transitions, no history, no provenance. */
export function wireManagement(overrides: Wire = {}): Wire {
  return {
    relationship_id: REL_ID,
    type: 'guardian',
    person: ADA,
    status: 'active',
    revision: 3,
    transitions: ['inactive'],
    fields: { stewardship: 'The garden' },
    default_role: wireDefaultRole(),
    basics: null,
    ...overrides,
  }
}

/** The view read (view capability): history and provenance, no revision-bearing transitions. */
export function wireRecord(overrides: Wire = {}): Wire {
  return {
    relationship_id: REL_ID,
    type: 'guardian',
    definition_version: 1,
    person: ADA,
    status: { key: 'active', since: '2026-09-01T10:00:00Z', by: GWEN },
    revision: 3,
    created_at: '2026-08-01T09:00:00Z',
    created_by: GWEN,
    fields: { stewardship: 'The garden' },
    default_role: wireDefaultRole(),
    basics: { primary_email: 'ada@example.org', primary_phone: '+1 555 010 0100' },
    history: [
      {
        from_status: null,
        to_status: 'active',
        changed_at: '2026-08-01T09:00:00Z',
        changed_by: GWEN,
      },
      {
        from_status: 'active',
        to_status: 'inactive',
        changed_at: '2026-08-15T09:00:00Z',
        changed_by: GWEN,
      },
      {
        from_status: 'inactive',
        to_status: 'active',
        changed_at: '2026-09-01T10:00:00Z',
        changed_by: GWEN,
      },
    ],
    ...overrides,
  }
}

export function wireEntry(overrides: Wire = {}): Wire {
  return {
    relationship_id: REL_ID,
    person: ADA,
    status: 'active',
    status_changed_at: '2026-09-01T10:00:00Z',
    basics: { primary_email: 'ada@example.org', primary_phone: '+1 555 010 0100' },
    ...overrides,
  }
}

export function directoryPage(entries: Wire[], meta: Record<string, number> = {}) {
  return { data: entries, meta: { page: 1, per_page: 25, total: entries.length, ...meta } }
}

export function candidate(
  id: string,
  name: string,
  matchedOn: 'display_name' | 'email' | 'phone' = 'display_name',
  status: string | null = null,
): Wire {
  return { person: person(id, name), matched_on: matchedOn, status }
}

export const candidateList = (candidates: Wire[], truncated = false) => ({
  data: candidates,
  truncated,
})

export const notFound = () =>
  json({ message: 'There is no such relationship.', code: 'relationship_not_found' }, 404)

export const stale = (current: Wire) =>
  json({ message: 'It changed.', code: 'stale_revision', current }, 409)

export const refusal = (code: string, status: number, extra: Wire = {}) =>
  json({ message: 'Refused.', code, ...extra }, status)

export const codedInvalid = (code: string) => json({ message: 'Refused.', code }, 422)

/** Capabilities of an operator who reaches the Console, with whatever else a test grants. */
export const operatorWith = (...capabilities: string[]): CurrentAccount =>
  accountFor({ capabilities: ['console.access', ...capabilities] })

/** The shell, with these relationship types served. */
export function serveRelationships(
  types: Wire[],
  account: CurrentAccount = operatorWith(),
): FakeApi {
  const api = serveOperator(account)
  api.on('GET /api/v1/admin/relationship-types', json(typesOf(...types)))
  return api
}

export const GUARDIAN_PATH = `/api/v1/admin/relationships/guardians/${PERSON}`
export const VOLUNTEER_PATH = `/api/v1/admin/relationships/volunteers/${PERSON}`
