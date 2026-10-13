import { describe, expect, it } from 'vitest'

import type { RelationshipField } from '../api/relationships.ts'
import { draftFrom, isEditableField, latestAllowedDate, prepareChanges } from './fieldEditing.ts'

const field = (overrides: Partial<RelationshipField>): RelationshipField => ({
  key: 'k',
  valueType: 'text',
  maxLength: 20,
  notAfter: null,
  required: false,
  visibility: 'view',
  label: 'K',
  help: null,
  order: 1,
  group: null,
  options: [],
  ...overrides,
})

const text = field({ key: 'mentor' })
const required = field({
  key: 'track',
  valueType: 'choice',
  required: true,
  options: ['wood', 'metal'],
})
const flag = field({ key: 'own_tools', valueType: 'boolean' })
const unknown = field({ key: 'signature', valueType: 'handwriting' })
const all = [text, required, flag, unknown]

describe('a partial field update', () => {
  it('sends nothing for a form left alone, so an omitted field is never touched', () => {
    const original = { mentor: 'Ola', track: 'wood', own_tools: true }
    const { changes, problems } = prepareChanges(all, original, draftFrom(all, original))
    expect(changes).toEqual({})
    expect(problems).toEqual({})
  })

  it('sends only the field that changed', () => {
    const original = { mentor: 'Ola', track: 'wood' }
    const draft = { ...draftFrom(all, original), mentor: 'Ravi' }
    expect(prepareChanges(all, original, draft).changes).toEqual({ mentor: 'Ravi' })
  })

  it('sends null for a value emptied on purpose, and nothing for one that was never set', () => {
    const original = { mentor: 'Ola', track: 'wood' }
    const draft = { ...draftFrom(all, original), mentor: '   ' }
    expect(prepareChanges(all, original, draft).changes).toEqual({ mentor: null })
    const never = { track: 'wood' }
    expect(prepareChanges(all, never, draftFrom(all, never)).changes).toEqual({})
  })

  it('says a required field cannot be emptied, and does not send it', () => {
    const original = { track: 'wood' }
    const draft = { ...draftFrom(all, original), track: '' }
    const result = prepareChanges(all, original, draft)
    expect(result.problems).toEqual({ track: 'This field is required.' })
    expect(result.changes).toEqual({})
  })

  it('treats a boolean as changed only when it flips, true to false included', () => {
    expect(
      prepareChanges(
        all,
        { track: 'wood' },
        { ...draftFrom(all, { track: 'wood' }), own_tools: true },
      ).changes,
    ).toEqual({ own_tools: true })
    expect(
      prepareChanges(
        all,
        { track: 'wood', own_tools: true },
        { ...draftFrom(all, { track: 'wood', own_tools: true }), own_tools: false },
      ).changes,
    ).toEqual({ own_tools: false })
    expect(
      prepareChanges(all, { track: 'wood' }, draftFrom(all, { track: 'wood' })).changes,
    ).toEqual({})
  })

  it('flags a value over the limit and a choice outside the options, advisorily', () => {
    const original = { track: 'wood' }
    const base = draftFrom(all, original)
    expect(prepareChanges(all, original, { ...base, mentor: 'x'.repeat(21) }).problems.mentor).toBe(
      'Use at most 20 characters.',
    )
    expect(prepareChanges(all, original, { ...base, track: 'glass' }).problems.track).toBe(
      'Choose one of the options.',
    )
  })

  it('never lets a field type the Console has no control for into the draft or the update', () => {
    expect(isEditableField(unknown)).toBe(false)
    const original = { signature: 'old', track: 'wood' }
    const draft = draftFrom(all, original)
    expect('signature' in draft).toBe(false)
    expect(prepareChanges(all, original, { ...draft, signature: 'forged' }).changes).toEqual({})
  })
})

describe('the date bound, advisory', () => {
  it('is today at UTC+14 for a "not after today" date, and absent otherwise', () => {
    const date = field({ valueType: 'date', notAfter: 'today' })
    expect(latestAllowedDate(date, new Date('2026-10-12T12:00:00Z'))).toBe('2026-10-13')
    expect(latestAllowedDate(field({ valueType: 'date' }), new Date())).toBeUndefined()
    expect(
      latestAllowedDate(field({ valueType: 'text', notAfter: 'today' }), new Date()),
    ).toBeUndefined()
  })
})
