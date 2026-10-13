import { describe, expect, it } from 'vitest'

import type { Candidate } from '../api/relationships.ts'
import { choiceFor, sharedNames } from './candidates.ts'

const make = (
  id: string,
  name: string | null,
  matchedOn: Candidate['matchedOn'] = 'display_name',
  status: string | null = null,
): Candidate => ({ person: { id, displayName: name }, matchedOn, status })

function at(list: Candidate[], index: number): Candidate {
  const found = list[index]
  if (found === undefined) throw new Error(`No candidate ${String(index)}.`)
  return found
}

describe('which candidates may be chosen, P4', () => {
  it('refuses to choose by name when two people share it, and says so for both', () => {
    const list = [make('a', 'Sam Lee'), make('b', 'sam lee '), make('c', 'Priya Rao')]
    const shared = sharedNames(list)
    expect(choiceFor(at(list, 0), shared).kind).toBe('ambiguous')
    expect(choiceFor(at(list, 1), shared).kind).toBe('ambiguous')
    expect(choiceFor(at(list, 2), shared).kind).toBe('choose')
  })

  it('lets a person found by an exact email or phone be chosen even if another shares their name', () => {
    const list = [make('a', 'Sam Lee', 'email'), make('b', 'Sam Lee', 'email')]
    const shared = sharedNames(list)
    expect(choiceFor(at(list, 0), shared).kind).toBe('choose')
    expect(choiceFor(at(list, 1), shared).kind).toBe('choose')
  })

  it('does not count a name match against an email match', () => {
    const list = [make('a', 'Sam Lee', 'display_name'), make('b', 'Sam Lee', 'email')]
    expect(choiceFor(at(list, 0), sharedNames(list)).kind).toBe('choose')
  })

  it('sends someone who already holds the type to their record, never to a second recording', () => {
    const held = make('a', 'Sam Lee', 'email', 'active')
    expect(choiceFor(held, sharedNames([held]))).toEqual({ kind: 'already', status: 'active' })
  })

  it('treats a missing name as one name, so two nameless people cannot be told apart either', () => {
    const list = [make('a', null), make('b', null)]
    expect(choiceFor(at(list, 0), sharedNames(list)).kind).toBe('ambiguous')
  })

  it('refuses to choose a name-only match from a cut-off result, even one whose name looks unique among those shown', () => {
    const list = [make('a', 'Priya Rao'), make('b', 'Sam Lee')]
    const shared = sharedNames(list)
    expect(choiceFor(at(list, 0), shared, false).kind).toBe('choose')
    expect(choiceFor(at(list, 0), shared, true).kind).toBe('ambiguous')
    expect(choiceFor(at(list, 1), shared, true).kind).toBe('ambiguous')
  })

  it('still lets an exact email or phone match be chosen, and a holder be opened, from a cut-off result', () => {
    const byEmail = make('a', 'Priya Rao', 'email')
    const byPhone = make('b', 'Priya Rao', 'phone')
    const held = make('c', 'Sam Lee', 'display_name', 'active')
    expect(choiceFor(byEmail, new Set(), true).kind).toBe('choose')
    expect(choiceFor(byPhone, new Set(), true).kind).toBe('choose')
    expect(choiceFor(held, new Set(), true)).toEqual({ kind: 'already', status: 'active' })
  })
})
