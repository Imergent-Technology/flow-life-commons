import { afterEach, describe, expect, it, vi } from 'vitest'

import { FakeApi, json, empty } from '../test/fakeApi.ts'
import {
  candidate,
  candidateList,
  codedInvalid,
  directoryPage,
  PERSON,
  REL_ID,
  stale,
  typesOf,
  wireApprenticeType,
  wireEntry,
  wireGuardianType,
  wireManagement,
  wireRecord,
  wireVolunteerType,
} from '../test/relationships.ts'
import {
  changeRelationshipStatus,
  decideDefaultRole,
  deleteRelationship,
  duplicateAdvice,
  establishRelationship,
  findCandidates,
  getRelationship,
  getRelationshipManagement,
  listDirectory,
  listRelationshipTypes,
  staleBasicsCurrent,
  staleRevisionCurrent,
  updateRelationshipBasics,
  updateRelationshipFields,
} from './relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

const BASE = '/api/v1/admin/relationships'

describe('the definitions', () => {
  it('are adapted to camelCase, keeping the wire semantics', async () => {
    new FakeApi()
      .on('GET /api/v1/admin/relationship-types', json(typesOf(wireGuardianType())))
      .install()
    const result = await listRelationshipTypes()
    expect(result.ok).toBe(true)
    if (!result.ok) return
    const [type] = result.value
    expect(type).toMatchObject({
      type: 'guardian',
      slug: 'guardians',
      definitionVersion: 1,
      initialStates: ['active', 'inactive'],
      position: 10,
      defaultRole: { displayName: 'Guardian Initiate' },
      actions: { view: true, manage: true, delete: true, provisionDefaultRole: true },
    })
    expect(type?.fields[0]).toMatchObject({
      key: 'recognized_on',
      valueType: 'date',
      notAfter: 'today',
    })
  })

  it('read a tone and a value type from a later vocabulary without failing', async () => {
    new FakeApi()
      .on('GET /api/v1/admin/relationship-types', json(typesOf(wireApprenticeType())))
      .install()
    const result = await listRelationshipTypes()
    expect(result.ok && result.value[0]?.states[0]?.tone).toBe('neutral')
    expect(result.ok && result.value[0]?.fields.find((f) => f.key === 'signature')?.valueType).toBe(
      'handwriting',
    )
  })

  it.each([
    ['a missing action', { actions: { view: true, manage: true, delete: true } }],
    [
      'a state without a label',
      { states: [{ key: 'a', description: 'd', tone: 'neutral', qualifies: true }] },
    ],
    [
      'a field with a text max length',
      { fields: [{ ...(wireVolunteerType().fields as object[])[0], max_length: '500' }] },
    ],
    ['a transition that is not a list', { transitions: { a: 'b' } }],
  ])('are rejected, as unexpected, with %s', async (_name, broken) => {
    new FakeApi()
      .on('GET /api/v1/admin/relationship-types', json(typesOf(wireVolunteerType(broken))))
      .install()
    expect(await listRelationshipTypes()).toEqual({
      ok: false,
      failure: { kind: 'unexpected', status: 200 },
    })
  })
})

