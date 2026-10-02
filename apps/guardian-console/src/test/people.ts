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
