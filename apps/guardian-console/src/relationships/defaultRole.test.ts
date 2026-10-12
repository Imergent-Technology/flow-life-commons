import { describe, expect, it } from 'vitest'

import type { DefaultRoleDecision } from '../api/relationships.ts'
import { choiceToSend, initialChoice, unavailableExplanation } from './defaultRole.ts'

// ADR 0038, K5: the whole table. Every row is checked for what the option STARTS as and for what would be SENT if the
// operator confirmed without touching it, because a preselection must never be the way a role is granted without a decision.

type Stored = DefaultRoleDecision | null

describe('the default-role choice, K5', () => {
  const rows: [string, Stored, boolean, { available: boolean; checked?: boolean }, string][] = [
    [
      'active recognition / no stored decision, may provision',
      null,
      true,
      { available: true, checked: true },
      'grant',
    ],
    [
      'reactivation, stored granted, may provision',
      'granted',
      true,
      { available: true, checked: true },
      'grant',
    ],
    [
      'reactivation, stored declined, may provision',
      'declined',
      true,
      { available: true, checked: false },
      'decline',
    ],
    ['no stored decision, may NOT provision', null, false, { available: false }, 'defer'],
    ['stored granted, may NOT provision', 'granted', false, { available: false }, 'defer'],
    ['stored declined, may NOT provision', 'declined', false, { available: false }, 'defer'],
  ]

  it.each(rows)('%s', (_name, stored, mayProvision, expected, sent) => {
    const option = initialChoice({ mayProvision, stored })
    expect(option.available).toBe(expected.available)
    if (option.available) expect(option.checked).toBe(expected.checked)
    // Confirming without touching the option sends exactly what it started as.
    expect(choiceToSend(option, option.available && option.checked)).toBe(sent)
  })

  it('never sends grant for an unavailable option, whatever the checkbox state', () => {
    const option = initialChoice({ mayProvision: false, stored: 'granted' })
    expect(choiceToSend(option, true)).toBe('defer')
    expect(choiceToSend(option, false)).toBe('defer')
  })

  it('sends decline for an available option the operator unchecked, and grant when they checked it', () => {
    const option = initialChoice({ mayProvision: true, stored: 'declined' })
    expect(choiceToSend(option, false)).toBe('decline')
    expect(choiceToSend(option, true)).toBe('grant')
  })

  it('names the role it is given in the explanation, and no other', () => {
    expect(unavailableExplanation('Apprentice Pass')).toBe(
      'Granting Apprentice Pass access needs permission to assign roles. Someone who has it can grant it from this record later.',
    )
  })
})