describe('reading one relationship', () => {
  it('keeps the view read and the management read apart', async () => {
    new FakeApi()
      .on(`GET ${BASE}/guardians/${PERSON}`, json(wireRecord()))
      .on(`GET ${BASE}/guardians/${PERSON}/management`, json(wireManagement()))
      .install()
    const view = await getRelationship('guardians', PERSON)
    const management = await getRelationshipManagement('guardians', PERSON)
    expect(view.ok && view.value).toMatchObject({
      relationshipId: REL_ID,
      definitionVersion: 1,
      revision: 3,
      status: { key: 'active' },
    })
    expect(view.ok && view.value.history).toHaveLength(3)
    expect(management.ok && management.value).toMatchObject({
      relationshipId: REL_ID,
      revision: 3,
      transitions: ['inactive'],
    })
    // The management read has no history and no provenance to take.
    expect(management.ok && 'history' in management.value).toBe(false)
  })

  it('refuses a decision the contract does not name, rather than guessing', async () => {
    new FakeApi()
      .on(
        `GET ${BASE}/guardians/${PERSON}/management`,
        json(
          wireManagement({
            default_role: {
              display_name: 'X',
              decision: 'maybe',
              decided_by: null,
              decided_at: null,
              effective: false,
            },
          }),
        ),
      )
      .install()
    expect(await getRelationshipManagement('guardians', PERSON)).toEqual({
      ok: false,
      failure: { kind: 'unexpected', status: 200 },
    })
  })

  it('refuses a field value that is neither text nor a boolean', async () => {
    new FakeApi()
      .on(`GET ${BASE}/guardians/${PERSON}/management`, json(wireManagement({ fields: { a: 7 } })))
      .install()
    expect((await getRelationshipManagement('guardians', PERSON)).ok).toBe(false)
  })

  it('percent-encodes what it puts in a path', async () => {
    new FakeApi().on(`GET ${BASE}/odd%2Fslug/a%20b/management`, json(wireManagement())).install()
    expect((await getRelationshipManagement('odd/slug', 'a b')).ok).toBe(true)
  })
})

describe('the directory and the lookup', () => {
  it('asks for a page, and sends a search and a status only when there is one', async () => {
    const api = new FakeApi().on(
      `GET ${BASE}/volunteers`,
      json(directoryPage([wireEntry()], { total: 51 })),
    )
    api.install()
    const plain = await listDirectory({
      slug: 'volunteers',
      page: 2,
      perPage: 25,
      query: '',
      status: '',
    })
    expect(api.calls[0]?.path).toBe(`${BASE}/volunteers?page=2&per_page=25`)
    expect(plain.ok && plain.value).toMatchObject({ page: 1, total: 51, lastPage: 3 })
    await listDirectory({
      slug: 'volunteers',
      page: 1,
      perPage: 25,
      query: 'ada l',
      status: 'active',
    })
    expect(api.calls[1]?.path).toBe(`${BASE}/volunteers?page=1&per_page=25&q=ada+l&status=active`)
  })

  it('reads a directory row without basics as null basics', async () => {
    new FakeApi()
      .on(`GET ${BASE}/guardians`, json(directoryPage([wireEntry({ basics: null })])))
      .install()
    const page = await listDirectory({
      slug: 'guardians',
      page: 1,
      perPage: 25,
      query: '',
      status: '',
    })
    expect(page.ok && page.value.entries[0]?.basics).toBeNull()
  })

  it('looks candidates up by the query alone', async () => {
    const api = new FakeApi().on(
      `GET ${BASE}/volunteers/candidates`,
      json(candidateList([candidate(PERSON, 'Ada Lovelace', 'email', 'active')], true)),
    )
    api.install()
    const found = await findCandidates('volunteers', 'ada@example.org')
    expect(api.calls[0]?.path).toBe(`${BASE}/volunteers/candidates?q=ada%40example.org`)
    expect(found.ok && found.value).toEqual({
      truncated: true,
      candidates: [
        {
          person: { id: PERSON, displayName: 'Ada Lovelace' },
          matchedOn: 'email',
          status: 'active',
        },
      ],
    })
  })
})

