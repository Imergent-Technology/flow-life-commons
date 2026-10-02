import { json } from './fakeApi.ts'

export const PERSON_ID = '01J00000000000000000PERSN1'
export const METHOD_EMAIL_ID = '01J0000000000000000EMAIL01'
export const METHOD_EMAIL2_ID = '01J0000000000000000EMAIL02'
export const METHOD_PHONE_ID = '01J0000000000000000PHONE01'

/** A contact method as the server sends it. */
export function wireMethod(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: METHOD_EMAIL_ID,
    kind: 'email',
    value: 'ada@example.org',
    label: null,
    is_primary: true,
    created_at: '2026-10-01T12:00:00Z',
    updated_at: '2026-10-01T12:00:00Z',
    ...overrides,
  }
}

/** A Person's CRM record as the server sends it: tags are present on the wire and deliberately never shown (WP5). */
export function wirePerson(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    person: { id: PERSON_ID, display_name: 'Ada Lovelace' },
    profile: {
      how_we_know: 'Met at the spring workshop',
      affiliation: 'Analytical Guild',
      updated_at: null,
    },
    contact_methods: [wireMethod()],
    tags: [{ id: '01J0000000000000000000TAG1', name: 'Partner' }],
    ...overrides,
  }
}

export function wireListing(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: PERSON_ID,
    display_name: 'Ada Lovelace',
    primary_email: 'ada@example.org',
    primary_phone: null,
    tags: [{ id: '01J0000000000000000000TAG1', name: 'Partner' }],
    ...overrides,
  }
}

export function peoplePage(rows: unknown[], meta: Record<string, number> = {}) {
  return {
    data: rows,
    meta: { page: 1, per_page: 25, total: rows.length, last_page: 1, ...meta },
  }
}

export const personNotFound = () =>
  json({ message: 'There is no such Person.', code: 'person_not_found' }, 404)

export const possibleDuplicate = (candidates: unknown[]) =>
  json(
    { message: 'That may be someone already here.', code: 'possible_duplicate', candidates },
    409,
  )

export const invalidContactInput = (field: string, message: string) =>
  json({ message, code: 'invalid_contact_input', errors: { [field]: [message] } }, 422)

export const INTERACTION_ID = '01J00000000000000INTRCT01'
export const INTERACTION2_ID = '01J00000000000000INTRCT02'
export const TAG_ID = '01J0000000000000000000TAG1'
export const TAG2_ID = '01J0000000000000000000TAG2'

/** An interaction as the server sends it: the author is the safe Person projection and nothing else. */
export function wireInteraction(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: INTERACTION_ID,
    kind: 'note',
    body: 'Spoke about the spring workshop.',
    occurred_at: '2026-10-01T15:00:00Z',
    author: { id: '01J000000000000000000AUTH01', display_name: 'Gwen Guardian' },
    updated_by: null,
    created_at: '2026-10-01T15:05:00Z',
    updated_at: '2026-10-01T15:05:00Z',
    ...overrides,
  }
}

export function interactionsPage(rows: unknown[], meta: Record<string, number> = {}) {
  return {
    data: rows,
    meta: { page: 1, per_page: 10, total: rows.length, last_page: 1, ...meta },
  }
}

/** A tag in the vocabulary, with how many People hold it. */
export function wireTag(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return { id: TAG_ID, name: 'Partner', person_count: 0, ...overrides }
}

export const tagNotFound = () =>
  json({ message: 'There is no such tag.', code: 'tag_not_found' }, 404)

export const interactionNotFound = () =>
  json(
    { message: 'There is no such interaction for that Person.', code: 'interaction_not_found' },
    404,
  )
