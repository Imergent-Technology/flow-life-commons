import { json } from './fakeApi.ts'

export const PERSON_ID = '01J0000000000000000MEMBER'
export const GRANT_ID = '01J0000000000000000000GRT1'

/** A grant history entry as it appears NESTED inside a Member's `grants` (no `person_id`: the record already names one). */
export function wireGrantEntry(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: GRANT_ID,
    starts_at: '2026-01-01T00:00:00Z',
    ends_at: null,
    source: 'operator',
    source_reference: null,
    revoked_at: null,
    ...overrides,
  }
}

/** The STANDALONE shape `POST .../grants` returns: a grant on its own, so it names its own `person_id`. */
export function wireGrant(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return { ...wireGrantEntry(), person_id: PERSON_ID, ...overrides }
}

/** A wire membership record (snake_case), with sensible defaults: one active, open-ended grant. */
export function wireMember(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    person: { id: PERSON_ID, display_name: 'Mia Member' },
    active: true,
    current_access_ends_at: null,
    open_ended: true,
    grants: [wireGrantEntry()],
    ...overrides,
  }
}

export function membersPage(members: unknown[], meta: Record<string, number> = {}) {
  return {
    data: members,
    meta: { page: 1, per_page: 25, total: members.length, last_page: 1, ...meta },
  }
}

export const membershipRecordNotFound = () =>
  json(
    { message: 'That Person has no membership record.', code: 'membership_record_not_found' },
    404,
  )

export const grantAlreadyRevoked = () =>
  json(
    { message: 'This membership grant has already been revoked.', code: 'grant_already_revoked' },
    409,
  )