describe('mutations name the instance, and the revision only where the contract says', () => {
  const ok = json(wireManagement({ revision: 4 }))

  it('a status change sends the instance, the revision and the status, and a role decision only when given', async () => {
    const api = new FakeApi().on(`PUT ${BASE}/guardians/${PERSON}/status`, ok)
    api.install()
    await changeRelationshipStatus('guardians', PERSON, {
      relationshipId: REL_ID,
      revision: 3,
      status: 'inactive',
    })
    expect(api.calls[0]?.body).toEqual({ relationship_id: REL_ID, revision: 3, status: 'inactive' })
    await changeRelationshipStatus('guardians', PERSON, {
      relationshipId: REL_ID,
      revision: 3,
      status: 'active',
      defaultRole: 'defer',
    })
    expect(api.calls[1]?.body).toEqual({
      relationship_id: REL_ID,
      revision: 3,
      status: 'active',
      default_role: 'defer',
    })
  })

  it('a default-role decision sends the instance, the revision and the wire word', async () => {
    const api = new FakeApi().on(`PUT ${BASE}/guardians/${PERSON}/default-role`, ok)
    api.install()
    await decideDefaultRole('guardians', PERSON, {
      relationshipId: REL_ID,
      revision: 3,
      decision: 'decline',
    })
    expect(api.calls[0]?.body).toEqual({
      relationship_id: REL_ID,
      revision: 3,
      default_role: 'decline',
    })
  })

  it('a field update is partial, and null is a clear', async () => {
    const api = new FakeApi().on(`PATCH ${BASE}/guardians/${PERSON}/fields`, ok)
    api.install()
    await updateRelationshipFields('guardians', PERSON, {
      relationshipId: REL_ID,
      revision: 3,
      fields: { stewardship: null, recognized_on: '2026-01-02' },
    })
    expect(api.calls[0]?.body).toEqual({
      relationship_id: REL_ID,
      revision: 3,
      fields: { stewardship: null, recognized_on: '2026-01-02' },
    })
  })

  it('a basic-detail edit sends the instance and {from, to} pairs, no revision, and leaves unchanged details out', async () => {
    const api = new FakeApi().on(`PATCH ${BASE}/volunteers/${PERSON}/basics`, ok)
    api.install()
    await updateRelationshipBasics('volunteers', PERSON, {
      relationshipId: REL_ID,
      changes: {
        primaryEmail: { from: 'Ana@Example.org', to: 'ana@example.org' },
        primaryPhone: { from: null, to: '+1 555 010 0100' },
      },
    })
    const body = api.calls[0]?.body as Record<string, unknown>
    expect(body).toEqual({
      relationship_id: REL_ID,
      primary_email: { from: 'Ana@Example.org', to: 'ana@example.org' },
      primary_phone: { from: null, to: '+1 555 010 0100' },
    })
    expect('revision' in body).toBe(false)
    expect('display_name' in body).toBe(false)
  })

  it('a deletion carries the instance and the revision in the query, and no body', async () => {
    const api = new FakeApi().on(`DELETE ${BASE}/guardians/${PERSON}`, empty())
    api.install()
    const result = await deleteRelationship('guardians', PERSON, {
      relationshipId: REL_ID,
      revision: 3,
    })
    expect(result).toEqual({ ok: true, value: null })
    expect(api.calls[0]?.path).toBe(
      `${BASE}/guardians/${PERSON}?relationship_id=${REL_ID}&revision=3`,
    )
    expect(api.calls[0]?.body).toBeNull()
  })

  it('intake of an existing person sends the confirmation flag and no new person', async () => {
    const api = new FakeApi().on(
      `POST ${BASE}/volunteers`,
      json(wireManagement({ type: 'volunteer' }), 201),
    )
    api.install()
    await establishRelationship('volunteers', {
      subject: { kind: 'existing', personId: PERSON },
      status: 'pending',
      fields: { interests: 'Gardening' },
    })
    expect(api.calls[0]?.body).toEqual({
      person_id: PERSON,
      confirm_existing_person: true,
      status: 'pending',
      fields: { interests: 'Gardening' },
    })
  })

  it('intake of a new person sends the person, and confirm_distinct only when the operator said so', async () => {
    const api = new FakeApi().on(`POST ${BASE}/volunteers`, json(wireManagement(), 201))
    api.install()
    const person = { displayName: 'New Person', email: null, phone: '555' }
    await establishRelationship('volunteers', {
      subject: { kind: 'new', person, confirmDistinct: false },
      status: 'active',
      fields: {},
    })
    await establishRelationship('volunteers', {
      subject: { kind: 'new', person, confirmDistinct: true },
      status: 'active',
      fields: {},
    })
    expect(api.calls[0]?.body).toEqual({
      new_person: { display_name: 'New Person', email: null, phone: '555' },
      status: 'active',
      fields: {},
    })
    expect((api.calls[1]?.body as Record<string, unknown>).confirm_distinct).toBe(true)
    expect('confirm_existing_person' in (api.calls[0]?.body as object)).toBe(false)
    expect('default_role' in (api.calls[0]?.body as object)).toBe(false)
  })

  it('intake says a default role only when the caller states one', async () => {
    const api = new FakeApi().on(`POST ${BASE}/guardians`, json(wireManagement(), 201))
    api.install()
    await establishRelationship('guardians', {
      subject: { kind: 'existing', personId: PERSON },
      status: 'active',
      fields: {},
      defaultRole: 'defer',
    })
    expect((api.calls[0]?.body as Record<string, unknown>).default_role).toBe('defer')
  })
})

describe('what a refusal carries', () => {
  it('a stale revision gives back the current management view, and nothing else does', async () => {
    new FakeApi()
      .on(
        `PUT ${BASE}/guardians/${PERSON}/status`,
        stale(wireManagement({ relationship_id: REL_ID, revision: 9 })),
      )
      .install()
    const result = await changeRelationshipStatus('guardians', PERSON, {
      relationshipId: REL_ID,
      revision: 3,
      status: 'inactive',
    })
    expect(!result.ok && staleRevisionCurrent(result.failure)?.revision).toBe(9)
    expect(!result.ok && staleBasicsCurrent(result.failure)).toBeNull()
  })

  it('a stale revision whose current is malformed gives nothing to trust', () => {
    expect(
      staleRevisionCurrent({ kind: 'conflict', code: 'stale_revision', current: { revision: 1 } }),
    ).toBeNull()
  })

  it('stale basics give back the three current values', () => {
    expect(
      staleBasicsCurrent({
        kind: 'conflict',
        code: 'stale_person_basics',
        current: { display_name: 'Ada', primary_email: null, primary_phone: '1' },
      }),
    ).toEqual({ displayName: 'Ada', primaryEmail: null, primaryPhone: '1' })
    expect(
      staleBasicsCurrent({
        kind: 'conflict',
        code: 'stale_person_basics',
        current: { display_name: 7 },
      }),
    ).toBeNull()
  })

  it('duplicate advice is checked entry by entry, and one bad entry discards the lot', () => {
    const good = { id: 'a', display_name: 'Ada', matched_on: ['email', 'display_name'] }
    expect(
      duplicateAdvice({ kind: 'conflict', code: 'possible_duplicate', candidates: [good] }),
    ).toEqual([{ id: 'a', displayName: 'Ada', matchedOn: ['email', 'display_name'] }])
    expect(
      duplicateAdvice({
        kind: 'conflict',
        code: 'possible_duplicate',
        candidates: [good, { id: 'b' }],
      }),
    ).toBeNull()
    expect(duplicateAdvice({ kind: 'conflict', code: 'relationship_exists' })).toBeNull()
  })

  it('a refusal of the whole request keeps its stable code', async () => {
    new FakeApi()
      .on(`PUT ${BASE}/guardians/${PERSON}/status`, codedInvalid('transition_not_allowed'))
      .install()
    const result = await changeRelationshipStatus('guardians', PERSON, {
      relationshipId: REL_ID,
      revision: 3,
      status: 'nope',
    })
    expect(result).toEqual({
      ok: false,
      failure: { kind: 'invalid', message: 'Refused.', errors: {}, code: 'transition_not_allowed' },
    })
  })
})
